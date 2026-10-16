<?php
require __DIR__ . '/config.php';

function finish(string $title, string $message): never {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title><body style="font:16px Arial;max-width:800px;margin:40px auto">';
    echo '<h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p><p><a href="index.php">Store</a> | <a href="cart.php">Cart</a> | <a href="orders.php">Orders</a></p></body></html>';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: cart.php'); exit; }

$customer = trim((string)($_POST['customer_name'] ?? ''));
$method = (string)($_POST['payment_method'] ?? 'CARD');
if ($customer === '') finish('Missing information', 'Enter a customer name.');
if (!in_array($method, ['CARD','EWALLET','BANK'], true)) $method = 'CARD';

$cart = $_SESSION['cart'] ?? [];
if (!$cart) finish('Empty cart', 'Add an item before checkout.');

$pdo = cart_db();
$ids = array_map('intval', array_keys($cart));
$marks = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id,name,price,stock FROM products WHERE id IN ($marks)");
$stmt->execute($ids);
$products = $stmt->fetchAll();
$total = 0.0; $validItems = [];
foreach ($products as $p) {
    $qty = min((int)$cart[$p['id']], (int)$p['stock']);
    if ($qty > 0) {
        $total += $qty * (float)$p['price'];
        $validItems[] = ['product'=>$p,'qty'=>$qty];
    }
}
if (!$validItems || $total <= 0) finish('Checkout error', 'No valid items were found.');

/* Persist order before calling the independent payment service. */
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("INSERT INTO orders (customer_name,total_amount,status) VALUES (?,?,'PENDING_PAYMENT')");
    $stmt->execute([$customer, number_format($total,2,'.','')]);
    $orderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,quantity,unit_price) VALUES (?,?,?,?,?)');
    foreach ($validItems as $i) {
        $itemStmt->execute([$orderId,$i['product']['id'],$i['product']['name'],$i['qty'],$i['product']['price']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    finish('Order error', 'Could not save the order.');
}

/*
 * Integration with the independent payment gateway.
 * The idempotency key is stable for this saved order, so a retry
 * of the same order reuses the same key.
 */
$apiUrl = 'http://localhost/payment-gateway/api/payments.php';
$idempotencyKey = 'cart-order-' . $orderId;

$payload = [
    'order_id' => $orderId,
    'amount' => round($total, 2),
    'customer_name' => $customer,
    'payment_method' => $method,
    'idempotency_key' => $idempotencyKey
];

/*
 * The gateway's classroom API defaults to SUCCESS when "outcome"
 * is omitted. For instructor testing, the server-side simulator can
 * temporarily be set to FAILED or PENDING; never take an outcome
 * supplied by the customer as proof of payment.
 */
$jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
if ($jsonPayload === false) {
    $stmt = $pdo->prepare("UPDATE orders SET status='PAYMENT_PENDING' WHERE id=?");
    $stmt->execute([$orderId]);
    finish('Payment status not confirmed', 'The payment request could not be encoded. Check order history before attempting payment again.');
}

$ch = curl_init();
if ($ch === false) {
    $stmt = $pdo->prepare("UPDATE orders SET status='PAYMENT_PENDING' WHERE id=?");
    $stmt->execute([$orderId]);
    finish('Payment status not confirmed', 'The payment service could not be contacted. Check order history before attempting payment again.');
}

curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $jsonPayload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Idempotency-Key: ' . $idempotencyKey
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15
]);

$responseBody = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($responseBody === false || $curlError !== '') {
    // A timeout/network error does not prove that the gateway failed.
    $stmt = $pdo->prepare("UPDATE orders SET status='PAYMENT_PENDING' WHERE id=?");
    $stmt->execute([$orderId]);
    finish('Payment status not confirmed', 'The gateway did not return a reliable response. Your order is still pending. Check order history before attempting payment again.');
}

$gatewayData = json_decode($responseBody, true);
if ($httpCode !== 200 || !is_array($gatewayData)) {
    $stmt = $pdo->prepare("UPDATE orders SET status='PAYMENT_PENDING' WHERE id=?");
    $stmt->execute([$orderId]);
    finish('Payment status not confirmed', 'The gateway returned an HTTP error or invalid JSON. Your order remains pending. Check order history before attempting payment again.');
}

/* Validate the response and ensure it belongs to this exact order and amount. */
$transactionId = trim((string)($gatewayData['transaction_id'] ?? ''));
$responseOrderId = filter_var($gatewayData['order_id'] ?? null, FILTER_VALIDATE_INT);
$responseAmount = filter_var($gatewayData['amount'] ?? null, FILTER_VALIDATE_FLOAT);
$status = strtoupper(trim((string)($gatewayData['status'] ?? '')));
$responseSuccess = ($gatewayData['success'] ?? false) === true;

$validResponse = $responseSuccess
    && $transactionId !== ''
    && $responseOrderId === $orderId
    && $responseAmount !== false
    && abs((float)$responseAmount - $total) < 0.01
    && in_array($status, ['SUCCESS','FAILED','PENDING'], true);

if (!$validResponse) {
    // Save a transaction ID only when one was returned, but do not trust
    // the response enough to mark the order paid or definitively failed.
    if ($transactionId !== '') {
        $stmt = $pdo->prepare("UPDATE orders SET status='PAYMENT_PENDING', gateway_transaction_id=? WHERE id=?");
        $stmt->execute([$transactionId, $orderId]);
    } else {
        $stmt = $pdo->prepare("UPDATE orders SET status='PAYMENT_PENDING' WHERE id=?");
        $stmt->execute([$orderId]);
    }
    finish('Payment status not confirmed', 'The gateway response was incomplete or did not match this order. The order remains pending; verify its status before retrying.');
}

$statusMap = [
    'SUCCESS' => 'PAID',
    'FAILED' => 'PAYMENT_FAILED',
    'PENDING' => 'PAYMENT_PENDING'
];
$orderStatus = $statusMap[$status];

/* Update only the order created in this checkout using a prepared statement. */
$stmt = $pdo->prepare('UPDATE orders SET status=?, gateway_transaction_id=? WHERE id=?');
$stmt->execute([$orderStatus, $transactionId, $orderId]);

if ($status === 'SUCCESS') {
    unset($_SESSION['cart']);
    finish('Payment successful', 'Payment was confirmed by the gateway. Order #' . $orderId . ' is marked PAID. Transaction: ' . $transactionId);
}
if ($status === 'FAILED') {
    finish('Payment failed', 'The gateway confirmed that payment failed for order #' . $orderId . '. Transaction: ' . $transactionId);
}
finish('Payment pending', 'Payment for order #' . $orderId . ' is awaiting confirmation. Transaction: ' . $transactionId);
