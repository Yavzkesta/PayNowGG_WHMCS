<?php
/**
 * PayNow.gg callback/webhook endpoint for WHMCS.
 *
 * Money flow (single source of truth = ON_ORDER_COMPLETED, which carries the order id and amounts):
 *  - first order of a checkout  -> pays the WHMCS invoice referenced in the checkout metadata
 *  - renewal order of a subscription -> pays the pending WHMCS renewal invoice of the service
 *    (or creates one linked to the service) so WHMCS never bills the renewal twice.
 * Subscription events only link / unlink the PayNow subscription id on the WHMCS service.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

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
            paynowgg_callback_handleOrderCompleted($gatewayModuleName, $gatewayParams, $body);
            http_response_code(200);
            echo 'OK';
            break;

        case 'ON_SUBSCRIPTION_ACTIVATED':
        case 'ON_SUBSCRIPTION_RENEWED':
            paynowgg_callback_linkSubscription($body);
            http_response_code(200);
            echo 'OK';
            break;

        case 'ON_SUBSCRIPTION_CANCELED':
            paynowgg_callback_unlinkSubscription($body);
            http_response_code(200);
            echo 'OK';
            break;

        case 'ON_REFUND':
        case 'ON_CHARGEBACK':
            // WHMCS does not require an automatic reversal here. Logging the signed event keeps an audit trail.
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

function paynowgg_callback_lock($name)
{
    // Named MySQL lock, released automatically when the request ends. Serialises concurrent webhooks.
    try {
        \WHMCS\Database\Capsule::connection()->select('SELECT GET_LOCK(?, 20) AS l', array('paynowgg_' . substr(md5($name), 0, 32)));
    } catch (\Throwable $e) {
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
        $body['checkout']['lines'][0]['metadata'] ?? null,
        $body['order']['checkout']['lines'][0]['metadata'] ?? null,
        $body['lines'][0]['metadata'] ?? null,
    );

    $merged = array();
    foreach ($candidates as $meta) {
        if (is_array($meta)) {
            $merged = $merged + $meta;
        }
    }

    // Metadata of every checkout line (the subscription link lives on the line, the invoice id on both).
    foreach ((array) ($body['checkout']['lines'] ?? array()) as $line) {
        if (is_array($line) && is_array($line['metadata'] ?? null)) {
            $merged = $merged + $line['metadata'];
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

    return '';
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

function paynowgg_callback_linkSubscription(array $body)
{
    $subscriptionId = (string) ($body['id'] ?? '');
    if ($subscriptionId === '') {
        return;
    }

    $metadata = paynowgg_callback_firstMetadata($body);
    $relid = (int) ($metadata['whmcs_subscription_relid'] ?? 0);
    $type = paynowgg_callback_normalizeType($metadata['whmcs_subscription_type'] ?? '');

    if ($relid <= 0 || $type === '') {
        return;
    }

    paynowgg_callback_lock('sub' . $subscriptionId);

    \WHMCS\Database\Capsule::table(paynowgg_callback_table($type))
        ->where('id', '=', $relid)
        ->update(array('subscriptionid' => $subscriptionId));
}

function paynowgg_callback_unlinkSubscription(array $body)
{
    $subscriptionId = (string) ($body['id'] ?? '');
    if ($subscriptionId === '') {
        return;
    }

    paynowgg_callback_lock('sub' . $subscriptionId);

    // Cleared so the customer can pay the next invoice (or start a new subscription) normally.
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

    // Serialise per order so duplicate / concurrent deliveries cannot both record a payment.
    paynowgg_callback_lock('order' . $transactionId);

    if (paynowgg_callback_transactionExists($transactionId)) {
        return;
    }

    $metadata = paynowgg_callback_firstMetadata($body);
    $subscriptionId = paynowgg_callback_findSubscriptionIdInOrder($body);
    $invoiceId = (int) ($metadata['whmcs_invoice_id'] ?? 0);

    $currency = (string) ($body['currency'] ?? '');
    $amount = isset($body['total_amount']) ? paynowgg_callback_fromMinorUnits((int) $body['total_amount'], $currency) : 0.0;
    $fee = paynowgg_callback_fromMinorUnits(
        (int) ($body['gateway_fee_amount'] ?? 0) + (int) ($body['platform_fee_amount'] ?? 0),
        $currency
    );

    // 1) Link the subscription to the service as soon as we can (first order of the checkout).
    $relid = (int) ($metadata['whmcs_subscription_relid'] ?? 0);
    $type = paynowgg_callback_normalizeType($metadata['whmcs_subscription_type'] ?? '');
    if ($subscriptionId !== '' && $relid > 0 && $type !== '') {
        \WHMCS\Database\Capsule::table(paynowgg_callback_table($type))
            ->where('id', '=', $relid)
            ->update(array('subscriptionid' => $subscriptionId));
    }

    // 2) Pay the invoice referenced by the checkout if it is still payable.
    if ($invoiceId > 0) {
        paynowgg_callback_lock('inv' . $invoiceId);
        $invoice = localAPI('GetInvoice', array('invoiceid' => $invoiceId));

        if (($invoice['result'] ?? '') === 'success' && ($invoice['status'] ?? '') === 'Unpaid') {
            $payable = paynowgg_callback_payableAmount($invoice, $amount, $currency);
            addInvoicePayment($invoiceId, $transactionId, $payable, $fee, $gatewayModuleName);
            return;
        }
    }

    // 3) Invoice already paid / missing: this is a renewal order of a subscription.
    if ($subscriptionId !== '') {
        paynowgg_callback_handleRenewalOrder($gatewayModuleName, $transactionId, $subscriptionId, $metadata, $amount, $fee, $body);
        return;
    }

    logModuleCall('PayNow.gg', 'order completed ignored', array(
        'order_id' => $transactionId,
        'invoice_id' => $invoiceId,
    ), array('status' => 'invoice not payable and order is not a subscription renewal'));
}

/**
 * Amount to record: never more than the invoice balance, and never trust a different-currency amount.
 */
