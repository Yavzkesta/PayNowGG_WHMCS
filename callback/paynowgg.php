<?php
/**
 * PayNow.gg callback/webhook endpoint for WHMCS.
 *
 * Money flow (ON_ORDER_COMPLETED carries the order id and amounts and is the only event that books money):
 *  - the checkout invoice is still unpaid      -> pay it
 *  - the checkout invoice is already paid and the order belongs to a linked subscription
 *    -> renewal: pay the pending renewal invoice of the service (or create one linked to the service)
 * Subscription events only link / unlink the PayNow subscription id on the WHMCS service, after checking
 * the real subscription status through the PayNow API.
 *
 * Anything that cannot be booked safely (currency mismatch, negative amount, duplicate payment, ...) is NOT
 * guessed: it is written to the WHMCS Activity Log for manual review.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
if (!function_exists('paynowgg_apiRequest')) {
    require_once __DIR__ . '/../paynowgg.php';
}

$gatewayModuleName = basename(__FILE__, '.php');
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    http_response_code(400);
    die('Module Not Activated');
}

$rawPayload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_PAYNOW_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_PAYNOW_TIMESTAMP'] ?? '';
$secret = (string) ($gatewayParams['webhookSigningSecret'] ?? '');
$debugMode = !empty($gatewayParams['debugMode']);

if ($debugMode) {
    logModuleCall('PayNow.gg', 'incoming webhook', array(
        'signature' => $signature,
        'timestamp' => $timestamp,
        'payload' => $rawPayload,
    ), null, null, array($secret));
}

if (!paynowgg_callback_validateSignature($rawPayload, $timestamp, $signature, $secret)) {
    logTransaction($gatewayParams['name'], $rawPayload, 'PayNow Hash Verification Failure');
    http_response_code(401);
    echo 'Invalid PayNow signature';
    exit;
}

$event = json_decode($rawPayload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo 'Invalid JSON';
    exit;
}

$eventType = (string) ($event['event_type'] ?? '');
$body = is_array($event['body'] ?? null) ? $event['body'] : array();
logTransaction($gatewayParams['name'], $rawPayload, $eventType ?: 'PayNow Webhook');

try {
    switch ($eventType) {
        case 'ON_ORDER_COMPLETED':
            paynowgg_callback_lock();
            paynowgg_callback_handleOrderCompleted($gatewayModuleName, $gatewayParams, $body);
            http_response_code(200);
            echo 'OK';
            break;

        case 'ON_SUBSCRIPTION_ACTIVATED':
        case 'ON_SUBSCRIPTION_RENEWED':
            paynowgg_callback_lock();
            paynowgg_callback_linkSubscription($gatewayParams, $body);
            http_response_code(200);
            echo 'OK';
            break;

        case 'ON_SUBSCRIPTION_CANCELED':
            paynowgg_callback_lock();
            paynowgg_callback_unlinkSubscription($body);
            http_response_code(200);
            echo 'OK';
            break;

        case 'ON_REFUND':
        case 'ON_CHARGEBACK':
            // Not reversed automatically: a human must decide. Flag it in the Activity Log.
            paynowgg_callback_report('Received ' . $eventType . ' - review the matching WHMCS transaction manually.', array(
                'event_type' => $eventType,
                'event_id' => $event['event_id'] ?? '',
                'order_id' => $body['order_id'] ?? ($body['id'] ?? ''),
            ));
            http_response_code(200);
            echo 'Logged';
            break;

        default:
            // Return 200 for unhandled but valid signed events so PayNow does not retry indefinitely.
            http_response_code(200);
            echo 'Ignored';
            break;
    }
} catch (Throwable $e) {
    // 500 makes PayNow retry. Every handler is idempotent, so retries are safe.
    logModuleCall('PayNow.gg', 'webhook processing error', $event, array('error' => $e->getMessage()), array('error' => $e->getMessage()), array($secret));
    http_response_code(500);
    echo 'Webhook processing failed';
}

function paynowgg_callback_validateSignature($rawPayload, $timestamp, $providedSignature, $secret)
{
    if ($rawPayload === '' || $timestamp === '' || $providedSignature === '' || $secret === '') {
        return false;
    }

    if (!ctype_digit((string) $timestamp)) {
        return false;
    }

    // PayNow timestamps are Unix milliseconds. Reject payloads older/newer than 5 minutes.
    $nowMs = (int) round(microtime(true) * 1000);
    $timestampMs = (int) $timestamp;
    if (abs($nowMs - $timestampMs) > 5 * 60 * 1000) {
        return false;
    }

    $expected = base64_encode(hash_hmac('sha256', $timestamp . '.' . $rawPayload, $secret, true));

    return hash_equals($expected, $providedSignature);
}

/**
 * One global lock serialises every state-changing webhook (low volume, and it avoids relying on several
 * named locks, which older MySQL releases silently drop). Failing to get it is an error: PayNow retries.
 */
