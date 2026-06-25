<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();

// Medicine requests are a barangay-level function — super admin is not involved
if (isSuperAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$admin = currentAdmin();
$pdo   = getDB();

$assignedStationId = (int)$admin['station_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id && in_array($action, ['fulfilled', 'dismissed'])) {
        $res = $pdo->prepare("SELECT mr.*, h.barangay_name FROM medicine_requests mr JOIN health_stations h ON h.id=mr.station_id WHERE mr.id=? AND mr.station_id=?");
        $res->execute([$id, $assignedStationId]);
        $row = $res->fetch();

        if ($row) {
            $pdo->prepare("UPDATE medicine_requests SET status=?, handled_by=?, handled_at=NOW() WHERE id=?")
                ->execute([$action, $admin['id'], $id]);
            logActivity('request_' . $action,
                "{$admin['full_name']} marked request #{$id} for {$row['medicine_name']} ({$row['barangay_name']}) as {$action}.",
                $row['station_id'], null, null, $id, 'medicine_requests');

            if ($row['source'] === 'sms' && !empty($row['mobile_number'])) {
                if ($action === 'fulfilled') {
                    $smsText =
                        "UPDATE: Ang {$row['medicine_name']} nga imo gin-request sa " .
                        "{$row['barangay_name']} BHS ay available na. " .
                        "Ref#{$id}. Bisita na sa BHS. Salamat!";
                } else {
                    $smsText =
                        "UPDATE: Ang imo request para sa {$row['medicine_name']} sa " .
                        "{$row['barangay_name']} BHS ay dismissed. " .
                        "Ref#{$id}. Makig-ugnay sa BHW para sa dugang tulong.";
                }
                $pdo->prepare("INSERT INTO sms_outbox (mobile_number, message, reference_type, reference_id) VALUES (?, ?, 'medicine_request', ?)")
                    ->execute([$row['mobile_number'], $smsText, $id]);
            }
        }
    }
    header('Location: requests.php');
    exit;
}

$requests = $pdo->prepare("
    SELECT mr.*, h.barangay_name
    FROM medicine_requests mr
    LEFT JOIN health_stations h ON h.id = mr.station_id
    WHERE mr.station_id = ?
    ORDER BY FIELD(mr.status,'pending','fulfilled','dismissed'), mr.created_at DESC
");
$requests->execute([$assignedStationId]);
$requests = $requests->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Medicine requests — BayaniServe Admin</title>
<?php include __DIR__ . '/includes/layout_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
    <div class="topbar">
        <div><div class="tb-title">Medicine requests</div><div class="tb-sub">Out-of-stock requests submitted via the resident chatbot</div></div>
    </div>
    <div class="content">
        <div class="card">
            <table class="tbl">
                <tr><th>Resident</th><th>Medicine Requested</th><th>Barangay</th><th>Source</th><th>Status</th><th>Action</th></tr>
                <?php foreach ($requests as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['resident_name']) ?><?php if($r['mobile_number']): ?><br><span style="font-size:11px;color:#888;"><?= htmlspecialchars($r['mobile_number']) ?></span><?php endif; ?></td>
                    <td><?= htmlspecialchars($r['medicine_name']) ?></td>
                    <td><?= htmlspecialchars($r['barangay_name'] ?? '—') ?></td>
                    <td>
                        <?php if ($r['source'] === 'sms'): ?>
                            <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;"><i class="bi bi-phone"></i> SMS</span>
                        <?php else: ?>
                            <span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;"><i class="bi bi-chat-left-dots"></i> Chat</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $map = ['pending'=>'ba','fulfilled'=>'bg','dismissed'=>'br']; ?>
                        <span class="badge <?= $map[$r['status']] ?>"><?= ucfirst($r['status']) ?></span>
                    </td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button class="btn btn-approve" name="action" value="fulfilled">Mark Fulfilled</button>
                            <button class="btn btn-decline" name="action" value="dismissed">Dismiss</button>
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
                <?php if (!$requests): ?>
                <tr><td colspan="6" style="text-align:center;color:#888;padding:20px;">No requests yet.</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
</main>
</body>
</html>
