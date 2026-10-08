<?php
/**
 * PayNowGG-WHMCS temporary test webhook callback.
 *
 * Purpose:
 * - Always return HTTP 200 for PayNow webhook POSTs.
 * - Log the raw request so you can confirm PayNow reached your server.
 *
 * WARNING: This is for onboarding/testing only.
 * It does NOT validate PayNow signatures.
 * It does NOT mark WHMCS invoices paid.
 * Remove it after the PayNow webhook step is completed.
 */

// Optional: set a token and use /paynow_test_callback.php?token=YOUR_TOKEN
// Leave blank to accept all requests.
$requiredToken = '';

if ($requiredToken !== '') {
    $providedToken = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if (!hash_equals($requiredToken, $providedToken)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo "Forbidden\n";
        exit;
    }
}

$rawBody = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];
$logFile = __DIR__ . '/paynow-test-webhook.log';

$entry = [
    'time' => gmdate('c'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    'headers' => $headers,
    'raw_body' => $rawBody,
    'json_body' => json_decode($rawBody, true),
];

@file_put_contents(
    $logFile,
    json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n",
    FILE_APPEND | LOCK_EX
);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'message' => 'PayNow test webhook received',
    'time' => gmdate('c'),
]);
