<?php
/**
 * BayaniServe — SMS Send Helper
 *
 * This is the ONLY place that touches the actual sending mechanism.
 * To switch from Gammu (local modem) to Semaphore (cloud API):
 *   1. Set SMS_PROVIDER to 'semaphore' below
 *   2. Fill in your SEMAPHORE_API_KEY
 * Nothing else in the codebase needs to change.
 *
 * This file runs on the LOCAL PC (the one with the modem plugged in).
 */

// ── Config ────────────────────────────────────────────────────────
define('SMS_PROVIDER',      'gammu');       // 'gammu' or 'semaphore'
define('SEMAPHORE_API_KEY', '');            // only needed if using semaphore
define('GAMMU_SEND_CMD',    'gammu sendsms TEXT');   // base command

/**
 * Send a single SMS.
 *
 * @param string $mobile  e.g. "09171234567"
 * @param string $message Plain text, keep under 160 chars for single SMS
 * @return bool           true = sent, false = failed
 */
function sendSMS(string $mobile, string $message): bool {
    $mobile  = preg_replace('/[^0-9+]/', '', $mobile);
    $message = trim($message);

    if (SMS_PROVIDER === 'semaphore') {
        return sendViaSemaphore($mobile, $message);
    }
    return sendViaGammu($mobile, $message);
}

// ── Gammu (local modem) ───────────────────────────────────────────
function sendViaGammu(string $mobile, string $message): bool {
    // Gammu expects number in +63 format for Philippines
    if (str_starts_with($mobile, '09')) {
        $mobile = '+63' . substr($mobile, 1);
    }
    $escapedMsg    = escapeshellarg($message);
    $escapedMobile = escapeshellarg($mobile);
    $cmd    = GAMMU_SEND_CMD . " {$escapedMobile} -text {$escapedMsg} 2>&1";
    $output = shell_exec($cmd);
    // Gammu prints nothing on success; any output usually means error
    return empty(trim($output ?? ''));
}

// ── Semaphore (cloud API — swap-in for online hosting) ────────────
function sendViaSemaphore(string $mobile, string $message): bool {
    $payload = http_build_query([
        'apikey'      => SEMAPHORE_API_KEY,
        'number'      => $mobile,
        'message'     => $message,
        'sendername'  => 'BAYANI',   // register sender name in Semaphore dashboard
    ]);
    $ch = curl_init('https://api.semaphore.co/api/v4/messages');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        15);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}
