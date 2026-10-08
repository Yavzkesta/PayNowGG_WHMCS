<?php
/**
 * PayNow.gg callback/webhook endpoint for WHMCS.
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
            paynowgg_callback_handleSubscriptionEvent($gatewayParams, $body);
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
} catch (Exception $e) {
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

function paynowgg_callback_handleOrderCompleted($gatewayModuleName, array $gatewayParams, array $body)
{
    $metadata = array();
    if (isset($body['checkout']['metadata']) && is_array($body['checkout']['metadata'])) {
        $metadata = $body['checkout']['metadata'];
    }

    $invoiceId = $metadata['whmcs_invoice_id'] ?? null;
    if (!$invoiceId && isset($body['checkout']['lines'][0]['metadata']['whmcs_invoice_id'])) {
        $invoiceId = $body['checkout']['lines'][0]['metadata']['whmcs_invoice_id'];
    }

    if (!$invoiceId) {
        throw new Exception('Missing whmcs_invoice_id metadata on PayNow order.');
    }

    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

    $transactionId = (string) ($body['id'] ?? $body['order_id'] ?? $body['checkout_id'] ?? '');
    if ($transactionId === '') {
        throw new Exception('Missing PayNow order/transaction id.');
    }

    checkCbTransID($transactionId);

    $paymentCurrency = (string) ($body['currency'] ?? '');
    $totalMinor = isset($body['total_amount']) ? (int) $body['total_amount'] : null;
    $feeMinor = (int) ($body['gateway_fee_amount'] ?? 0) + (int) ($body['platform_fee_amount'] ?? 0);

    $paymentAmount = $totalMinor !== null ? paynowgg_callback_fromMinorUnits($totalMinor, $paymentCurrency) : 0.0;
    $paymentFee = $feeMinor > 0 ? paynowgg_callback_fromMinorUnits($feeMinor, $paymentCurrency) : 0.0;

    // Fetch invoice to get WHMCS invoice currency and amount
    $invoice = localAPI('GetInvoice', array('invoiceid' => $invoiceId));
    $invoiceCurrency = isset($invoice['currency']) ? (string) $invoice['currency'] : '';
    $invoiceTotal = isset($invoice['total']) ? (float) $invoice['total'] : 0.0;

    // Handle currency mismatch: if PayNow payment currency differs from invoice currency,
    // use the invoice amount as the authoritative value to prevent overpayment issues
    // when customers change currencies during checkout
    if ($paymentCurrency !== '' && $invoiceCurrency !== '' && strtoupper($paymentCurrency) !== strtoupper($invoiceCurrency)) {
        logModuleCall('PayNow.gg', 'currency mismatch detected', array(
            'invoice_id' => $invoiceId,
            'payment_currency' => $paymentCurrency,
            'payment_amount' => $paymentAmount,
            'invoice_currency' => $invoiceCurrency,
            'invoice_total' => $invoiceTotal,
        ), array('status' => 'using invoice total'));

        // Use the invoice amount in its own currency to prevent overpayment credits
        // when customer pays in different currency at different exchange rate
        $paymentAmount = $invoiceTotal;
        $paymentFee = 0.0; // Don't charge fees when converting currencies
    }

    addInvoicePayment($invoiceId, $transactionId, $paymentAmount, $paymentFee, $gatewayModuleName);
}

function paynowgg_callback_handleSubscriptionEvent(array $gatewayParams, array $body)
{
    $subscriptionId = (string) ($body['id'] ?? $body['subscription_id'] ?? '');
    if ($subscriptionId === '') {
        return;
    }

    $metadata = array();
    if (isset($body['order']['checkout']['lines'][0]['metadata']) && is_array($body['order']['checkout']['lines'][0]['metadata'])) {
        $metadata = $body['order']['checkout']['lines'][0]['metadata'];
    } elseif (isset($body['checkout']['lines'][0]['metadata']) && is_array($body['checkout']['lines'][0]['metadata'])) {
        $metadata = $body['checkout']['lines'][0]['metadata'];
    }

    $relid = $metadata['whmcs_subscription_relid'] ?? null;
    $type = $metadata['whmcs_subscription_type'] ?? null;

    if (!$relid || !$type) {
        return;
    }

    if ($type === 'hosting') {
        \WHMCS\Database\Capsule::table('tblhosting')->where('id', '=', (int) $relid)->update(array('subscriptionid' => $subscriptionId));
    } elseif ($type === 'addon') {
        \WHMCS\Database\Capsule::table('tblhostingaddons')->where('id', '=', (int) $relid)->update(array('subscriptionid' => $subscriptionId));
    }

    // Handle subscription renewals - create invoices for recurring charges
    $eventType = (string) ($body['event_type'] ?? '');
    if ($eventType === 'ON_SUBSCRIPTION_RENEWED') {
        paynowgg_callback_handleSubscriptionRenewal($gatewayParams, $body, $relid, $type);
    }
}

function paynowgg_callback_handleSubscriptionRenewal(array $gatewayParams, array $body, $relid, $type)
{
    try {
        if ($type === 'hosting') {
            $resource = \WHMCS\Database\Capsule::table('tblhosting')->where('id', '=', (int) $relid)->first();
        } else {
            $resource = \WHMCS\Database\Capsule::table('tblhostingaddons')->where('id', '=', (int) $relid)->first();
        }

        if (!$resource) {
            logModuleCall('PayNow.gg', 'subscription renewal: resource not found', array(
                'type' => $type,
                'relid' => $relid,
            ), array('status' => 'failed'));
            return;
        }

        $userId = (int) $resource->userid;
        $renewalAmount = null;
        $renewalCurrency = 'USD';

        // Extract renewal amount from PayNow webhook
        if (isset($body['order']) && is_array($body['order'])) {
            $order = $body['order'];
            if (isset($order['total_amount'])) {
                $renewalAmount = paynowgg_callback_fromMinorUnits((int) $order['total_amount'], (string) ($order['currency'] ?? 'USD'));
                $renewalCurrency = (string) ($order['currency'] ?? 'USD');
            }
        }

        // If we couldn't get amount from order, try to get it from the subscription or event body
        if ($renewalAmount === null && isset($body['amount'])) {
            $renewalAmount = paynowgg_callback_fromMinorUnits((int) $body['amount'], (string) ($body['currency'] ?? 'USD'));
            $renewalCurrency = (string) ($body['currency'] ?? 'USD');
        }

        // If we still don't have a renewal amount, we can't proceed
        if ($renewalAmount === null || $renewalAmount <= 0) {
            logModuleCall('PayNow.gg', 'subscription renewal: unable to extract amount', array(
                'type' => $type,
                'relid' => $relid,
                'body' => $body,
            ), array('status' => 'skipped'));
            return;
        }

        // Try to find an existing renewal invoice created by WHMCS's automatic renewal system
        // WHMCS creates renewal invoices ~14 days before the renewal date
        $renewalInvoiceId = paynowgg_findRenewalInvoice($userId, $renewalAmount, $relid, $type);

        if ($renewalInvoiceId) {
            // Mark existing renewal invoice as paid
            $subscriptionId = (string) ($body['id'] ?? $body['subscription_id'] ?? '');
            $transactionId = $subscriptionId !== '' ? $subscriptionId . '-renewal' : (string) ($body['order_id'] ?? $body['id'] ?? '');
            
            addInvoicePayment($renewalInvoiceId, $transactionId, $renewalAmount, 0.0, 'paynowgg');

            logModuleCall('PayNow.gg', 'subscription renewal: marked existing invoice as paid', array(
                'invoice_id' => $renewalInvoiceId,
                'user_id' => $userId,
                'type' => $type,
                'relid' => $relid,
                'renewal_amount' => $renewalAmount,
                'subscription_id' => $subscriptionId,
            ), array('status' => 'success'));
        } else {
            // No existing renewal invoice found - create one
            // This handles cases where WHMCS renewal system didn't create an invoice
            // (e.g., manual subscription setup or if renewal generation failed)
            paynowgg_createRenewalInvoice($userId, $renewalAmount, $relid, $type, $body);
        }
    } catch (Exception $e) {
        logModuleCall('PayNow.gg', 'subscription renewal: error', array(
            'type' => $type,
            'relid' => $relid,
            'event_id' => $body['id'] ?? 'unknown',
        ), array('error' => $e->getMessage()), array('error' => $e->getMessage()));
    }
}

function paynowgg_findRenewalInvoice($userId, $renewalAmount, $relid, $type)
{
    try {
        // Look for unpaid invoices created in the last 30 days
        // But specifically match to the hosting/addon record being renewed
        $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
        
        $invoices = \WHMCS\Database\Capsule::table('tblinvoices')
            ->where('userid', '=', (int) $userId)
            ->where('status', '=', 'Unpaid')
            ->where('datecreated', '>=', $thirtyDaysAgo)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($invoices as $invoice) {
            $total = (float) $invoice->total;
            
            // First check: amount must match (within 0.01)
            if (abs($total - $renewalAmount) >= 0.01) {
                continue;
            }

            // Second check: invoice must contain a line item for this specific hosting/addon
            $invoiceId = (int) $invoice->id;
            if (paynowgg_invoiceContainsResource($invoiceId, $relid, $type)) {
                return $invoiceId;
            }
        }
        
        return null;
    } catch (Exception $e) {
        logModuleCall('PayNow.gg', 'subscription renewal: error finding renewal invoice', array(
            'user_id' => $userId,
            'renewal_amount' => $renewalAmount,
            'relid' => $relid,
            'type' => $type,
        ), array('error' => $e->getMessage()));
        return null;
    }
}

function paynowgg_invoiceContainsResource($invoiceId, $relid, $type)
{
    try {
        $invoiceId = (int) $invoiceId;
        $relid = (int) $relid;
        
        // Get all line items for this invoice
        $items = \WHMCS\Database\Capsule::table('tblinvoiceitems')
            ->where('invoiceid', '=', $invoiceId)
            ->get();

        if (empty($items)) {
            return false;
        }

        foreach ($items as $item) {
            $itemType = (string) ($item->type ?? '');
            $itemRelid = (int) ($item->relid ?? 0);
            
            // Match the type and relid to the resource
            if ($type === 'hosting' && $itemType === 'Hosting' && $itemRelid === $relid) {
                return true;
            }
            if ($type === 'addon' && $itemType === 'Addon' && $itemRelid === $relid) {
                return true;
            }
        }
        
        return false;
    } catch (Exception $e) {
        logModuleCall('PayNow.gg', 'subscription renewal: error checking invoice items', array(
            'invoice_id' => $invoiceId,
            'relid' => $relid,
            'type' => $type,
        ), array('error' => $e->getMessage()));
        return false;
    }
}

function paynowgg_createRenewalInvoice($userId, $renewalAmount, $relid, $type, $body)
{
    try {
        // Get resource details for description
        if ($type === 'hosting') {
            $resource = \WHMCS\Database\Capsule::table('tblhosting')->where('id', '=', (int) $relid)->first();
        } else {
            $resource = \WHMCS\Database\Capsule::table('tblhostingaddons')->where('id', '=', (int) $relid)->first();
        }

        // Create invoice for renewal
        $invoiceParams = array(
            'userid' => $userId,
            'date' => date('Y-m-d'),
            'duedate' => date('Y-m-d', strtotime('+7 days')),
            'itemdescription' => '',
            'itemamount' => '',
            'itemtaxed' => '',
            'notes' => 'PayNow Subscription Renewal (ID: ' . (string) ($body['id'] ?? 'unknown') . ')',
        );

        $invoiceResult = localAPI('CreateInvoice', $invoiceParams);
        if (!isset($invoiceResult['invoiceid'])) {
            throw new Exception('Failed to create renewal invoice');
        }

        $invoiceId = (int) $invoiceResult['invoiceid'];

        // Add the renewal line item to the invoice
        $description = '';
        if ($type === 'hosting' && $resource && isset($resource->domain)) {
            $description = 'Renewal: ' . (string) $resource->domain;
        } elseif ($type === 'addon' && $resource) {
            $addonName = 'Addon';
            try {
                $addonData = \WHMCS\Database\Capsule::table('tbladdons')->where('id', '=', (int) $resource->addonid)->first();
                if ($addonData) {
                    $addonName = (string) $addonData->name;
                }
            } catch (Exception $e) {
                // Use default addon name
            }
            $description = 'Renewal: ' . $addonName;
        }

        $lineParams = array(
            'invoiceid' => $invoiceId,
            'description' => $description ?: 'Subscription Renewal',
            'amount' => $renewalAmount,
            'taxed' => 0,
        );

        localAPI('InvoiceAddItem', $lineParams);

        // PayNow has already charged the customer at renewal time, so mark the invoice as paid
        $subscriptionId = (string) ($body['id'] ?? $body['subscription_id'] ?? '');
        $transactionId = $subscriptionId !== '' ? $subscriptionId . '-renewal' : (string) ($body['order_id'] ?? $body['id'] ?? '');
        
        addInvoicePayment($invoiceId, $transactionId, $renewalAmount, 0.0, 'paynowgg');

        logModuleCall('PayNow.gg', 'subscription renewal: invoice created and marked paid', array(
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'type' => $type,
            'relid' => $relid,
            'renewal_amount' => $renewalAmount,
            'subscription_id' => $subscriptionId,
        ), array('status' => 'created (no existing found)'));
    } catch (Exception $e) {
        logModuleCall('PayNow.gg', 'subscription renewal: error creating invoice', array(
            'user_id' => $userId,
            'relid' => $relid,
            'type' => $type,
        ), array('error' => $e->getMessage()), array('error' => $e->getMessage()));
    }
}

function paynowgg_callback_fromMinorUnits($amount, $currencyCode)
{
    $zeroDecimalCurrencies = array(
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'
    );

    $divisor = in_array(strtoupper($currencyCode), $zeroDecimalCurrencies, true) ? 1 : 100;
    return round(((int) $amount) / $divisor, 2);
}
