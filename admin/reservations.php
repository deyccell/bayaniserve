<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();

// Reservations are a barangay-level function — super admin is not involved
if (isSuperAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$admin = currentAdmin();
$pdo   = getDB();

// Harden station_id: re-fetch from DB if session value is null/stale
$assignedStationId = (int)$admin['station_id'];
if (!$assignedStationId) {
    $stFetch = $pdo->prepare("SELECT station_id FROM admins WHERE id = ?");
    $stFetch->execute([$admin['id']]);
    $assignedStationId = (int)$stFetch->fetchColumn();
    $_SESSION['admin_station_id'] = $assignedStationId;
}

// Handle approve/decline actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id && in_array($action, ['approved', 'declined'])) {
        // Fetch reservation details
        $res = $pdo->prepare("SELECT r.*, m.name AS medicine_name, h.barangay_name FROM reservations r JOIN medicines m ON m.id=r.medicine_id JOIN health_stations h ON h.id=r.station_id WHERE r.id=?");
        $res->execute([$id]);
        $row = $res->fetch();

        if ($row && (isSuperAdmin() || (int)$row['station_id'] === $assignedStationId)) {
            $pdo->prepare("UPDATE reservations SET status=?, handled_by=?, handled_at=NOW() WHERE id=?")
                ->execute([$action, $admin['id'], $id]);

            if ($action === 'approved') {
                // Get qty before deduction
                $qOld = $pdo->prepare("SELECT quantity FROM inventory WHERE station_id=? AND medicine_id=?");
                $qOld->execute([$row['station_id'], $row['medicine_id']]);
                $qtyBefore = (int)$qOld->fetchColumn();

                $pdo->prepare("UPDATE inventory SET quantity = GREATEST(quantity - 1, 0) WHERE station_id=? AND medicine_id=?")
                    ->execute([$row['station_id'], $row['medicine_id']]);

                $qtyAfter = max($qtyBefore - 1, 0);
                logActivity('reservation_approved',
                    "{$admin['full_name']} approved reservation #{$id} for {$row['medicine_name']} ({$row['barangay_name']}) — stock deducted.",
                    $row['station_id'], $qtyBefore, $qtyAfter, $id, 'reservations');

                // Queue SMS reply if this came from SMS and has a mobile number
                if ($row['source'] === 'sms' && !empty($row['mobile_number'])) {
                    $smsText =
                        "APPROVED: Ang imo reservation para sa {$row['medicine_name']} " .
                        "sa {$row['barangay_name']} BHS ay approved na. " .
                        "Ref#{$id}. Bisita na para sa pickup. Salamat!";
                    $pdo->prepare("INSERT INTO sms_outbox (mobile_number, message, reference_type, reference_id) VALUES (?, ?, 'reservation', ?)")
                        ->execute([$row['mobile_number'], $smsText, $id]);
                }

            } else {
                logActivity('reservation_declined',
                    "{$admin['full_name']} declined reservation #{$id} for {$row['medicine_name']} ({$row['barangay_name']}).",
                    $row['station_id'], null, null, $id, 'reservations');

                // Queue SMS reply for SMS-sourced reservations
                if ($row['source'] === 'sms' && !empty($row['mobile_number'])) {
                    $smsText =
                        "DECLINED: Pasensya, ang imo reservation para sa {$row['medicine_name']} " .
                        "sa {$row['barangay_name']} BHS ay hindi ma-approve. " .
                        "Ref#{$id}. Bisita sa BHS para sa dugang impormasyon.";
                    $pdo->prepare("INSERT INTO sms_outbox (mobile_number, message, reference_type, reference_id) VALUES (?, ?, 'reservation', ?)")
                        ->execute([$row['mobile_number'], $smsText, $id]);
                }
            }
        }
    }
    header('Location: reservations.php');
    exit;
}

// Always scoped to barangay admin's own station
$reservations = $pdo->prepare("
    SELECT r.*, m.name AS medicine_name, h.barangay_name
    FROM reservations r
    JOIN medicines m ON m.id = r.medicine_id
    JOIN health_stations h ON h.id = r.station_id
    WHERE r.station_id = ?
    ORDER BY FIELD(r.status,'pending','approved','declined','completed'), r.created_at DESC
");
$reservations->execute([$assignedStationId]);
$reservations = $reservations->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reservations — BayaniServe Admin</title>
<?php include __DIR__ . '/includes/layout_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
    <div class="topbar">
        <div><div class="tb-title">Reservations</div><div class="tb-sub">Automatically fetched from the resident chatbot — no manual entry</div></div>
    </div>
    <div class="content">
        <div class="card">
            <table class="tbl">
                <tr><th>Resident</th><th>Medicine</th><th>Station</th><th>Source</th><th>Pickup date</th><th>Status</th><th>Action</th></tr>
                <?php foreach ($reservations as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['resident_name']) ?><?php if($r['mobile_number']): ?><br><span style="font-size:11px;color:#888;"><?= htmlspecialchars($r['mobile_number']) ?></span><?php endif; ?></td>
                    <td><?= htmlspecialchars($r['medicine_name']) ?></td>
                    <td><?= htmlspecialchars($r['barangay_name']) ?></td>
                    <td>
                        <?php if ($r['source'] === 'sms'): ?>
                            <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;"><i class="bi bi-phone"></i> SMS</span>
                        <?php else: ?>
                            <span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;"><i class="bi bi-chat-left-dots"></i> Chat</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $r['pickup_date'] ? date('M j', strtotime($r['pickup_date'])) : '—' ?></td>
                    <td>
                        <?php
                        $map = ['pending'=>'ba','approved'=>'bg','declined'=>'br','completed'=>'bb'];
                        ?>
                        <span class="badge <?= $map[$r['status']] ?>"><?= ucfirst($r['status']) ?></span>
                    </td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button class="btn btn-approve" name="action" value="approved">Approve</button>
                            <button class="btn btn-decline" name="action" value="declined">Decline</button>
                        </form>
                        <?php if ($r['source'] === 'sms'): ?>
                            <div style="font-size:10px;color:#888;margin-top:3px;"><i class="bi bi-phone"></i> SMS reply auto-sends</div>
                        <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$reservations): ?>
                <tr><td colspan="7" style="text-align:center;color:#888;padding:20px;">No reservations yet.</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
</main>
</body>
</html>
