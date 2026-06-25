<?php
/**
 * Shared announcement posting helpers for admin pages.
 */

function ensureSmsOutboxTable(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `sms_outbox` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `mobile_number` VARCHAR(20) NOT NULL,
              `message` TEXT NOT NULL,
              `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
              `reference_type` VARCHAR(50) DEFAULT NULL,
              `reference_id` INT DEFAULT NULL,
              `sent_at` DATETIME DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Exception $e) {
        // Do not block the announcement page if the table already exists differently.
    }
}

function postAnnouncement(PDO $pdo, int $adminId, string $title, string $message, ?int $targetStationId): array
{
    ensureSmsOutboxTable($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO announcements (title, message, target_station_id, sent_as_sms, posted_by)
        VALUES (?, ?, ?, 1, ?)
    ");
    $stmt->execute([$title, $message, $targetStationId, $adminId]);
    $announcementId = (int)$pdo->lastInsertId();

    if ($targetStationId === null) {
        $resStmt = $pdo->prepare("
            SELECT mobile_number
            FROM residents
            WHERE mobile_number IS NOT NULL AND mobile_number != ''
        ");
        $resStmt->execute();
    } else {
        $resStmt = $pdo->prepare("
            SELECT mobile_number
            FROM residents
            WHERE station_id = ? AND mobile_number IS NOT NULL AND mobile_number != ''
        ");
        $resStmt->execute([$targetStationId]);
    }

    $smsText = "ANNOUNCEMENT: {$title}\n{$message}";
    $smsInsert = $pdo->prepare("
        INSERT INTO sms_outbox (mobile_number, message, reference_type, reference_id)
        VALUES (?, ?, 'broadcast', ?)
    ");

    $queuedCount = 0;
    foreach ($resStmt->fetchAll(PDO::FETCH_COLUMN) as $mobile) {
        $smsInsert->execute([$mobile, $smsText, $announcementId]);
        $queuedCount++;
    }

    return [
        'announcement_id' => $announcementId,
        'queued_count' => $queuedCount,
    ];
}

function announcementTargetLabel(?int $targetStationId, array $stationNamesById = []): string
{
    if ($targetStationId === null) {
        return 'all barangays';
    }

    return $stationNamesById[$targetStationId] ?? "station ID {$targetStationId}";
}
