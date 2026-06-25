<?php
/**
 * Smart inventory helpers for BayaniServe.
 *
 * These functions are intentionally defensive so they can be included by
 * existing admin pages while the database is being migrated in stages.
 */

function smartInventoryTableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
        ");
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function smartInventoryColumnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
        ");
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function smartInventoryCreateNotification(
    PDO $pdo,
    string $recipientRole,
    string $title,
    string $message,
    string $severity = 'info',
    ?int $stationId = null,
    ?string $sourceType = null,
    ?int $sourceId = null
): void {
    if (!smartInventoryTableExists($pdo, 'dashboard_notifications')) {
        return;
    }

    $pdo->prepare("
        INSERT INTO dashboard_notifications
            (recipient_role, station_id, title, message, severity, source_type, source_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $recipientRole,
        $stationId,
        $title,
        $message,
        $severity,
        $sourceType,
        $sourceId
    ]);
}

function smartInventoryGetExpiring(PDO $pdo, ?int $stationId = null, int $days = 60): array {
    if (smartInventoryTableExists($pdo, 'inventory_batches')) {
        $where = "b.quantity > 0 AND b.expiration_date IS NOT NULL
                  AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)";
        $params = [$days];

        if ($stationId !== null) {
            $where .= " AND b.station_id = ?";
            $params[] = $stationId;
        }

        $stmt = $pdo->prepare("
            SELECT
                b.id AS batch_id,
                b.batch_no,
                b.quantity,
                b.expiration_date,
                DATEDIFF(b.expiration_date, CURDATE()) AS days_until_expiry,
                m.name AS medicine_name,
                hs.barangay_name
            FROM inventory_batches b
            JOIN medicines m ON m.id = b.medicine_id
            LEFT JOIN health_stations hs ON hs.id = b.station_id
            WHERE {$where}
            ORDER BY b.expiration_date ASC, m.name ASC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!smartInventoryColumnExists($pdo, 'inventory', 'expiration_date')) {
        return [];
    }

    $where = "i.quantity > 0 AND i.expiration_date IS NOT NULL
              AND i.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)";
    $params = [$days];

    if ($stationId !== null && smartInventoryColumnExists($pdo, 'inventory', 'station_id')) {
        $where .= " AND i.station_id = ?";
        $params[] = $stationId;
    }

    $stmt = $pdo->prepare("
        SELECT
            i.id AS inventory_id,
            i.quantity,
            i.expiration_date,
            DATEDIFF(i.expiration_date, CURDATE()) AS days_until_expiry,
            m.name AS medicine_name,
            hs.barangay_name
        FROM inventory i
        JOIN medicines m ON m.id = i.medicine_id
        LEFT JOIN health_stations hs ON hs.id = i.station_id
        WHERE {$where}
        ORDER BY i.expiration_date ASC, m.name ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function smartInventoryFindFifoBatches(PDO $pdo, int $medicineId, int $stationId, int $quantityNeeded): array {
    if (!smartInventoryTableExists($pdo, 'inventory_batches')) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT id, batch_no, quantity, expiration_date
        FROM inventory_batches
        WHERE medicine_id = ?
          AND station_id = ?
          AND quantity > 0
        ORDER BY
          CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END,
          expiration_date ASC,
          received_at ASC,
          id ASC
    ");
    $stmt->execute([$medicineId, $stationId]);

    $remaining = $quantityNeeded;
    $routes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $batch) {
        if ($remaining <= 0) {
            break;
        }

        $take = min($remaining, (int) $batch['quantity']);
        $routes[] = [
            'batch_id' => (int) $batch['id'],
            'batch_no' => $batch['batch_no'],
            'expiration_date' => $batch['expiration_date'],
            'quantity' => $take
        ];
        $remaining -= $take;
    }

    return $routes;
}

