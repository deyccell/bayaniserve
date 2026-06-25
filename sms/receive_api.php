<?php
/**
 * BayaniServe — Online SMS Receive API
 *
 * Lives on the ONLINE SERVER.
 * The local PC's receive.php POSTs here when Gammu gets an SMS.
 *
 * URL: https://yourdomain.com/bayani/sms/receive_api.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/sms_parser.php';

header('Content-Type: application/json');

// ── Validate secret ───────────────────────────────────────────────
define('SMS_API_SECRET', 'change_this_to_a_random_secret');  // must match local PC

if (($_POST['secret'] ?? '') !== SMS_API_SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$from    = trim($_POST['from']    ?? '');
$message = trim($_POST['message'] ?? '');

if (empty($from) || empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing from or message']);
    exit;
}

// Hand off to rule-based parser — saves to DB, queues reply in sms_outbox
handleIncomingSMS($from, $message);

echo json_encode(['status' => 'ok']);