function paynowgg_callback_lock()
{
    $row = \WHMCS\Database\Capsule::connection()->selectOne('SELECT GET_LOCK(?, 25) AS l', array('paynowgg_webhook'));
    $acquired = $row ? (int) ((array) $row)['l'] : 0;

    if ($acquired !== 1) {
        throw new Exception('Could not acquire the PayNow webhook lock.');
    }
}

/**
 * Writes to the module log and to the WHMCS Activity Log so an administrator sees what needs attention.
 */
function paynowgg_callback_report($message, array $context = array())
{
    logModuleCall('PayNow.gg', 'attention required', $context, array('message' => $message));

    if (function_exists('logActivity')) {
        logActivity('PayNow.gg: ' . $message . ' ' . json_encode($context));
    }
}

function paynowgg_callback_transactionExists($transactionId)
{
    return \WHMCS\Database\Capsule::table('tblaccounts')
        ->where('gateway', '=', 'paynowgg')
        ->where('transid', '=', (string) $transactionId)
        ->exists();
}

function paynowgg_callback_firstMetadata(array $body)
{
    $candidates = array(
        $body['checkout']['metadata'] ?? null,
        $body['order']['checkout']['lines'][0]['metadata'] ?? null,
        $body['lines'][0]['metadata'] ?? null,
    );

    foreach ((array) ($body['checkout']['lines'] ?? array()) as $line) {
        if (is_array($line)) {
            $candidates[] = $line['metadata'] ?? null;
        }
    }

    $merged = array();
    foreach ($candidates as $meta) {
        if (is_array($meta)) {
            $merged = $merged + $meta;
        }
    }

    return $merged;
}

function paynowgg_callback_findSubscriptionIdInOrder(array $body)
{
    foreach ((array) ($body['lines'] ?? array()) as $line) {
        if (is_array($line) && !empty($line['subscription_id'])) {
            return (string) $line['subscription_id'];
        }
    }

    return (string) ($body['subscription_id'] ?? '');
}

function paynowgg_callback_normalizeType($type)
{
    $type = strtolower((string) $type);
    return in_array($type, array('hosting', 'addon'), true) ? $type : '';
}

function paynowgg_callback_table($type)
{
    return $type === 'addon' ? 'tblhostingaddons' : 'tblhosting';
}

/**
 * Finds the WHMCS service tied to a PayNow subscription id.
 * Returns array('type' => 'hosting|addon', 'relid' => int, 'userid' => int) or null.
 */
function paynowgg_callback_findServiceBySubscription($subscriptionId)
{
    if ($subscriptionId === '') {
        return null;
    }

    foreach (array('hosting', 'addon') as $type) {
        $row = \WHMCS\Database\Capsule::table(paynowgg_callback_table($type))
            ->where('subscriptionid', '=', $subscriptionId)
            ->first();
        if ($row) {
            return array('type' => $type, 'relid' => (int) $row->id, 'userid' => (int) $row->userid);
        }
    }

    return null;
}

function paynowgg_callback_api(array $gatewayParams, $method, $path, array $payload = null)
{
    $apiBaseUrl = rtrim(trim((string) ($gatewayParams['apiBaseUrl'] ?: 'https://api.paynow.gg')), '/');

    return paynowgg_apiRequest(
        $apiBaseUrl,
        trim((string) $gatewayParams['storeId']),
        trim((string) $gatewayParams['apiKey']),
        $method,
        $path,
        $payload,
        !empty($gatewayParams['debugMode'])
    );
}

