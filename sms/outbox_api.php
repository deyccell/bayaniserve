<?php
/**
 * BayaniServe — Online SMS Outbox API
 *
 * Lives on the ONLINE SERVER.
 * GET  → returns pending outbound SMS for local PC to send
 * POST → local PC marks SMS as sent or failed
 *
 * URL: https://yourdomain.com/bayani/sms/outbox_api.php
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

define('SMS_API_SECRET', 'change_this_to_a_random_secret');  // must match local PC

$secret = $_GET['secret'] ?? $_POST['secret'] ?? '';
if ($secret !== SMS_API_SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$pdo = getDB();

// ── GET: return pending outbox ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query("
        SELECT id, mobile_number, message
        FROM sms_outbox
        WHERE status = 'pending'
        ORDER BY created_at ASC
        LIMIT 20
    ");
    echo json_encode($stmt->fetchAll());
    exit;
}

// ── POST: mark as sent or failed ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $now = date('Y-m-d H:i:s');

    $sentIds = array_filter(explode(',', $_POST['sent_ids'] ?? ''));
    foreach ($sentIds as $id) {
        $id = (int)trim($id);
        if ($id) {
            $pdo->prepare("UPDATE sms_outbox SET status='sent', sent_at=? WHERE id=?")
                ->execute([$now, $id]);
            // Mirror to sms_log for the admin SMS log page
            $sms = $pdo->prepare("SELECT mobile_number, message FROM sms_outbox WHERE id=?");
            $sms->execute([$id]);
            $row = $sms->fetch();
            if ($row) {
                $pdo->prepare("INSERT INTO sms_log (direction, mobile_number, message, status) VALUES ('outbound', ?, ?, 'sent')")
                    ->execute([$row['mobile_number'], $row['message']]);
            }
        }
    }

    $failedIds = array_filter(explode(',', $_POST['failed_ids'] ?? ''));
    foreach ($failedIds as $id) {
        $id = (int)trim($id);
        if ($id) {
            $pdo->prepare("UPDATE sms_outbox SET status='failed' WHERE id=?")
                ->execute([$id]);
        }
    }

    echo json_encode(['status' => 'ok']);
    exit;
}
