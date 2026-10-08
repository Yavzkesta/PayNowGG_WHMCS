<?php
/**
 * PayNow.gg Payment Gateway for WHMCS
 *
 * Converted from the Tebex Checkout WHMCS gateway shape to use PayNow.gg.
 *
 * Installation: place this file in /modules/gateways/paynowgg.php and place
 * callback/paynowgg.php in /modules/gateways/callback/paynowgg.php.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Log helper.
 */
function paynowgg_moduleLog($action, $request, $response)
{
    logModuleCall('PayNow.gg', $action, $request, $response, $response, array(
        'apiKey',
        'webhookSigningSecret',
        'Authorization',
    ));
}

function paynowgg_MetaData()
{
    return array(
        'DisplayName' => 'PayNow.gg',
        'Version' => '1.0.4',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function paynowgg_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'PayNow.gg',
        ),
        'storeId' => array(
            'FriendlyName' => 'Store ID',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Your PayNow Store ID / flake ID.',
        ),
        'apiKey' => array(
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => '64',
            'Default' => '',
            'Description' => 'Your PayNow API key token. Enter only the token; the module adds the APIKey prefix automatically.',
        ),
        'webhookSigningSecret' => array(
            'FriendlyName' => 'Webhook Signing Secret',
            'Type' => 'password',
            'Size' => '64',
            'Default' => '',
            'Description' => 'The signing secret for your PayNow JSON webhook endpoint.',
        ),
        'apiBaseUrl' => array(
            'FriendlyName' => 'API Base URL',
            'Type' => 'text',
            'Size' => '64',
            'Default' => 'https://api.paynow.gg',
            'Description' => 'PayNow API base URL. Leave as https://api.paynow.gg unless PayNow tells you otherwise.',
        ),
        'autoRedirect' => array(
            'FriendlyName' => 'Auto Redirect After Checkout',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Ask PayNow to automatically return customers to WHMCS after successful checkout.',
        ),
        'allowSubscriptions' => array(
            'FriendlyName' => 'Allow Subscriptions',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Enable PayNow subscription checkout for WHMCS recurring hosting/addon invoice lines. When enabled, subscriptions auto-charge on renewal. One-time invoice checkout is used when disabled.',
        ),
        'debugMode' => array(
            'FriendlyName' => 'Debug Logging',
            'Type' => 'yesno',
            'Description' => 'Log PayNow API requests and webhook payloads to the WHMCS gateway log.',
        ),
    );
}

/**
 * Generates the WHMCS invoice payment button.
 */
function paynowgg_link($params)
{
    $storeId = trim((string) $params['storeId']);
    $apiKey = trim((string) $params['apiKey']);
    $apiBaseUrl = rtrim(trim((string) ($params['apiBaseUrl'] ?: 'https://api.paynow.gg')), '/');
    $allowSubscriptions = !empty($params['allowSubscriptions']);
    $debugMode = !empty($params['debugMode']);

    $invoiceId = (int) $params['invoiceid'];
    $description = (string) $params['description'];
    $amount = (float) $params['amount'];
    $currencyCode = strtoupper((string) $params['currency']);

    $client = $params['clientdetails'];
    $firstName = (string) ($client['firstname'] ?? '');
    $lastName = (string) ($client['lastname'] ?? '');
    $email = (string) ($client['email'] ?? '');
    $clientId = (string) ($client['userid'] ?? $params['clientdetails']['id'] ?? '');

    $whmcsReturnUrl = (string) $params['returnurl'];
    $systemUrl = rtrim((string) $params['systemurl'], '/');
    $moduleName = (string) $params['paymentmethod'];
    $callbackUrl = $systemUrl . '/modules/gateways/callback/' . $moduleName . '.php';
    // PayNow appends checkout_id/order_id to return_url. Some WHMCS installs
    // or templates can crash on unexpected query parameters on viewinvoice.php,
    // so return to a tiny gateway-owned relay first, then redirect to the
    // clean WHMCS invoice URL.
    $returnUrl = $systemUrl . '/modules/gateways/callback/' . $moduleName . '_return.php?invoice_id=' . $invoiceId;

    if ($storeId === '' || $apiKey === '') {
        return '<span style="color: red">PayNow.gg is not configured. Missing Store ID or API Key.</span>';
    }

    if ($amount <= 0) {
        return '<span style="color: red">This invoice does not have a positive balance to pay.</span>';
    }

    try {
        $customer = paynowgg_createCustomer($apiBaseUrl, $storeId, $apiKey, array(
            'name' => trim($firstName . ' ' . $lastName) ?: $email ?: ('WHMCS Client ' . $clientId),
            'metadata' => array(
                'whmcs_client_id' => $clientId,
                'email' => $email,
                'source' => 'whmcs',
            ),
        ), $debugMode);

        if (empty($customer['id'])) {
            throw new Exception('PayNow customer response did not include an id.');
        }

        $checkoutPayload = paynowgg_buildCheckoutPayload($params, $customer['id'], $returnUrl, $callbackUrl, $allowSubscriptions);
        $checkout = paynowgg_apiRequest($apiBaseUrl, $storeId, $apiKey, 'POST', '/checkouts', $checkoutPayload, $debugMode);

        if (empty($checkout['url'])) {
            throw new Exception('PayNow checkout response did not include a redirect url.');
        }

        $checkoutUrl = paynowgg_normaliseCheckoutUrl($checkout);
        $buttonText = htmlspecialchars($params['langpaynow'] ?? 'Pay Now', ENT_QUOTES, 'UTF-8');
        $safeCheckoutUrl = htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8');

        // Important: do not submit this as a GET form. Browsers can replace the
        // query string in the action URL, which strips PayNow's ?t= checkout token
        // and sends customers to https://checkout.paynow.gg/? with validation failed.
        return '<a class="btn btn-primary" role="button" href="' . $safeCheckoutUrl . '">' . $buttonText . '</a>';
    } catch (Exception $e) {
        paynowgg_moduleLog('failed creating PayNow checkout', array(
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'currency' => $currencyCode,
        ), array(
            'error' => $e->getMessage(),
        ));

        return '<span style="color: red">Error! Failed to create PayNow checkout. See the WHMCS gateway/module log.</span>';
    }
}