function smartInventoryApplyFifoDeduction(PDO $pdo, int $medicineId, int $stationId, int $quantityNeeded): array {
    $routes = smartInventoryFindFifoBatches($pdo, $medicineId, $stationId, $quantityNeeded);
    $allocated = array_sum(array_column($routes, 'quantity'));

    if ($allocated < $quantityNeeded) {
        throw new RuntimeException('Not enough batch stock available for FIFO allocation.');
    }

    foreach ($routes as $route) {
        $pdo->prepare("
            UPDATE inventory_batches
            SET quantity = quantity - ?
            WHERE id = ? AND quantity >= ?
        ")->execute([$route['quantity'], $route['batch_id'], $route['quantity']]);
    }

    $summaryStmt = $pdo->prepare("
        SELECT id, quantity
        FROM inventory
        WHERE medicine_id = ? AND station_id = ?
        LIMIT 1
    ");
    $summaryStmt->execute([$medicineId, $stationId]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    if ($summary) {
        $newQuantity = max(((int) $summary['quantity']) - $quantityNeeded, 0);
        $newStatus = $newQuantity <= 0 ? 'out_of_stock' : ($newQuantity <= 10 ? 'low_stock' : 'in_stock');
        $pdo->prepare("
            UPDATE inventory
            SET quantity = ?, status = ?
            WHERE id = ?
        ")->execute([$newQuantity, $newStatus, (int) $summary['id']]);
    }

    return $routes;
}

function smartInventoryMaybeBroadcastRestock(
    PDO $pdo,
    int $medicineId,
    int $stationId,
    int $oldQuantity,
    int $newQuantity
): int {
    if ($newQuantity <= $oldQuantity || !smartInventoryTableExists($pdo, 'resident_subscriptions')) {
        return 0;
    }

    $categorySelect = smartInventoryColumnExists($pdo, 'medicines', 'category') ? 'category' : "'' AS category";
    $stmt = $pdo->prepare("
        SELECT name, {$categorySelect}
        FROM medicines
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$medicineId]);
    $medicine = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$medicine) {
        return 0;
    }

    $category = trim((string) ($medicine['category'] ?? ''));
    $params = [$stationId];
    $categorySql = '';
    if ($category !== '') {
        $categorySql = " OR LOWER(category) = LOWER(?)";
        $params[] = $category;
    }
    $params[] = $medicine['name'];

    $stmt = $pdo->prepare("
        SELECT resident_name, mobile_number
        FROM resident_subscriptions
        WHERE is_active = 1
          AND (station_id IS NULL OR station_id = ?)
          AND (
              LOWER(category) = 'all'
              {$categorySql}
              OR LOWER(category) = LOWER(?)
          )
    ");
    $stmt->execute($params);
    $subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stationStmt = $pdo->prepare("SELECT barangay_name FROM health_stations WHERE id = ? LIMIT 1");
    $stationStmt->execute([$stationId]);
    $stationName = (string) ($stationStmt->fetchColumn() ?: 'your BHS');

    $sent = 0;
    foreach ($subscribers as $subscriber) {
        $number = trim((string) $subscriber['mobile_number']);
        if ($number === '' || !smartInventoryTableExists($pdo, 'sms_outbox')) {
            continue;
        }

        $message =
            "BayaniServe: Available na ang {$medicine['name']} sa {$stationName}. " .
            "Bag-o nga stock: {$newQuantity}. Magkadto sa BHS para sa assessment.";

        $pdo->prepare("
            INSERT INTO sms_outbox (mobile_number, message, reference_type, reference_id)
            VALUES (?, ?, 'inventory_restock', ?)
        ")->execute([$number, $message, $medicineId]);
        $sent++;
    }

    return $sent;
}

function smartInventoryEstimateDaysRemaining(PDO $pdo, int $medicineId, ?int $stationId = null, int $lookbackDays = 90): ?array {
    $stockSql = "SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE medicine_id = ?";
    $stockParams = [$medicineId];
    if ($stationId !== null && smartInventoryColumnExists($pdo, 'inventory', 'station_id')) {
        $stockSql .= " AND station_id = ?";
        $stockParams[] = $stationId;
    }

    $stockStmt = $pdo->prepare($stockSql);
    $stockStmt->execute($stockParams);
    $currentStock = (int) $stockStmt->fetchColumn();

    $dailyUsage = 0.0;
    if (smartInventoryTableExists($pdo, 'emergency_distributions')) {
        $dateColumn = smartInventoryColumnExists($pdo, 'emergency_distributions', 'distributed_at')
            ? 'distributed_at'
            : (smartInventoryColumnExists($pdo, 'emergency_distributions', 'created_at') ? 'created_at' : null);

        $quantityColumn = smartInventoryColumnExists($pdo, 'emergency_distributions', 'quantity')
            ? 'quantity'
            : (smartInventoryColumnExists($pdo, 'emergency_distributions', 'quantity_distributed') ? 'quantity_distributed' : null);

        if ($dateColumn !== null && $quantityColumn !== null && smartInventoryColumnExists($pdo, 'emergency_distributions', 'medicine_id')) {
            $where = "medicine_id = ? AND {$dateColumn} >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
            $params = [$medicineId, $lookbackDays];
            if ($stationId !== null && smartInventoryColumnExists($pdo, 'emergency_distributions', 'station_id')) {
                $where .= " AND station_id = ?";
                $params[] = $stationId;
            }

            $stmt = $pdo->prepare("SELECT COALESCE(SUM({$quantityColumn}), 0) FROM emergency_distributions WHERE {$where}");
            $stmt->execute($params);
            $used = (float) $stmt->fetchColumn();
            $dailyUsage = $used / max($lookbackDays, 1);
        }
    }

    if ($dailyUsage <= 0.0) {
        return [
            'current_stock' => $currentStock,
            'daily_usage' => 0.0,
            'days_remaining' => null,
            'severity' => 'info'
        ];
    }

    $daysRemaining = (int) floor($currentStock / $dailyUsage);
    $severity = $daysRemaining <= 7 ? 'critical' : ($daysRemaining <= 30 ? 'warning' : 'ok');

    return [
        'current_stock' => $currentStock,
        'daily_usage' => round($dailyUsage, 2),
        'days_remaining' => $daysRemaining,
        'severity' => $severity
    ];
}

function smartInventoryNotifyLowForecast(PDO $pdo, int $medicineId, ?int $stationId = null, int $thresholdDays = 7): void {
    $estimate = smartInventoryEstimateDaysRemaining($pdo, $medicineId, $stationId);
    if (!$estimate || $estimate['days_remaining'] === null || $estimate['days_remaining'] > $thresholdDays) {
        return;
    }

    $stmt = $pdo->prepare("SELECT name FROM medicines WHERE id = ? LIMIT 1");
    $stmt->execute([$medicineId]);
    $medicineName = (string) ($stmt->fetchColumn() ?: 'Medicine');

    $title = "Stock depletion alert: {$medicineName}";
    $message =
        "{$medicineName} has about {$estimate['days_remaining']} day(s) of stock remaining " .
        "based on recent distribution trends. Please prepare a barangay requisition or transfer.";

    smartInventoryCreateNotification($pdo, 'cityhealth', $title, $message, 'warning', $stationId, 'stock_forecast', $medicineId);
    smartInventoryCreateNotification($pdo, 'superadmin', $title, $message, 'warning', $stationId, 'stock_forecast', $medicineId);
}
