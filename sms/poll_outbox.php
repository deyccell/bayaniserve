<?php
/**
 * BayaniServe — SMS Outbox Poller
 *
 * This runs on the LOCAL PC every 30 seconds via Windows Task Scheduler.
 * It asks the online server "any SMS to send?" then fires Gammu to send them.
 *
 * Task Scheduler setup (Windows):
 *   Program: C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\bayani\sms\poll_outbox.php
 *   Trigger: Every 30 seconds (set to repeat every 30s for 1 day, indefinitely)
 *
 * HOW IT WORKS:
 * 1. Calls online server GET /sms/outbox_api.php
 * 2. Gets list of pending outbound SMS
 * 3. Sends each one via Gammu (or Semaphore — see send_sms.php)
 * 4. Reports back to online server which ones were sent/failed
 */

require_once __DIR__ . '/send_sms.php';

// ── Config — must match receive.php ──────────────────────────────
define('ONLINE_URL',    'https://yourdomain.com/bayani');   // ← change this
define('SMS_API_SECRET','change_this_to_a_random_secret');  // must match online server

// ── Fetch pending outbox from online server ───────────────────────
$url = ONLINE_URL . '/sms/outbox_api.php?secret=' . urlencode(SMS_API_SECRET);
$ch  = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || empty($response)) {
    file_put_contents(__DIR__ . '/poll_error.log',
        date('Y-m-d H:i:s') . " — Could not reach online server (HTTP {$httpCode})\n", FILE_APPEND);
    exit(1);
}

$outbox = json_decode($response, true);
if (empty($outbox)) exit(0);   // nothing to send

$sentIds    = [];
$failedIds  = [];

foreach ($outbox as $sms) {
    $success = sendSMS($sms['mobile_number'], $sms['message']);
    if ($success) {
        $sentIds[]   = $sms['id'];
    } else {
        $failedIds[] = $sms['id'];
    }
    // Small delay between messages to avoid modem overload
    usleep(500000); // 0.5 seconds
}

// ── Report back to online server ──────────────────────────────────
$payload = http_build_query([
    'secret'     => SMS_API_SECRET,
    'sent_ids'   => implode(',', $sentIds),
    'failed_ids' => implode(',', $failedIds),
]);
$ch = curl_init(ONLINE_URL . '/sms/outbox_api.php');
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        10);
curl_exec($ch);
curl_close($ch);

// Log
$logLine = date('Y-m-d H:i:s') . " — Sent: " . count($sentIds) . ", Failed: " . count($failedIds) . "\n";
file_put_contents(__DIR__ . '/poll.log', $logLine, FILE_APPEND);
exit(0);