/**
 * Real subscription status from PayNow (created, active, past_due, canceled). Throws on API failure so the
 * webhook is retried instead of acting on a guess.
 */
function paynowgg_callback_subscriptionStatus(array $gatewayParams, $subscriptionId)
{
    $subscription = paynowgg_callback_api($gatewayParams, 'GET', '/subscriptions/' . rawurlencode($subscriptionId));

    return strtolower((string) ($subscription['status'] ?? ''));
}

/**
 * Links a PayNow subscription to a WHMCS service.
 * Returns: 'linked' | 'canceled' (stale event, nothing done) | 'duplicate' (service already has another live
 * subscription: the new one is cancelled) | 'missing' (service not found).
 */
function paynowgg_callback_attachSubscription(array $gatewayParams, $type, $relid, $subscriptionId)
{
    $table = paynowgg_callback_table($type);
    $row = \WHMCS\Database\Capsule::table($table)->where('id', '=', (int) $relid)->first();

    if (!$row) {
        return 'missing';
    }

    $existing = (string) ($row->subscriptionid ?? '');
    if ($existing === $subscriptionId) {
        return 'linked';
    }

    if (paynowgg_callback_subscriptionStatus($gatewayParams, $subscriptionId) === 'canceled') {
        return 'canceled';
    }

    if ($existing !== '' && paynowgg_callback_subscriptionStatus($gatewayParams, $existing) !== 'canceled') {
        paynowgg_callback_report('Duplicate PayNow subscription detected, the new one is being cancelled. If the customer was charged twice, refund the extra order in PayNow.', array(
            'service_type' => $type,
            'service_id' => (int) $relid,
            'kept_subscription' => $existing,
            'duplicate_subscription' => $subscriptionId,
        ));

        try {
            paynowgg_callback_api($gatewayParams, 'POST', '/subscriptions/' . rawurlencode($subscriptionId) . '/cancel', array());
        } catch (Throwable $e) {
            paynowgg_callback_report('Could not cancel the duplicate PayNow subscription automatically - cancel it in the PayNow dashboard.', array(
                'duplicate_subscription' => $subscriptionId,
                'error' => $e->getMessage(),
            ));
        }

        return 'duplicate';
    }

    \WHMCS\Database\Capsule::table($table)->where('id', '=', (int) $relid)->update(array('subscriptionid' => $subscriptionId));

    return 'linked';
}

function paynowgg_callback_linkSubscription(array $gatewayParams, array $body)
{
    $subscriptionId = (string) ($body['id'] ?? '');
    $metadata = paynowgg_callback_firstMetadata($body);
    $relid = (int) ($metadata['whmcs_subscription_relid'] ?? 0);
    $type = paynowgg_callback_normalizeType($metadata['whmcs_subscription_type'] ?? '');

    if ($subscriptionId === '' || $relid <= 0 || $type === '') {
        return;
    }

    paynowgg_callback_attachSubscription($gatewayParams, $type, $relid, $subscriptionId);
}

function paynowgg_callback_unlinkSubscription(array $body)
{
    $subscriptionId = (string) ($body['id'] ?? '');
    if ($subscriptionId === '') {
        return;
    }

    // Only the service that points at this exact subscription is cleared, so the customer can pay the next
    // invoice (or start a new subscription) normally.
    foreach (array('tblhosting', 'tblhostingaddons') as $table) {
        \WHMCS\Database\Capsule::table($table)
            ->where('subscriptionid', '=', $subscriptionId)
            ->update(array('subscriptionid' => ''));
    }

    logModuleCall('PayNow.gg', 'subscription canceled', array('subscription_id' => $subscriptionId), array('status' => 'unlinked'));
}

