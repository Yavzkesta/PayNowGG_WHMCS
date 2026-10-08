<?php
/**
 * PayNow.gg WHMCS return relay.
 *
 * PayNow may append checkout_id/order_id query parameters to return_url after a
 * hosted checkout. Some WHMCS installs/templates can error on unexpected params
 * on viewinvoice.php, so this relay strips PayNow's query params and redirects
 * customers back to the clean invoice page.
 *
 * This file does NOT mark invoices paid. Payment completion is handled only by
 * the signed webhook in callback/paynowgg.php.
 */

require_once __DIR__ . '/../../../init.php';

$invoiceId = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;

if ($invoiceId <= 0) {
    http_response_code(400);
    echo 'Missing invoice_id.';
    exit;
}

try {
    if (function_exists('logModuleCall')) {
        logModuleCall('PayNow.gg', 'return relay', array(
            'invoice_id' => $invoiceId,
            'checkout_id' => isset($_GET['checkout_id']) ? (string) $_GET['checkout_id'] : '',
            'order_id' => isset($_GET['order_id']) ? (string) $_GET['order_id'] : '',
        ), array('redirect' => 'viewinvoice.php?id=' . $invoiceId));
    }
} catch (Exception $e) {
    // Never let logging break the customer return flow.
}

header('Location: ../../../viewinvoice.php?id=' . $invoiceId, true, 302);
exit;
