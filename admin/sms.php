<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
$admin = currentAdmin();
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim($_POST['message']);
    $stationId = $_POST['station_id'] ?: null;

    if ($message) {
        $sql = "SELECT mobile_number FROM residents";
        $params = [];
        if ($stationId) {
            $sql .= " WHERE station_id = ?";
            $params[] = $stationId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $mobile) {
            // Replace this with your actual SMS gateway integration
            // (e.g. Semaphore, Twilio, or a GSM modem AT-command bridge)
            $log = $pdo->prepare("INSERT INTO sms_log (direction, mobile_number, message, status) VALUES ('outbound', ?, ?, 'sent')");
            $log->execute([$mobile, $message]);
        }
    }
    header('Location: sms.php');
    exit;
}

$stations = $pdo->query("SELECT s.*, COUNT(r.id) as resident_count FROM health_stations s
                          LEFT JOIN residents r ON r.station_id = s.id
                          GROUP BY s.id")->fetchAll();
$smsLog = $pdo->query("SELECT * FROM sms_log ORDER BY created_at DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SMS broadcast — BayaniServe Admin</title>
<?php include __DIR__ . '/includes/layout_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
    <div class="topbar">
        <div><div class="tb-title">SMS broadcast</div><div class="tb-sub">Send messages based on uploaded resident records</div></div>
    </div>
    <div class="content">

        <div class="card" style="margin-bottom:16px;">
            <div class="card-h">Send SMS broadcast</div>
            <form method="POST">
                <div style="margin-bottom:10px;">
                    <label style="font-size:12px;color:#5f5e5a;display:block;margin-bottom:4px;">Recipients</label>
                    <select name="station_id" style="padding:8px 10px;border:1px solid #b4b2a9;border-radius:8px;font-size:13px;">
                        <option value="">All registered residents</option>
                        <?php foreach ($stations as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['barangay_name']) ?> only (<?= $s['resident_count'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-bottom:10px;">
                    <label style="font-size:12px;color:#5f5e5a;display:block;margin-bottom:4px;">Message</label>
                    <textarea name="message" required rows="3" style="width:100%;padding:8px 10px;border:1px solid #b4b2a9;border-radius:8px;font-size:13px;"></textarea>
                </div>
                <button class="btn btn-primary" type="submit">Send SMS</button>
            </form>
            <p style="font-size:11px;color:#888;margin-top:10px;">Note: connect your SMS gateway (e.g. Semaphore, Twilio, or GSM modem) inside sms.php to actually send messages — this currently logs to the database only.</p>
        </div>

        <div class="card">
            <div class="card-h">Recent SMS log</div>
            <table class="tbl">
                <tr><th>Direction</th><th>Number</th><th>Message</th><th>Time</th><th>Status</th></tr>
                <?php foreach ($smsLog as $s): ?>
                <tr>
                    <td><?= ucfirst($s['direction']) ?></td>
                    <td><?= htmlspecialchars($s['mobile_number']) ?></td>
                    <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($s['message']) ?></td>
                    <td style="font-size:11px;color:#888;"><?= date('M j, g:i A', strtotime($s['created_at'])) ?></td>
                    <td><span class="badge bg"><?= ucfirst($s['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

    </div>
</main>
</body>
</html>