function paynowgg_callback_handleOrderCompleted($gatewayModuleName, array $gatewayParams, array $body)
{
    $transactionId = (string) ($body['id'] ?? $body['order_id'] ?? '');
    if ($transactionId === '') {
        throw new Exception('Missing PayNow order id.');
    }

    if (paynowgg_callback_transactionExists($transactionId)) {
        return;
    }

    $metadata = paynowgg_callback_firstMetadata($body);
    $subscriptionId = paynowgg_callback_findSubscriptionIdInOrder($body);
    $invoiceId = (int) ($metadata['whmcs_invoice_id'] ?? 0);
    $currency = strtoupper((string) ($body['currency'] ?? ''));

    $paid = paynowgg_callback_fromMinorUnits((int) ($body['total_amount'] ?? 0), $currency);
    $fee = paynowgg_callback_fromMinorUnits(
        (int) ($body['gateway_fee_amount'] ?? 0) + (int) ($body['platform_fee_amount'] ?? 0),
        $currency
    );
    $isFreeOrder = (int) ($body['discount_amount'] ?? 0) > 0
        || (int) ($body['giftcard_usage_amount'] ?? 0) > 0
        || !empty($body['applied_coupons'])
        || !empty($body['applied_giftcards']);

    if ($invoiceId <= 0) {
        paynowgg_callback_report('Order completed without whmcs_invoice_id metadata, nothing was booked.', array('order_id' => $transactionId));
        return;
    }

    // A failed lookup must be retried, never interpreted as "invoice already paid".
    $invoice = localAPI('GetInvoice', array('invoiceid' => $invoiceId));
    if (($invoice['result'] ?? '') !== 'success') {
        throw new Exception('Could not load WHMCS invoice ' . $invoiceId . ': ' . json_encode($invoice));
    }
    $status = (string) ($invoice['status'] ?? '');

    // Link the subscription first: if it fails the webhook is retried, and linking is idempotent.
    $relid = (int) ($metadata['whmcs_subscription_relid'] ?? 0);
    $type = paynowgg_callback_normalizeType($metadata['whmcs_subscription_type'] ?? '');
    $attached = '';
    if ($subscriptionId !== '' && $relid > 0 && $type !== '') {
        $attached = paynowgg_callback_attachSubscription($gatewayParams, $type, $relid, $subscriptionId);
    }

    if ($status === 'Unpaid') {
        $amount = paynowgg_callback_resolveAmount($invoice, $invoiceId, $paid, $currency, $isFreeOrder, $transactionId);
        if ($amount === null) {
            return;
        }

        addInvoicePayment($invoiceId, $transactionId, $amount, $fee, $gatewayModuleName);
        return;
    }

    if ($subscriptionId === '') {
        paynowgg_callback_report('Order completed for an invoice that is not payable (status ' . $status . '), nothing was booked. The customer may have been charged twice.', array(
            'order_id' => $transactionId,
            'invoice_id' => $invoiceId,
            'amount' => $paid,
            'currency' => $currency,
        ));
        return;
    }

    if ($attached === 'duplicate' || $attached === 'canceled') {
        paynowgg_callback_report('Order belongs to a duplicate or cancelled subscription and the invoice is already ' . $status . '. Refund it in PayNow if the customer was charged.', array(
            'order_id' => $transactionId,
            'invoice_id' => $invoiceId,
            'subscription_id' => $subscriptionId,
            'amount' => $paid,
            'currency' => $currency,
        ));
        return;
    }

    // Renewal order: the checkout invoice must be Paid, and it must have been paid through this gateway.
    if ($status !== 'Paid' || !\WHMCS\Database\Capsule::table('tblaccounts')->where('invoiceid', '=', $invoiceId)->where('gateway', '=', 'paynowgg')->exists()) {
        paynowgg_callback_report('Subscription order cannot be matched to a paid PayNow invoice (status ' . $status . '), nothing was booked.', array(
            'order_id' => $transactionId,
            'invoice_id' => $invoiceId,
            'subscription_id' => $subscriptionId,
        ));
        return;
    }

    paynowgg_callback_handleRenewalOrder($gatewayModuleName, $transactionId, $subscriptionId, $paid, $fee, $currency, $isFreeOrder);
}

