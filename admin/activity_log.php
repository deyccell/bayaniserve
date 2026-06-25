<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
$admin = currentAdmin();
$pdo   = getDB();

$assignedStationId = isBarangayAdmin() ? (int)$admin['station_id'] : null;

// Scoped by role
if (isSuperAdmin()) {
    $logs = $pdo->query("
        SELECT al.*, a.full_name AS admin_name, h.barangay_name
        FROM activity_log al
        LEFT JOIN admins a         ON a.id  = al.admin_id
        LEFT JOIN health_stations h ON h.id = al.station_id
        ORDER BY al.created_at DESC
        LIMIT 200
    ")->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT al.*, a.full_name AS admin_name, h.barangay_name
        FROM activity_log al
        LEFT JOIN admins a         ON a.id  = al.admin_id
        LEFT JOIN health_stations h ON h.id = al.station_id
        WHERE al.station_id = ?
        ORDER BY al.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$assignedStationId]);
    $logs = $stmt->fetchAll();
}

$typeIcons = [
    'stock_added'             => 'bi bi-box-seam text-primary',
    'stock_added_bulk'        => 'bi bi-box-seam text-primary',
    'reservation_approved'    => 'bi bi-check-circle-fill text-success',
    'reservation_declined'    => 'bi bi-x-circle-fill text-danger',
    'requisition_submitted'   => 'bi bi-clipboard-text text-warning',
    'requisition_approved'    => 'bi bi-check-circle-fill text-success',
    'requisition_partial'     => 'bi bi-exclamation-circle-fill text-warning',
    'requisition_rejected'    => 'bi bi-x-circle-fill text-danger',
    'requisition_delivered'   => 'bi bi-truck text-success',
    'account_created'         => 'bi bi-person-plus-fill text-info',
    'account_deactivated'     => 'bi bi-lock-fill text-muted',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Activity Log — BayaniServe</title>
<?php include __DIR__ . '/includes/layout_head.php'; ?>
<style>
.log-row { display:flex; gap:12px; padding:10px 0; border-bottom:1px solid #f0f0f0; align-items:flex-start; font-size:13px; }
.log-row:last-child { border-bottom:none; }
.log-icon { font-size:16px; min-width:28px; text-align:center; display:flex; align-items:center; justify-content:center; }
.log-body { flex:1; }
.log-desc { color:#222; line-height:1.4; }
.log-meta { color:#888; font-size:11px; margin-top:2px; }
.log-qty { background:#f0f4ff; color:#1e40af; padding:2px 8px; border-radius:10px; font-size:11px; margin-top:4px; display:inline-block; }
</style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
    <div class="topbar">
        <div>
            <div class="tb-title">Activity Log</div>
            <div class="tb-sub">
                <?= isSuperAdmin() ? 'All stations — complete audit trail' : 'Your station — state-changing actions only' ?>
            </div>
        </div>
    </div>
    <div class="content">
        <div class="card">
            <div class="card-h">Recent Actions (last 200)</div>
            <?php if (empty($logs)): ?>
                <p style="color:#888;font-size:13px;">No activity recorded yet.</p>
            <?php endif; ?>
            <?php foreach ($logs as $log): ?>
            <div class="log-row">
                <div class="log-icon">
                    <i class="<?= $typeIcons[$log['action_type']] ?? 'bi bi-info-circle text-secondary' ?>"></i>
                </div>
                <div class="log-body">
                    <div class="log-desc"><?= htmlspecialchars($log['description']) ?></div>
                    <div class="log-meta">
                        <?= date('M j, Y g:i A', strtotime($log['created_at'])) ?>
                        <?php if ($log['barangay_name']): ?> · <i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($log['barangay_name']) ?><?php endif; ?>
                        <?php if ($log['admin_name']): ?> · by <?= htmlspecialchars($log['admin_name']) ?><?php endif; ?>
                    </div>
                    <?php if ($log['quantity_before'] !== null && $log['quantity_after'] !== null): ?>
                        <span class="log-qty">
                            Stock: <?= $log['quantity_before'] ?> → <?= $log['quantity_after'] ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>
</body>
</html>