function paynowgg_refund($params)
{
    $storeId = trim((string) $params['storeId']);
    $apiKey = trim((string) $params['apiKey']);
    $apiBaseUrl = rtrim(trim((string) ($params['apiBaseUrl'] ?: 'https://api.paynow.gg')), '/');
    $orderId = trim((string) $params['transid']);
    $debugMode = !empty($params['debugMode']);

    if ($storeId === '' || $apiKey === '' || $orderId === '') {
        return array('status' => 'error', 'rawdata' => 'Missing PayNow configuration or transaction/order ID.');
    }

    try {
        $payload = array();
        $response = paynowgg_apiRequest($apiBaseUrl, $storeId, $apiKey, 'POST', '/orders/' . rawurlencode($orderId) . '/refund', $payload, $debugMode);
        return array(
            'status' => 'success',
            'rawdata' => $response,
            'transid' => $orderId,
        );
    } catch (Exception $e) {
        paynowgg_moduleLog('failed refunding PayNow order', array('order_id' => $orderId), array('error' => $e->getMessage()));
        return array(
            'status' => 'error',
            'rawdata' => $e->getMessage(),
        );
    }
}

function paynowgg_createCustomer($apiBaseUrl, $storeId, $apiKey, array $payload, $debugMode = false)
{
    return paynowgg_apiRequest($apiBaseUrl, $storeId, $apiKey, 'POST', '/customers', $payload, $debugMode);
}

function paynowgg_normaliseCheckoutUrl(array $checkout)
{
    $url = trim((string) ($checkout['url'] ?? ''));
    $token = trim((string) ($checkout['token'] ?? ''));

    if ($url !== '') {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $query = (string) ($parts['query'] ?? '');

        if ($host === 'checkout.paynow.gg' && $token !== '' && ($query === '' || $url === 'https://checkout.paynow.gg/?')) {
            return 'https://checkout.paynow.gg/?t=' . rawurlencode($token);
        }

        return $url;
    }

    if ($token !== '') {
        return 'https://checkout.paynow.gg/?t=' . rawurlencode($token);
    }

    throw new Exception('PayNow checkout response did not include a usable redirect url or token.');
}

function paynowgg_buildCheckoutPayload(array $params, $customerId, $returnUrl, $callbackUrl, $allowSubscriptions)
{
    $invoiceId = (int) $params['invoiceid'];
    $amount = (float) $params['amount'];
    $currencyCode = strtoupper((string) $params['currency']);
    $description = trim((string) $params['description']);
    $minorAmount = paynowgg_toMinorUnits($amount, $currencyCode);

    $productName = paynowgg_limitString($description ?: ('WHMCS Invoice #' . $invoiceId), 100);
    $productDescription = paynowgg_limitString(
        paynowgg_minimumString($description ?: ('Payment for WHMCS invoice #' . $invoiceId), 25),
        50000
    );

    $line = array(
        'quantity' => 1,
        'inline_product' => array(
            'slug' => paynowgg_uniqueProductSlug($invoiceId),
            'name' => $productName,
            'description' => $productDescription,
            'price' => $minorAmount,
            'allow_one_time_purchase' => true,
            'allow_subscription' => false,
        ),
        'metadata' => array(
            'whmcs_invoice_id' => (string) $invoiceId,
            'whmcs_line_type' => 'invoice',
        ),
    );

    $subscription = paynowgg_detectSubscription($params, $allowSubscriptions);
    if ($subscription !== null) {
        $line['subscription'] = true;
        $line['inline_product']['allow_subscription'] = true;
        $line['inline_product']['allow_one_time_purchase'] = false;
        $line['inline_product']['subscription_interval_value'] = $subscription['interval_value'];
        $line['inline_product']['subscription_interval_scale'] = $subscription['interval_scale'];
        $line['metadata']['whmcs_subscription_relid'] = $subscription['relid'];
        $line['metadata']['whmcs_subscription_type'] = $subscription['type'];
    }

    return array(
        'lines' => array($line),
        'return_url' => $returnUrl,
        'cancel_url' => $returnUrl,
        'auto_redirect' => !empty($params['autoRedirect']),
        'customer_id' => (string) $customerId,
        'metadata' => array(
            'whmcs_invoice_id' => (string) $invoiceId,
            'whmcs_gateway' => 'paynowgg',
            'whmcs_return_url' => (string) ($params['returnurl'] ?? $returnUrl),
            'paynow_return_relay_url' => $returnUrl,
            'whmcs_callback_url' => $callbackUrl,
        ),
    );
}



