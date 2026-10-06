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

- `ON_ORDER_COMPLETED` is the only event that books money (it carries the order id and amounts).
  - Checkout invoice still unpaid: it is paid.
  - Checkout invoice already paid, order belongs to a subscription linked to a WHMCS service: renewal. The pending
    renewal invoice of that service is paid, or one is created (with its line item linked to the service).
  - The invoice lookup must succeed. A failed lookup returns HTTP 500 so PayNow retries; it is never treated as a renewal.
- `ON_SUBSCRIPTION_ACTIVATED` / `ON_SUBSCRIPTION_RENEWED` only link the subscription to the service, after checking its
  real status through the PayNow API (a late event for a cancelled subscription is ignored). They never book money.
- `ON_SUBSCRIPTION_CANCELED` clears the subscription id so the next invoice can be paid normally.
- An invoice for a service with a PayNow subscription shows "renewed automatically" instead of a pay button.
- The PayNow order id is the WHMCS transaction id and a single global database lock serialises webhooks. If the lock
  cannot be obtained the webhook fails (HTTP 500) and PayNow retries.
- Renewal invoices carry the PayNow order id in their notes, so a retry reuses the invoice instead of creating another.

### What is booked automatically, and what is not
Anything that cannot be booked safely is **not guessed**. It is written to the WHMCS Activity Log (prefix `PayNow.gg:`)
and the Gateway Log for manual review:

| Situation | Behaviour |
| --- | --- |
| Amount lower than the balance | Booked as a partial payment |
| Amount higher than the balance | Only the balance is booked; refund the difference in PayNow |
| Amount 0 with a coupon / gift card | Invoice settled (free order, used by the onboarding test) |
| Amount 0 without discount, or negative | Not booked |
| PayNow currency different from the client's WHMCS currency | Not booked |
| Order for an invoice that is already paid, with no linked subscription | Not booked (possible double charge) |
| Second subscription for a service that already has a live one | New subscription cancelled through the API, admin alerted |
| `ON_REFUND` / `ON_CHARGEBACK` | Only reported, never reversed automatically |

### Known limitations
- Two checkouts opened for the same invoice before the first webhook arrives can still both be paid. The duplicate is
  detected and cancelled when its webhook arrives, but the customer is charged and must be refunded in PayNow.
- A renewal is recognised by "paid invoice + subscription linked to a service". PayNow does not give a billing period
  in the order, so an unexpected extra order on a linked subscription is booked as a renewal.
- `ON_REFUND` / `ON_CHARGEBACK` do not update WHMCS.
- Behaviour was validated with simulated WHMCS/PayNow responses, not on a live WHMCS install. Test one subscription end
  to end with Debug Logging enabled before going to production.

Upgrading from 1.0.4
--------------------
Fixes made in 1.0.5 do not repair data created by older versions. Check manually for:
- blank or duplicate renewal invoices created by the old renewal handler,
- account credit created by payments applied to invoices that were already paid,
- duplicate PayNow subscriptions on the same service (cancel the extra ones in the PayNow dashboard).

Also add `ON_SUBSCRIPTION_CANCELED` to your PayNow webhook subscriptions. The webhook now calls the PayNow API
(subscription status / cancel), so the API key must be allowed to read and cancel subscriptions.

Changelog
---------
1.0.5 - Subscription and payment handling rewritten:
- renewals are booked from `ON_ORDER_COMPLETED` (the `ON_SUBSCRIPTION_RENEWED` payload has no amount or order id);
- no more guessed amounts: zero/negative amounts and currency mismatches are reported instead of booked;
- over-payments are capped to the balance and reported;
- global webhook lock that fails closed, idempotent retries, renewal invoices reused through an order marker;
- subscription status checked through the API, duplicate subscriptions cancelled, late events ignored;
- subscription `ON_SUBSCRIPTION_CANCELED` handling and an "automatic renewal" message instead of a pay button.
1.0.4 - Previous release.

1.0.1 - Added Callback Test and updated README.

1.0.0 - Initial commit
