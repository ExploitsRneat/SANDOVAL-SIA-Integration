# SIA Integration Lab — Completed Student Copy

## Install
1. Copy `shopping-cart` and `payment-gateway` into `C:\xampp\htdocs\`.
2. In phpMyAdmin, import `sql/shopping_cart_db.sql`, then `sql/payment_gateway_db.sql`.
3. Confirm Apache and MySQL are running and PHP cURL is enabled.
4. Open `http://localhost/shopping-cart/` and `http://localhost/payment-gateway/`.

The two applications use separate databases. The shopping cart calls the gateway only through HTTP/JSON.

## Test outcomes
The gateway defaults to SUCCESS when the `outcome` field is omitted. For classroom simulation, follow your instructor's instructions to temporarily set the server-side request outcome to `FAILED` or `PENDING`, then restore normal behavior. Never treat a customer-supplied outcome as payment confirmation.

This is a local mock payment gateway, not a real payment service. Do not enter real payment credentials.