function paynowgg_uniqueProductSlug($invoiceId)
{
    // PayNow requires inline products to have globally unique slugs. Do not
    // reuse a slug for invoice retries, otherwise PayNow returns
    // "product slug is already in use" and checkout creation fails.
    $seed = 'whmcs-invoice-' . (int) $invoiceId . '-' . time() . '-' . mt_rand(1000, 999999);
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $seed));
    $slug = trim($slug, '-');

    return paynowgg_limitString($slug, 120);
}

function paynowgg_minimumString($value, $minLength)
{
    $value = trim((string) $value);
    if ($value === '') {
        $value = 'Payment for WHMCS invoice';
    }

    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length >= $minLength) {
        return $value;
    }

    $suffix = ' - generated by WHMCS';
    while ((function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value)) < $minLength) {
        $value .= $suffix;
    }

    return $value;
}

function paynowgg_limitString($value, $maxLength)
{
    $value = trim((string) $value);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($value, 'UTF-8') > $maxLength ? mb_substr($value, 0, $maxLength, 'UTF-8') : $value;
    }

    return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
}

function paynowgg_detectSubscription(array $params, $allowSubscriptions)
{
    if (!$allowSubscriptions) {
        return null;
    }

    $invoiceId = (int) $params['invoiceid'];
    $invoice = localAPI('GetInvoice', array('invoiceid' => $invoiceId));
    if (empty($invoice['items']['item']) || count($invoice['items']['item']) !== 1) {
        return null;
    }

    $item = $invoice['items']['item'][0];
    if (!in_array($item['type'], array('Hosting', 'Addon'), true)) {
        return null;
    }

    $cycle = null;
    try {
        if ($item['type'] === 'Hosting') {
            $hosting = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $item['relid'])->first();
            $cycle = $hosting ? $hosting->billingcycle : null;
        } elseif ($item['type'] === 'Addon') {
            $addon = \WHMCS\Database\Capsule::table('tblhostingaddons')->where('id', $item['relid'])->first();
            $cycle = $addon ? $addon->billingcycle : null;
        }
    } catch (Exception $e) {
        return null;
    }

    $interval = paynowgg_whmcsCycleToPayNowInterval((string) $cycle);
    if ($interval === null) {
        return null;
    }

    return array(
        'interval_value' => $interval['value'],
        'interval_scale' => $interval['scale'],
        'relid' => (string) $item['relid'],
        'type' => strtolower($item['type']),
    );
}

function paynowgg_whmcsCycleToPayNowInterval($billingCycle)
{
    switch ($billingCycle) {
        case 'Monthly':
            return array('value' => 1, 'scale' => 'month');
        case 'Quarterly':
            return array('value' => 3, 'scale' => 'month');
        case 'Semi-Annually':
            return array('value' => 6, 'scale' => 'month');
        case 'Annually':
            return array('value' => 1, 'scale' => 'year');
        case 'Biennially':
        case 'Bienially':
            return array('value' => 2, 'scale' => 'year');
        case 'Triennially':
        case 'Trienially':
            return array('value' => 3, 'scale' => 'year');
        default:
            return null;
    }
}

function paynowgg_apiRequest($apiBaseUrl, $storeId, $apiKey, $method, $path, array $payload = null, $debugMode = false)
{
    $url = rtrim($apiBaseUrl, '/') . '/v1/stores/' . rawurlencode($storeId) . $path;
    $jsonPayload = $payload === null ? '' : json_encode($payload);

    $ch = curl_init($url);
    $headers = array(
        'Authorization: APIKey ' . $apiKey,
        'Accept: application/json',
        'Content-Type: application/json',
    );

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    }

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $responseBody, true);

    if ($debugMode) {
        paynowgg_moduleLog('PayNow API ' . $method . ' ' . $path, $payload, array(
            'status' => $statusCode,
            'body' => $decoded ?: $responseBody,
        ));
    }

    if ($responseBody === false || $curlError !== '') {
        throw new Exception('cURL error calling PayNow: ' . $curlError);
    }

    if ($statusCode < 200 || $statusCode > 299) {
        throw new Exception('PayNow API returned HTTP ' . $statusCode . ': ' . $responseBody);
    }

    return is_array($decoded) ? $decoded : array();
}

function paynowgg_toMinorUnits($amount, $currencyCode)
{
    $zeroDecimalCurrencies = array(
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'
    );

    $multiplier = in_array(strtoupper($currencyCode), $zeroDecimalCurrencies, true) ? 1 : 100;
    return (int) round(((float) $amount) * $multiplier);
}
