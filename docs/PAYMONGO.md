# PayMongo setup for KITA

KITA already connects POS → E-Wallet → GCash, Maya, or GrabPay to PayMongo Hosted Checkout. It computes prices on the server, reserves stock, and confirms payment through a signed webhook plus a provider API lookup. Secret keys stay on the server. A public key is not needed for this hosted flow.

## Configure test mode

1. In your PayMongo dashboard, get the test secret API key and enable the wallet methods you want to test.
2. Make the Laravel application reachable at a public HTTPS URL. For local development, use an HTTPS tunnel to your running Laravel server and open KITA through that URL so the login session survives the return from checkout.
3. Set these values in your uncommitted `.env` (or deployment environment):

   ```dotenv
   APP_URL=https://your-public-kita-host
   PAYMONGO_SECRET_KEY=sk_test_your_key
   PAYMONGO_WEBHOOK_SECRET=your_endpoint_signing_secret
   ```

4. In PayMongo's webhook settings, create a test-mode endpoint at `https://your-public-kita-host/api/payments/paymongo/webhook`. Subscribe to `checkout_session.payment.paid`. Copy that endpoint's signing secret into `PAYMONGO_WEBHOOK_SECRET`; this is different from the API secret key.
5. Run `php artisan config:clear`, then `php artisan paymongo:check`. On deployments that cache configuration, run `php artisan config:cache` after setting the environment. The check does not contact PayMongo or validate the keys remotely.

Never commit credentials or put secret keys in browser JavaScript. Update the webhook and APP_URL whenever a temporary tunnel URL changes. Behind a reverse proxy, make sure the app recognizes the original HTTPS scheme; checkout return URLs are generated from the incoming request.

## Verify the complete flow

For scan-to-pay, choose **E-Wallet > QR Ph** in cashier or manager checkout.
PayMongo's hosted page displays the payment QR; KITA does not generate a personal-wallet transfer QR.
The requested PHP amount is calculated from stored product prices, quantities, and approved discounts on the server, even if the browser submits a different total.
QR Ph must be enabled for the PayMongo account. Test keys simulate payment; real scanning requires live credentials and the matching live webhook signing secret.
Keep the current mode until a test checkout, signed payment confirmation, and duplicate-event handling have been verified.
Customer GCash authentication stays on the provider's checkout. No manually entered phone or reference number marks a sale paid.

1. Sign in as a cashier or manager, add an available product to the POS cart, choose E-Wallet and a supported provider, and start payment.
2. Complete the provider's test checkout. The return page refreshes pending status for about two minutes; use Refresh status if delivery takes longer. The transaction must become Paid only after verified webhook delivery. Visiting the success URL alone cannot mark it paid.
3. Check that stock was deducted once and the sale appears once. Re-delivering the same webhook must not duplicate the sale.
4. Start another checkout, return via cancel, and select Cancel payment and release stock. Stock is released only after the remote session is confirmed expired and unpaid. Merely closing the checkout does not release stock.
5. Run automated coverage with `php artisan test --filter=PayMongo` and `php artisan test --filter=CheckoutStockTest`. These use fake provider responses and do not charge money.

## Operations and troubleshooting

- A 503 configuration error means the secret key or webhook secret is missing; check cached configuration too.
- A rejected checkout releases its stock reservation. Confirm the payment method is enabled and the amount satisfies your provider's requirements.
- A timeout or uncertain provider response keeps stock reserved because a remote payment may still exist. Keep the transaction UUID and reconcile it in PayMongo before retrying. There is no automatic expiry/reconciliation job for abandoned reservations.
- A pending payment after successful checkout usually needs webhook delivery inspection in PayMongo: check the public URL, signing secret, test/live mode, and server clock. Signed requests older than five minutes are rejected.
- Wallet refunds are initiated in PayMongo. Enter the successful refund ID in KITA's refund workflow; KITA verifies the payment, amount, currency, and mode before recording it.

The existing integration uses `/v1/checkout_sessions` for creation, retrieval, and expiry. PayMongo recommends V2 for new integrations; this setup preserves the project's existing V1 flow. Test any future API migration across all three operations before switching.

## Go live

After an end-to-end test, configure the live secret key and a live webhook endpoint/signing secret, enable the required payment methods in your approved PayMongo account, and rebuild cached configuration. Use a stable public HTTPS host. Test and live keys and webhook secrets must not be mixed.

Official references: [Hosted Checkout](https://docs.paymongo.com/docs/payment-channels-hosted-checkout), [Quick start](https://docs.paymongo.com/docs/payment-channels-hosted-checkout-quick-start), and [Webhook setup and signature verification](https://docs.paymongo.com/docs/developer-tools-webhook-setup-management).
