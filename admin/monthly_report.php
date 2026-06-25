<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
$admin = currentAdmin();
$pdo   = getDB();

$assignedStationId = isBarangayAdmin() ? (int)$admin['station_id'] : null;

// Harden station_id in session if missing
if (isBarangayAdmin() && !$assignedStationId) {
    $stFetch = $pdo->prepare("SELECT station_id FROM admins WHERE id = ?");
    $stFetch->execute([$admin['id']]);
    $assignedStationId = (int)$stFetch->fetchColumn();
    $_SESSION['admin_station_id'] = $assignedStationId;
}

// ── AUTOMATIC MONTHLY SNAPSHOT GENERATION ─────────────────────────
// Automatically update/refresh snapshot data for the active current calendar month.
// This executes silently on page load without requiring manual button clicks.
$currentMonth = date('Y-m-01');

try {
    if (isSuperAdmin()) {
        // Super Admin: automatically refresh all stations' snapshots for the current month
        $pdo->prepare("DELETE FROM monthly_inventory_snapshots WHERE snapshot_month = ?")->execute([$currentMonth]);
        
        $allInv = $pdo->query("SELECT station_id, medicine_id, quantity FROM inventory")->fetchAll();
        foreach ($allInv as $row) {
            $pdo->prepare("
                INSERT INTO monthly_inventory_snapshots (station_id, medicine_id, snapshot_month, quantity)
                VALUES (?, ?, ?, ?)
            ")->execute([$row['station_id'], $row['medicine_id'], $currentMonth, $row['quantity']]);
        }
    } else {
        // Barangay Admin: automatically refresh only their station's snapshot for the current month
        $pdo->prepare("DELETE FROM monthly_inventory_snapshots WHERE station_id = ? AND snapshot_month = ?")->execute([$assignedStationId, $currentMonth]);
        
        $snapshot = $pdo->prepare("
            SELECT i.medicine_id, i.quantity
            FROM inventory i
            WHERE i.station_id = ?
        ");
        $snapshot->execute([$assignedStationId]);
        $rows = $snapshot->fetchAll();
        
        foreach ($rows as $row) {
            $pdo->prepare("
                INSERT INTO monthly_inventory_snapshots (station_id, medicine_id, snapshot_month, quantity)
                VALUES (?, ?, ?, ?)
            ")->execute([$assignedStationId, $row['medicine_id'], $currentMonth, $row['quantity']]);
        }
    }
} catch (Exception $e) {
    // Fail silently to keep the report page working under all conditions
}

// ── Fetch snapshots ───────────────────────────────────────────────
if (isSuperAdmin()) {
    $snapshots = $pdo->query("
        SELECT s.*, m.name AS medicine_name, h.barangay_name
        FROM monthly_inventory_snapshots s
        JOIN medicines m       ON m.id = s.medicine_id
        JOIN health_stations h ON h.id = s.station_id
        ORDER BY s.snapshot_month DESC, h.barangay_name, m.name
    ")->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT s.*, m.name AS medicine_name, h.barangay_name
        FROM monthly_inventory_snapshots s
        JOIN medicines m       ON m.id = s.medicine_id
        JOIN health_stations h ON h.id = s.station_id
        WHERE s.station_id = ?
        ORDER BY s.snapshot_month DESC, m.name
    ");
    $stmt->execute([$assignedStationId]);
    $snapshots = $stmt->fetchAll();
}

// Group by month for display
$grouped = [];
foreach ($snapshots as $s) {
    $monthLabel = date('F Y', strtotime($s['snapshot_month']));
    $grouped[$monthLabel][] = $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Monthly Inventory Report — BayaniServe</title>
<?php include __DIR__ . '/includes/layout_head.php'; ?>
<style>
.month-group { margin-bottom:24px; }
.month-title { font-size:15px; font-weight:700; color:#185FA5; margin-bottom:8px; }
.info-notice { background:#e0f2fe; border:1px solid #bae6fd; color:#0369a1; padding:14px 16px; border-radius:8px; font-size:13px; margin-bottom:20px; line-height:1.4; }
</style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
    <div class="topbar">
        <div>
            <div class="tb-title">Monthly Inventory Report</div>
            <div class="tb-sub">Track trends over time — automatically generated and updated</div>
        </div>
    </div>
    <div class="content">

        <div class="info-notice">
            <i class="bi bi-info-circle-fill"></i> <strong>Auto-Snapshot System:</strong> Inventory snapshot reports are automatically generated and kept up-to-date in real-time. Once the calendar month transitions, the snapshot freezes permanently so that demand trends and historical metrics are safely preserved.
        </div>

        <?php if (empty($grouped)): ?>
            <div class="card">
                <p style="color:#888;font-size:13px;">No snapshot data has been saved yet.</p>
            </div>
        <?php else: ?>
            <?php foreach ($grouped as $monthLabel => $rows): ?>
            <div class="card month-group">
                <div class="month-title"><i class="bi bi-calendar-event"></i> <?= htmlspecialchars($monthLabel) ?></div>
                <table class="tbl">
                    <tr><th>Medicine</th><th>Station</th><th>Quantity (snapshot value)</th></tr>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><b><?= htmlspecialchars($r['medicine_name']) ?></b></td>
                        <td><i class="bi bi-geo-alt-fill text-muted"></i> <?= htmlspecialchars($r['barangay_name']) ?></td>
                        <td><?= number_format($r['quantity']) ?> units</td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</main>
</body>
</html>