/**
 * Amount to book on an invoice, or null when it must not be booked automatically (reported to the admin).
 * Currency is read from the invoice owner's WHMCS currency (GetInvoice does not return it).
 */
function paynowgg_callback_resolveAmount(array $invoice, $invoiceId, $paid, $paidCurrency, $isFreeOrder, $transactionId)
{
    $invoiceCurrency = paynowgg_callback_invoiceCurrencyCode($invoiceId);
    $balance = round((float) ($invoice['balance'] ?? 0), 2);
    $context = array(
        'order_id' => $transactionId,
        'invoice_id' => $invoiceId,
        'paid' => $paid,
        'paid_currency' => $paidCurrency,
        'invoice_currency' => $invoiceCurrency,
        'invoice_balance' => $balance,
    );

    if ($paidCurrency === '' || $invoiceCurrency === '' || $paidCurrency !== $invoiceCurrency) {
        paynowgg_callback_report('Currency mismatch between the PayNow order and the WHMCS invoice, nothing was booked. Apply the payment manually.', $context);
        return null;
    }

    if ($balance <= 0) {
        paynowgg_callback_report('Invoice has no balance left, nothing was booked.', $context);
        return null;
    }

    if ($paid < 0) {
        paynowgg_callback_report('Negative PayNow order amount, nothing was booked.', $context);
        return null;
    }

    if ($paid == 0.0) {
        if ($isFreeOrder) {
            // 100% coupon / gift card order: the merchant explicitly made it free, settle the invoice.
            return $balance;
        }

        paynowgg_callback_report('PayNow order amount is 0 without any discount, nothing was booked.', $context);
        return null;
    }

    if ($paid > $balance + 0.005) {
        paynowgg_callback_report('PayNow order is higher than the invoice balance. Only the balance was booked; refund the difference in PayNow.', $context);
        return $balance;
    }

    return $paid;
}

function paynowgg_callback_invoiceCurrencyCode($invoiceId)
{
    $row = \WHMCS\Database\Capsule::table('tblinvoices')
        ->join('tblclients', 'tblclients.id', '=', 'tblinvoices.userid')
        ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
        ->where('tblinvoices.id', '=', (int) $invoiceId)
        ->first(array('tblcurrencies.code as code'));

    return $row ? strtoupper((string) $row->code) : '';
}

function paynowgg_callback_handleRenewalOrder($gatewayModuleName, $transactionId, $subscriptionId, $paid, $fee, $currency, $isFreeOrder)
{
    // Strict: the subscription must already be linked to a service (done by the first order / activation).
    $service = paynowgg_callback_findServiceBySubscription($subscriptionId);
    if (!$service) {
        paynowgg_callback_report('Renewal order for a subscription that is not linked to any WHMCS service, nothing was booked.', array(
            'order_id' => $transactionId,
            'subscription_id' => $subscriptionId,
            'amount' => $paid,
            'currency' => $currency,
        ));
        return;
    }

    if ($paid <= 0) {
        paynowgg_callback_report('Renewal order with a zero or negative amount, nothing was booked.', array('order_id' => $transactionId, 'subscription_id' => $subscriptionId));
        return;
    }

    $invoiceId = paynowgg_callback_findInvoiceByOrderMarker($transactionId);
    $created = false;

    if (!$invoiceId) {
        $invoiceId = paynowgg_callback_findRenewalInvoice($service['userid'], $service['relid'], $service['type']);
    }

    if (!$invoiceId) {
        $invoiceId = paynowgg_callback_createRenewalInvoice($service, $paid, $subscriptionId, $transactionId);
        $created = true;
    }

    // Idempotent: re-links the line item if a previous attempt created the invoice but failed afterwards.
    paynowgg_callback_linkInvoiceItem($invoiceId, $service);

    $invoice = localAPI('GetInvoice', array('invoiceid' => $invoiceId));
    if (($invoice['result'] ?? '') !== 'success') {
        throw new Exception('Could not load renewal invoice ' . $invoiceId . '.');
    }

    $amount = paynowgg_callback_resolveAmount($invoice, $invoiceId, $paid, $currency, $isFreeOrder, $transactionId);
    if ($amount === null) {
        return;
    }

    addInvoicePayment($invoiceId, $transactionId, $amount, $fee, $gatewayModuleName);

    logModuleCall('PayNow.gg', 'subscription renewal recorded', array(
        'invoice_id' => $invoiceId,
        'order_id' => $transactionId,
        'subscription_id' => $subscriptionId,
        'service' => $service,
        'amount' => $amount,
        'invoice_created' => $created,
    ), array('status' => 'success'));
}

