<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();

$admin = currentAdmin();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/smart_inventory.php';

$pdo = getDB();
$stationId = isset($admin['station_id']) ? (int) $admin['station_id'] : null;
$role = strtolower((string) ($admin['role'] ?? 'admin'));
if (in_array($role, ['superadmin', 'cityhealth'], true)) {
    $stationId = null;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'notify_forecasts') {
    $stmt = $pdo->query("SELECT DISTINCT medicine_id FROM inventory WHERE quantity >= 0");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $medicineId) {
        smartInventoryNotifyLowForecast($pdo, (int) $medicineId, $stationId, 7);
    }
    $message = 'Forecast notifications were drafted for city health/superadmin when stock is projected to run out within 7 days.';
}

$expiringItems = smartInventoryGetExpiring($pdo, $stationId, 60);

$stockStmt = $pdo->prepare("
    SELECT DISTINCT m.id, m.name, hs.barangay_name
    FROM inventory i
    JOIN medicines m ON m.id = i.medicine_id
    LEFT JOIN health_stations hs ON hs.id = i.station_id
    WHERE (? IS NULL OR i.station_id = ?)
    ORDER BY m.name ASC, hs.barangay_name ASC
");
$stockStmt->execute([$stationId, $stationId]);
$forecastRows = [];
foreach ($stockStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $estimate = smartInventoryEstimateDaysRemaining($pdo, (int) $row['id'], $stationId, 90);
    $forecastRows[] = [
        'medicine_name' => $row['name'],
        'barangay_name' => $row['barangay_name'],
        'estimate' => $estimate
    ];
}

function smartInventoryBadgeClass(string $severity): string {
    return match($severity) {
        'critical' => 'badge-critical',
        'warning' => 'badge-warning',
        'ok' => 'badge-ok',
        default => 'badge-info'
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Smart Inventory - BayaniServe</title>
    <style>
        :root {
            --ink: #17202a;
            --muted: #5f6b7a;
            --line: #d8dee6;
            --panel: #ffffff;
            --bg: #f5f7fa;
            --blue: #1f6feb;
            --green: #1f8f4d;
            --amber: #b76e00;
            --red: #b42318;
        }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: Arial, Helvetica, sans-serif;
        }
        main {
            max-width: 1180px;
            margin: 0 auto;
            padding: 24px;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
        }
        h1 {
            margin: 0;
            font-size: 28px;
            letter-spacing: 0;
        }
        h2 {
            margin: 0 0 12px;
            font-size: 18px;
            letter-spacing: 0;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 18px;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 18px;
        }
        .notice {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid #9cc2ff;
            background: #eef5ff;
            color: #174680;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th,
        td {
            padding: 10px 8px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
        }
        th {
            font-size: 13px;
            color: var(--muted);
            font-weight: 700;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 0 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .badge-critical {
            color: #ffffff;
            background: var(--red);
        }
        .badge-warning {
            color: #3d2500;
            background: #ffd681;
        }
        .badge-ok {
            color: #ffffff;
            background: var(--green);
        }
        .badge-info {
            color: #143f73;
            background: #dbeafe;
        }
        .button {
            border: 0;
            border-radius: 8px;
            background: var(--blue);
            color: #ffffff;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .empty {
            color: var(--muted);
            padding: 10px 0;
        }
        @media (max-width: 720px) {
            main {
                padding: 16px;
            }
            .topbar {
                align-items: stretch;
                flex-direction: column;
            }
            table,
            thead,
            tbody,
            tr,
            th,
            td {
                display: block;
            }
            thead {
                display: none;
            }
            td {
                border-bottom: 0;
                padding: 6px 0;
            }
            tr {
                border-bottom: 1px solid var(--line);
                padding: 10px 0;
            }
            td::before {
                content: attr(data-label);
                display: block;
                color: var(--muted);
                font-size: 12px;
                font-weight: 700;
            }
        }
    </style>
</head>
<body>
<main>
    <div class="topbar">
        <div>
            <h1>Smart Inventory</h1>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="notify_forecasts">
            <button class="button" type="submit">Draft Dashboard Alerts</button>
        </form>
    </div>

    <?php if ($message): ?>
        <div class="notice"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="grid">
        <section class="panel">
            <h2>Expiring Within 60 Days</h2>
            <?php if (empty($expiringItems)): ?>
                <div class="empty">No expiring stock found.</div>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>Barangay</th>
                        <th>Batch</th>
                        <th>Quantity</th>
                        <th>Expiration</th>
                        <th>Days</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($expiringItems as $item): ?>
                        <tr>
                            <td data-label="Medicine"><?= htmlspecialchars($item['medicine_name'] ?? '') ?></td>
                            <td data-label="Barangay"><?= htmlspecialchars($item['barangay_name'] ?? 'All') ?></td>
                            <td data-label="Batch"><?= htmlspecialchars($item['batch_no'] ?? '-') ?></td>
                            <td data-label="Quantity"><?= (int) ($item['quantity'] ?? 0) ?></td>
                            <td data-label="Expiration"><?= htmlspecialchars($item['expiration_date'] ?? '') ?></td>
                            <td data-label="Days">
                                <span class="badge <?= ((int) ($item['days_until_expiry'] ?? 0) <= 30) ? 'badge-critical' : 'badge-warning' ?>">
                                    <?= (int) ($item['days_until_expiry'] ?? 0) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Estimated Days of Stock Remaining</h2>
            <?php if (empty($forecastRows)): ?>
                <div class="empty">No inventory records found.</div>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>Barangay</th>
                        <th>Current Stock</th>
                        <th>Daily Use</th>
                        <th>DoSR</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($forecastRows as $row): ?>
                        <?php
                        $estimate = $row['estimate'] ?? null;
                        $severity = $estimate['severity'] ?? 'info';
                        $days = $estimate['days_remaining'] ?? null;
                        ?>
                        <tr>
                            <td data-label="Medicine"><?= htmlspecialchars($row['medicine_name']) ?></td>
                            <td data-label="Barangay"><?= htmlspecialchars($row['barangay_name'] ?? 'All') ?></td>
                            <td data-label="Current Stock"><?= (int) ($estimate['current_stock'] ?? 0) ?></td>
                            <td data-label="Daily Use"><?= htmlspecialchars((string) ($estimate['daily_usage'] ?? 0)) ?></td>
                            <td data-label="DoSR"><?= $days === null ? 'No trend yet' : ((int) $days . ' days') ?></td>
                            <td data-label="Status">
                                <span class="badge <?= smartInventoryBadgeClass($severity) ?>">
                                    <?= htmlspecialchars(strtoupper($severity)) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
