<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
$admin = currentAdmin();
$pdo   = getDB();
require_once __DIR__ . '/../includes/smart_inventory.php';

// City Health (super_admin) gets view-only — they influence inventory via requisition approvals, not direct edits
if (isSuperAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    die(json_encode(['error' => 'City Health cannot directly edit station inventory.']));
}

// Harden station_id: if session is stale or null, re-fetch from DB
$assignedStationId = null;
if (isBarangayAdmin()) {
    $assignedStationId = (int)$admin['station_id'];
    if (!$assignedStationId) {
        // Fallback: re-query from DB using the admin id stored in session
        $stFetch = $pdo->prepare("SELECT station_id FROM admins WHERE id = ?");
        $stFetch->execute([$admin['id']]);
        $assignedStationId = (int)$stFetch->fetchColumn();
        // Also update the session so future loads are fast
        $_SESSION['admin_station_id'] = $assignedStationId;
    }
}

$msg  = '';
$errs = [];

// Fetch assigned station name
$assignedStationName = '';
if ($assignedStationId) {
    $stmt = $pdo->prepare("SELECT barangay_name FROM health_stations WHERE id = ?");
    $stmt->execute([$assignedStationId]);
    $assignedStationName = $stmt->fetchColumn();
}

// ── Handle POST (barangay_admin only) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isBarangayAdmin()) {

    if (isset($_POST['action']) && $_POST['action'] === 'single_add') {
        $medName  = trim($_POST['medicine_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $qty      = (int)($_POST['quantity'] ?? 0);
        $batchNo  = trim($_POST['batch_no'] ?? '');
        $expiry   = trim($_POST['expiration_date'] ?? '');

        if ($medName === '' || $qty < 0) {
            $errs[] = 'Pakisagutan ang lahat ng kinakailangang field.';
        } elseif ($expiry !== '' && strtotime($expiry) === false) {
            $errs[] = 'Invalid expiration date.';
        } else {
            // Upsert medicine
            $stmt = $pdo->prepare("INSERT INTO medicines (name, category) VALUES (?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
            $stmt->execute([$medName, $category ?: null]);
            $medicineId = (int)$pdo->lastInsertId();
            if (!$medicineId) {
                $stmt = $pdo->prepare("SELECT id FROM medicines WHERE name = ?");
                $stmt->execute([$medName]);
                $medicineId = (int)$stmt->fetchColumn();
            }

            // Get quantity before
            $stmtOld = $pdo->prepare("SELECT quantity FROM inventory WHERE station_id = ? AND medicine_id = ?");
            $stmtOld->execute([$assignedStationId, $medicineId]);
            $qtyBefore = (int)($stmtOld->fetchColumn() ?: 0);

            $pdo->prepare("
                INSERT INTO inventory (station_id, medicine_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    quantity = quantity + VALUES(quantity)
            ")
                ->execute([$assignedStationId, $medicineId, $qty]);

            $inventoryId = (int)$pdo->lastInsertId();
            if (!$inventoryId) {
                $stmtInv = $pdo->prepare("SELECT id FROM inventory WHERE station_id = ? AND medicine_id = ?");
                $stmtInv->execute([$assignedStationId, $medicineId]);
                $inventoryId = (int)$stmtInv->fetchColumn();
            }

            $stmtAfter = $pdo->prepare("SELECT quantity FROM inventory WHERE id = ?");
            $stmtAfter->execute([$inventoryId]);
            $qtyAfter = (int)$stmtAfter->fetchColumn();
            $statusAfter = $qtyAfter <= 0 ? 'out_of_stock' : ($qtyAfter <= 10 ? 'low_stock' : 'in_stock');
            $pdo->prepare("UPDATE inventory SET status = ? WHERE id = ?")->execute([$statusAfter, $inventoryId]);

            if ($qty > 0 && smartInventoryTableExists($pdo, 'inventory_batches')) {
                $pdo->prepare("
                    INSERT INTO inventory_batches
                        (inventory_id, medicine_id, station_id, batch_no, quantity, expiration_date, received_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ")->execute([
                    $inventoryId,
                    $medicineId,
                    $assignedStationId,
                    $batchNo ?: null,
                    $qty,
                    $expiry ?: null
                ]);
            }

            $broadcastCount = smartInventoryMaybeBroadcastRestock($pdo, $medicineId, $assignedStationId, $qtyBefore, $qtyAfter);
            smartInventoryNotifyLowForecast($pdo, $medicineId, $assignedStationId, 7);

            logActivity(
                'stock_added',
                "{$admin['full_name']} added {$qty} units of {$medName} to {$assignedStationName}.",
                $assignedStationId, $qtyBefore, $qtyAfter, $medicineId, 'inventory'
            );
            $msg = 'Stock allocation successfully saved.';
            if ($broadcastCount > 0) {
                $msg .= " {$broadcastCount} subscriber notification(s) queued.";
            }
        }

    } elseif (isset($_FILES['stock_file'])) {
        $file = $_FILES['stock_file'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $handle = fopen($file['tmp_name'], 'r');
            $header = fgetcsv($handle);
            $headerClean   = array_map('trim', array_map('strtolower', $header));
            $expectedClean = ['medicine_name', 'category', 'quantity'];
            $expectedBatchClean = ['medicine_name', 'category', 'quantity', 'batch_no', 'expiration_date'];

            if ($headerClean !== $expectedClean && $headerClean !== $expectedBatchClean) {
                $errs[] = 'CSV structure mismatch! Expected columns: medicine_name, category, quantity, batch_no, expiration_date';
            } else {
                $count = 0;
                $broadcastTotal = 0;
                $pdo->beginTransaction();
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) < 3) continue;
                    $medName = trim($row[0] ?? '');
                    $category = trim($row[1] ?? '');
                    $qty = trim($row[2] ?? 0);
                    $batchNo = trim($row[3] ?? '');
                    $expiry = trim($row[4] ?? '');
                    $qty = (int)$qty;
                    if ($medName === '' || $qty < 0) continue;
                    if ($expiry !== '' && strtotime($expiry) === false) continue;

                    $stmt = $pdo->prepare("INSERT INTO medicines (name, category) VALUES (?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
                    $stmt->execute([$medName, $category ?: null]);
                    $medicineId = (int)$pdo->lastInsertId();
                    if (!$medicineId) {
                        $stmt = $pdo->prepare("SELECT id FROM medicines WHERE name = ?");
                        $stmt->execute([$medName]);
                        $medicineId = (int)$stmt->fetchColumn();
                    }

                    $stmtOld = $pdo->prepare("SELECT quantity FROM inventory WHERE station_id = ? AND medicine_id = ?");
                    $stmtOld->execute([$assignedStationId, $medicineId]);
                    $qtyBefore = (int)($stmtOld->fetchColumn() ?: 0);

                    $pdo->prepare("
                        INSERT INTO inventory (station_id, medicine_id, quantity)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            id = LAST_INSERT_ID(id),
                            quantity = quantity + VALUES(quantity)
                    ")
                        ->execute([$assignedStationId, $medicineId, $qty]);

                    $inventoryId = (int)$pdo->lastInsertId();
                    if (!$inventoryId) {
                        $stmtInv = $pdo->prepare("SELECT id FROM inventory WHERE station_id = ? AND medicine_id = ?");
                        $stmtInv->execute([$assignedStationId, $medicineId]);
                        $inventoryId = (int)$stmtInv->fetchColumn();
                    }
                    $qtyAfter = $qtyBefore + $qty;
                    $statusAfter = $qtyAfter <= 0 ? 'out_of_stock' : ($qtyAfter <= 10 ? 'low_stock' : 'in_stock');
                    $pdo->prepare("UPDATE inventory SET status = ? WHERE id = ?")->execute([$statusAfter, $inventoryId]);

                    if ($qty > 0 && smartInventoryTableExists($pdo, 'inventory_batches')) {
                        $pdo->prepare("
                            INSERT INTO inventory_batches
                                (inventory_id, medicine_id, station_id, batch_no, quantity, expiration_date, received_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ")->execute([
                            $inventoryId,
                            $medicineId,
                            $assignedStationId,
                            $batchNo ?: null,
                            $qty,
                            $expiry ?: null
                        ]);
                    }

                    $broadcastTotal += smartInventoryMaybeBroadcastRestock($pdo, $medicineId, $assignedStationId, $qtyBefore, $qtyAfter);
                    smartInventoryNotifyLowForecast($pdo, $medicineId, $assignedStationId, 7);

                    logActivity('stock_added_bulk',
                        "Bulk import: {$qty} units of {$medName} added to {$assignedStationName}.",
                        $assignedStationId, $qtyBefore, $qtyAfter, $medicineId, 'inventory');
                    $count++;
                }
                $pdo->commit();
                fclose($handle);
                $msg = "Bulk upload complete: {$count} medicines processed.";
                if ($broadcastTotal > 0) {
                    $msg .= " {$broadcastTotal} subscriber notification(s) queued.";
                }
            }
        }
    }
}

// ── Fetch inventory (scoped by role) ────────────────────────────
if (isSuperAdmin()) {
    $allStations = $pdo->query("SELECT id, barangay_name FROM health_stations ORDER BY barangay_name")->fetchAll();
    $inventory   = $pdo->query("
        SELECT i.*, m.name AS medicine_name, m.category, h.barangay_name
        FROM inventory i
        JOIN medicines m ON m.id = i.medicine_id
        JOIN health_stations h ON h.id = i.station_id
        ORDER BY h.barangay_name, m.name
    ")->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT i.*, m.name AS medicine_name, m.category, h.barangay_name
        FROM inventory i
        JOIN medicines m ON m.id = i.medicine_id
        JOIN health_stations h ON h.id = i.station_id
        WHERE i.station_id = ?
        ORDER BY m.name
    ");
    $stmt->execute([$assignedStationId]);
    $inventory = $stmt->fetchAll();
}

$expiringItems = smartInventoryGetExpiring($pdo, isSuperAdmin() ? null : $assignedStationId, 60);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Inventory — BayaniServe</title>
<?php include __DIR__ . '/includes/layout_head.php'; ?>
<style>
.grid-forms { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
.form-group { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
.form-group label { font-size: 12px; font-weight: 600; color: #444; }
.form-group input, .form-group select { padding: 8px 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
.inline-btn { padding: 9px 16px; background: #185FA5; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 13px; }
.inline-btn:hover { background: #0c447c; }
.alert-success { background: #dcfce7; color: #14532d; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
.alert-danger { background: #fee2e2; color: #7f1d1d; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
.station-badge { background: #e0f2fe; color: #0369a1; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; display: inline-block; margin-bottom: 10px; }
.readonly-notice { background: #fef9c3; border: 1px solid #fde047; color: #713f12; padding: 14px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; }
.expiry-alert { background:#fff7ed; border:1px solid #fed7aa; color:#7c2d12; padding:14px 16px; border-radius:8px; margin-bottom:18px; font-size:13px; }
.expiry-alert table { width:100%; border-collapse:collapse; margin-top:10px; }
.expiry-alert th, .expiry-alert td { padding:7px 6px; border-bottom:1px solid #fed7aa; text-align:left; }
.expiry-alert th { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#9a3412; }
.expiry-chip { display:inline-block; padding:3px 8px; border-radius:20px; background:#ffedd5; color:#9a3412; font-weight:700; font-size:12px; }
</style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
    <div class="topbar">
        <div>
            <div class="tb-title">Inventory Management</div>
            <div class="tb-sub">
                Logged in as: <b><?= htmlspecialchars($admin['full_name']) ?></b>
                <?= isSuperAdmin() ? '— City Health (View Only)' : '— Managing <i class="bi bi-geo-alt-fill text-muted"></i> ' . htmlspecialchars($assignedStationName) ?>
            </div>
        </div>
    </div>
    <div class="content">

        <?php if ($msg): ?><div class="alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($errs): ?>
            <div class="alert-danger"><ul><?php foreach ($errs as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <?php if (isSuperAdmin()): ?>
        <div class="readonly-notice">
            <i class="bi bi-exclamation-triangle-fill"></i> <strong>City Health — View Only.</strong> Direct stock edits are reserved for Barangay Admins. To add supply, approve a Requisition submitted by the barangay — they will then confirm delivery, which updates the stock automatically.
        </div>
        <?php else: ?>
        <?php if (!empty($expiringItems)): ?>
        <div class="expiry-alert">
            <strong>Expiration Alert:</strong> The following batches expire within 60 days. FIFO dispensing will prioritize the earliest expiration date first.
            <table>
                <tr><th>Medicine</th><th>Batch</th><th>Quantity</th><th>Expiration</th><th>Days Left</th></tr>
                <?php foreach (array_slice($expiringItems, 0, 8) as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['medicine_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($item['batch_no'] ?? '—') ?></td>
                    <td><?= number_format((int)($item['quantity'] ?? 0)) ?></td>
                    <td><?= htmlspecialchars($item['expiration_date'] ?? '—') ?></td>
                    <td><span class="expiry-chip"><?= (int)($item['days_until_expiry'] ?? 0) ?> days</span></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
        <div class="grid-forms">
            <div class="card">
                <div class="card-h">Add / Update Single Medicine Stock</div>
                <form method="POST">
                    <input type="hidden" name="action" value="single_add">
                    <div class="station-badge"><i class="bi bi-geo-alt-fill"></i> Station: <?= htmlspecialchars($assignedStationName) ?> (Locked)</div>
                    <div class="form-group">
                        <label>Medicine Name *</label>
                        <input type="text" name="medicine_name" placeholder="e.g. Paracetamol 500mg" required>
                    </div>
                    <div class="form-group">
                        <label>Category (Optional)</label>
                        <input type="text" name="category" placeholder="e.g. Analgesic">
                    </div>
                    <div class="form-group">
                        <label>Quantity to Add *</label>
                        <input type="number" name="quantity" min="0" value="0" required>
                    </div>
                    <div class="form-group">
                        <label>Batch Number (Optional)</label>
                        <input type="text" name="batch_no" placeholder="e.g. PCM-2026-01">
                    </div>
                    <div class="form-group">
                        <label>Expiration Date (Optional)</label>
                        <input type="date" name="expiration_date">
                    </div>
                    <button type="submit" class="inline-btn">Save Stock Allocation</button>
                </form>
            </div>

            <div class="card" style="display:flex; flex-direction:column; justify-content:space-between;">
                <div>
                    <div class="card-h">Bulk Import CSV File</div>
                    <p style="font-size:13px;color:#666;margin-bottom:12px;line-height:1.4;">
                        Upload your stock spreadsheet. Rows merge safely into your existing inventory.
                    </p>
                    <div style="background:#f8fafc;border:1px dashed #cbd5e1;padding:12px;border-radius:6px;font-size:12px;color:#475569;margin-bottom:12px;">
                        Required columns: <code>medicine_name, category, quantity</code>
                        <br>Optional smart inventory columns: <code>batch_no, expiration_date</code>
                        <br>Station is automatically set to your own.
                    </div>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <input type="file" name="stock_file" accept=".csv" required>
                    </div>
                    <button type="submit" class="inline-btn" style="background:#475569;">Upload & Parse CSV</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-h"><?= isSuperAdmin() ? 'All Stations — Stock Overview' : 'Your Station Stock Records' ?></div>
            <table class="tbl">
                <tr><th>Medicine</th><th>Category</th><th>Health Station</th><th>Stock Units</th><th>Status</th></tr>
                <?php if (empty($inventory)): ?>
                    <tr><td colspan="5" style="text-align:center;color:#999;padding:20px;">No stock on record.</td></tr>
                <?php endif; ?>
                <?php foreach ($inventory as $inv): ?>
                <tr>
                    <td><b><?= htmlspecialchars($inv['medicine_name']) ?></b></td>
                    <td><?= htmlspecialchars($inv['category'] ?? '—') ?></td>
                    <td><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($inv['barangay_name']) ?></td>
                    <td><?= number_format($inv['quantity']) ?> units</td>
                    <td>
                        <span class="badge <?= $inv['status'] === 'in_stock' ? 'bg-success' : ($inv['status'] === 'low_stock' ? 'ba' : 'br') ?>">
                            <?= str_replace('_', ' ', $inv['status']) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

    </div>
</main>
</body>
</html>
