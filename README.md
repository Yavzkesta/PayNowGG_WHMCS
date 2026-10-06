PayNow.gg WHMCS Gateway
=======================

Install
-------
1. Upload `paynowgg.php` to:
   
   `/modules/gateways/paynowgg.php`
   
2. Upload `callback/paynowgg.php` and `callback/paynowgg_return.php` to:
   
   `/modules/gateways/callback/paynowgg.php` and `/modules/gateways/callback/paynowgg_return.php`
   
3. In WHMCS Admin, activate the PayNow.gg gateway.
   
4. Configure:
   - Store ID
   - API Key token
   - Webhook Signing Secret
     
5. In PayNow, create a JSON webhook endpoint pointing to:
   
   `https://YOUR-WHMCS-DOMAIN/modules/gateways/callback/paynowgg.php`
   
6. Subscribe to `ON_ORDER_COMPLETED`. If you use subscriptions, also subscribe to `ON_SUBSCRIPTION_ACTIVATED`, `ON_SUBSCRIPTION_RENEWED` and `ON_SUBSCRIPTION_CANCELED`. `ON_REFUND` and `ON_CHARGEBACK` are only logged.

<img width="1485" height="637" alt="image" src="https://github.com/user-attachments/assets/41cc8694-1763-40db-9326-97795ab0318b" />

## Completing the PayNow.GG Onboarding

1. Upload `paynow_test_callback.php` to:

   `/modules/gateways/callback/`

2. In your PayNow dashboard, create a webhook and set the endpoint URL to:

   `https://YOUR-WHMCS-DOMAIN/modules/gateways/callback/paynow_test_callback.php`

3. Generate a test transaction by either:
   - Applying a **100% discount coupon** to an invoice, or
   - Creating a **$0.01 invoice** and completing the checkout process.

4. Once the test payment is processed, the callback script should receive the webhook request and verify that your webhook configuration is working correctly.

If the test completes successfully, webhook/callback functionality has been verified. Note: The only way to create an order on a headless store is to do it through WHMCS. You must invoice yourself from WHMCS then you can create a coupon code from PayNow to complete the order.

Notes
-----
- The module creates a PayNow customer and checkout from the WHMCS invoice.
- Checkout metadata includes whmcs_invoice_id so the signed webhook can mark the invoice paid.
- Subscription support is experimental and only requested when the WHMCS invoice has a single recurring Hosting or Addon line and Allow Subscriptions is enabled.

Subscriptions: how it works
---------------------------
PayNow charges subscription renewals by itself. WHMCS only has to record them, without double billing.

- `ON_ORDER_COMPLETED` is the single source of truth for money (it carries the order id and amounts).
  - First order of a checkout: pays the WHMCS invoice from the `whmcs_invoice_id` metadata, if it is still unpaid.
  - Renewal order: finds the WHMCS service through the PayNow subscription id, pays its pending renewal invoice,
    or creates one (with its line item, linked to the service) when WHMCS has not generated it yet.
- `ON_SUBSCRIPTION_ACTIVATED` / `ON_SUBSCRIPTION_RENEWED` only link the PayNow subscription id to the service. They never record a payment.
- `ON_SUBSCRIPTION_CANCELED` clears the subscription id so the next invoice can be paid normally.
- An invoice for a service that already has an active PayNow subscription shows "renewed automatically" instead of a pay button, so customers cannot pay twice.
- The PayNow order id is the WHMCS transaction id. A transaction that already exists is never recorded again, and a lock serialises concurrent webhooks.
- Recorded payments are capped at the invoice balance, so no credit is added to the client account. On a currency mismatch the invoice balance is used.
- On processing errors the webhook returns HTTP 500 so PayNow retries; handling is idempotent.

Upgrading from 1.0.4
--------------------
Fixes made in 1.0.5 do not repair data created by older versions. Check manually for:
- blank or duplicate renewal invoices created by the old renewal handler,
- account credit created by payments applied to invoices that were already paid,
- duplicate PayNow subscriptions on the same service (cancel the extra ones in the PayNow dashboard).

Also add `ON_SUBSCRIPTION_CANCELED` to your PayNow webhook subscriptions.

Changelog
---------
1.0.5 - Fixed duplicate payments, account credit, blank/duplicate renewal invoices and duplicate subscriptions.
Renewals are now recorded from `ON_ORDER_COMPLETED` (the `ON_SUBSCRIPTION_RENEWED` payload has no amount or order id).
Added `ON_SUBSCRIPTION_CANCELED` handling, idempotency and locking, payment capped to invoice balance,
and an "automatic renewal" message instead of a pay button for services with an active subscription.

1.0.4 - Previous release.

1.0.1 - Added Callback Test and updated README.

1.0.0 - Initial commit