function paynowgg_callback_orderMarker($transactionId)
{
    return 'PayNow Order ' . $transactionId;
}

function paynowgg_callback_findInvoiceByOrderMarker($transactionId)
{
    $row = \WHMCS\Database\Capsule::table('tblinvoices')
        ->where('status', '=', 'Unpaid')
        ->where('notes', 'like', '%' . paynowgg_callback_orderMarker($transactionId) . ']%')
        ->orderBy('id', 'asc')
        ->first(array('id'));

    return $row ? (int) $row->id : 0;
}

/**
 * Oldest unpaid invoice of the client that contains this exact service. Amount is deliberately not compared
 * (taxes, promos and credit legitimately differ).
 */
function paynowgg_callback_findRenewalInvoice($userId, $relid, $type)
{
    $row = \WHMCS\Database\Capsule::table('tblinvoiceitems')
        ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
        ->where('tblinvoices.userid', '=', (int) $userId)
        ->where('tblinvoices.status', '=', 'Unpaid')
        ->where('tblinvoiceitems.type', '=', $type === 'addon' ? 'Addon' : 'Hosting')
        ->where('tblinvoiceitems.relid', '=', (int) $relid)
        ->orderBy('tblinvoices.id', 'asc')
        ->first(array('tblinvoices.id as id'));

    return $row ? (int) $row->id : 0;
}

function paynowgg_callback_linkInvoiceItem($invoiceId, array $service)
{
    \WHMCS\Database\Capsule::table('tblinvoiceitems')
        ->where('invoiceid', '=', (int) $invoiceId)
        ->where('relid', '=', 0)
        ->update(array('type' => $service['type'] === 'addon' ? 'Addon' : 'Hosting', 'relid' => $service['relid']));
}

function paynowgg_callback_createRenewalInvoice(array $service, $amount, $subscriptionId, $transactionId)
{
    $description = 'Subscription Renewal';

    if ($service['type'] === 'hosting') {
        $resource = \WHMCS\Database\Capsule::table('tblhosting')->where('id', '=', $service['relid'])->first();
        if ($resource && !empty($resource->domain)) {
            $description = 'Renewal: ' . $resource->domain;
        }
    } else {
        $resource = \WHMCS\Database\Capsule::table('tblhostingaddons')->where('id', '=', $service['relid'])->first();
        if ($resource) {
            $addon = \WHMCS\Database\Capsule::table('tbladdons')->where('id', '=', (int) $resource->addonid)->first();
            $description = 'Renewal: ' . ($addon && $addon->name ? $addon->name : 'Addon');
        }
    }

    // The order marker in the notes lets a retry find this invoice instead of creating another one.
    $result = localAPI('CreateInvoice', array(
        'userid' => $service['userid'],
        'date' => date('Y-m-d'),
        'duedate' => date('Y-m-d'),
        'paymentmethod' => 'paynowgg',
        'sendinvoice' => false,
        'itemdescription1' => $description,
        'itemamount1' => $amount,
        'itemtaxed1' => 0,
        'notes' => '[' . paynowgg_callback_orderMarker($transactionId) . '] PayNow Subscription Renewal (ID: ' . $subscriptionId . ')',
    ));

    if (empty($result['invoiceid'])) {
        throw new Exception('Failed to create renewal invoice: ' . json_encode($result));
    }

    return (int) $result['invoiceid'];
}

function paynowgg_callback_fromMinorUnits($amount, $currencyCode)
{
    $zeroDecimalCurrencies = array(
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'
    );

    $divisor = in_array(strtoupper($currencyCode), $zeroDecimalCurrencies, true) ? 1 : 100;
    return round(((int) $amount) / $divisor, 2);
}
