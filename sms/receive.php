<?php
/**
 * BayaniServe — SMS Receive Handler
 *
 * This runs on the LOCAL PC (the one with the modem).
 * Gammu SMSD calls this automatically when an SMS arrives.
 *
 * Gammu passes SMS data as environment variables:
 *   SMS_1_TEXT   — message body
 *   SMS_1_NUMBER — sender's number
 *
 * Setup in smsdrc:
 *   runonreceive = C:\xampp\php\php.exe C:\xampp\htdocs\bayani\sms\receive.php
 *
 * HOW IT WORKS:
 * 1. Gammu fires this script with the SMS data in env vars
 * 2. This script POSTs the SMS to your ONLINE BayaniServe server
 * 3. The online server parses it and saves reservations/requests to DB
 * 4. The online server queues a reply in sms_outbox
 * 5. poll_outbox.php (running every 30s) picks up the reply and sends it via Gammu
 */

// ── Config — update ONLINE_URL to your actual hosted domain ──────
define('ONLINE_URL',    'https://yourdomain.com/bayani');   // ← change this
define('SMS_API_SECRET','change_this_to_a_random_secret');  // must match online server

// ── Get SMS data from Gammu env vars ─────────────────────────────
$from    = getenv('SMS_1_NUMBER') ?: ($argv[1] ?? '');
$message = getenv('SMS_1_TEXT')   ?: ($argv[2] ?? '');

if (empty($from) || empty($message)) {
    file_put_contents(__DIR__ . '/receive_error.log',
        date('Y-m-d H:i:s') . " — No SMS data received from Gammu\n", FILE_APPEND);
    exit(1);
}

// ── Forward to online server ──────────────────────────────────────
$payload = http_build_query([
    'secret'  => SMS_API_SECRET,
    'from'    => $from,
    'message' => $message,
]);

$ch = curl_init(ONLINE_URL . '/sms/receive_api.php');
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        15);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Log result
$logLine = date('Y-m-d H:i:s') . " FROM:{$from} HTTP:{$httpCode} MSG:" . substr($message, 0, 50) . "\n";
file_put_contents(__DIR__ . '/receive.log', $logLine, FILE_APPEND);

exit(0);