function paynowgg_callback_payableAmount(array $invoice, $paidAmount, $paidCurrency)
{
    $balance = isset($invoice['balance']) ? (float) $invoice['balance'] : (float) ($invoice['total'] ?? 0);
    $invoiceCurrency = (string) ($invoice['currencycode'] ?? $invoice['currency'] ?? '');

    $sameCurrency = $paidCurrency === '' || $invoiceCurrency === '' || strtoupper($paidCurrency) === strtoupper($invoiceCurrency);

    if (!$sameCurrency) {
        logModuleCall('PayNow.gg', 'currency mismatch detected', array(
            'invoice_id' => $invoice['invoiceid'] ?? null,
            'payment_currency' => $paidCurrency,
            'payment_amount' => $paidAmount,
            'invoice_currency' => $invoiceCurrency,
            'balance' => $balance,
        ), array('status' => 'using invoice balance'));

        return $balance;
    }

    if ($paidAmount <= 0 || $paidAmount > $balance) {
        return $balance;
    }

    return $paidAmount;
}

function paynowgg_callback_handleRenewalOrder($gatewayModuleName, $transactionId, $subscriptionId, array $metadata, $amount, $fee, array $body)
{
    paynowgg_callback_lock('sub' . $subscriptionId);

    $service = paynowgg_callback_findServiceBySubscription($subscriptionId);

    if (!$service) {
        // Fall back on the metadata of the original checkout.
        $type = paynowgg_callback_normalizeType($metadata['whmcs_subscription_type'] ?? '');
        $relid = (int) ($metadata['whmcs_subscription_relid'] ?? 0);
        if ($type !== '' && $relid > 0) {
            $row = \WHMCS\Database\Capsule::table(paynowgg_callback_table($type))->where('id', '=', $relid)->first();
            if ($row) {
                $service = array('type' => $type, 'relid' => $relid, 'userid' => (int) $row->userid);
            }
        }
    }

    if (!$service) {
        throw new Exception('Renewal order ' . $transactionId . ': no WHMCS service linked to subscription ' . $subscriptionId);
    }

    if ($amount <= 0) {
        throw new Exception('Renewal order ' . $transactionId . ': invalid amount.');
    }

    $invoiceId = paynowgg_callback_findRenewalInvoice($service['userid'], $service['relid'], $service['type']);
    $created = false;

    if (!$invoiceId) {
        $invoiceId = paynowgg_callback_createRenewalInvoice($service, $amount, $subscriptionId);
        $created = true;
    }

    paynowgg_callback_lock('inv' . $invoiceId);

    $invoice = localAPI('GetInvoice', array('invoiceid' => $invoiceId));
    $payable = paynowgg_callback_payableAmount($invoice, $amount, (string) ($body['currency'] ?? ''));

    addInvoicePayment($invoiceId, $transactionId, $payable, $fee, $gatewayModuleName);

    logModuleCall('PayNow.gg', 'subscription renewal recorded', array(
        'invoice_id' => $invoiceId,
        'order_id' => $transactionId,
        'subscription_id' => $subscriptionId,
        'service' => $service,
        'amount' => $payable,
        'invoice_created' => $created,
    ), array('status' => 'success'));
}

/**
 * Oldest unpaid invoice of the client that contains this exact service. Amount is deliberately not compared
 * (taxes, promos and credit legitimately differ).
 */
function paynowgg_callback_findRenewalInvoice($userId, $relid, $type)
{
    $itemType = $type === 'addon' ? 'Addon' : 'Hosting';

    $row = \WHMCS\Database\Capsule::table('tblinvoiceitems')
        ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
        ->where('tblinvoices.userid', '=', (int) $userId)
        ->where('tblinvoices.status', '=', 'Unpaid')
        ->where('tblinvoiceitems.type', '=', $itemType)
        ->where('tblinvoiceitems.relid', '=', (int) $relid)
        ->orderBy('tblinvoices.id', 'asc')
        ->first(array('tblinvoices.id as id'));

    return $row ? (int) $row->id : 0;
}

function paynowgg_callback_createRenewalInvoice(array $service, $amount, $subscriptionId)
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

    // Single call with its line item: never leaves a blank invoice behind.
    $result = localAPI('CreateInvoice', array(
        'userid' => $service['userid'],
        'date' => date('Y-m-d'),
        'duedate' => date('Y-m-d'),
        'paymentmethod' => 'paynowgg',
        'sendinvoice' => false,
        'itemdescription1' => $description,
        'itemamount1' => $amount,
        'itemtaxed1' => 0,
        'notes' => 'PayNow Subscription Renewal (ID: ' . $subscriptionId . ')',
    ));

    if (empty($result['invoiceid'])) {
        throw new Exception('Failed to create renewal invoice: ' . json_encode($result));
    }

    $invoiceId = (int) $result['invoiceid'];

    // Link the item to the service so WHMCS advances the next due date and does not invoice it again.
    \WHMCS\Database\Capsule::table('tblinvoiceitems')
        ->where('invoiceid', '=', $invoiceId)
        ->update(array('type' => $service['type'] === 'addon' ? 'Addon' : 'Hosting', 'relid' => $service['relid']));

    return $invoiceId;
}

function paynowgg_callback_fromMinorUnits($amount, $currencyCode)
{
    $zeroDecimalCurrencies = array(
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'
    );

    $divisor = in_array(strtoupper($currencyCode), $zeroDecimalCurrencies, true) ? 1 : 100;
    return round(((int) $amount) / $divisor, 2);
}
