<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/announcement_helpers.php';
requireAdminLogin();

$admin = currentAdmin();
$pdo   = getDB();

$assignedStationId = (int)$admin['station_id'];

// detect superadmin
$isSuperAdmin = isSuperAdmin();

// load stations list for superadmin
$stations = [];
$stationNamesById = [];
if ($isSuperAdmin) {
    $stations = $pdo->query("SELECT id, barangay_name FROM health_stations ORDER BY barangay_name")->fetchAll();
    foreach ($stations as $station) {
        $stationNamesById[(int)$station['id']] = $station['barangay_name'];
    }
}

// Harden station_id in session if missing
if (!$assignedStationId) {
    $stFetch = $pdo->prepare("SELECT station_id FROM admins WHERE id = ?");
    $stFetch->execute([$admin['id']]);
    $assignedStationId = (int)$stFetch->fetchColumn();
    $_SESSION['admin_station_id'] = $assignedStationId;
}

$err = '';
$msg = '';

// Handle posting announcements
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title   = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($title && $message) {
        // determine target station
        if ($isSuperAdmin) {
            $targetRaw = $_POST['target_station'] ?? 'all';
            if ($targetRaw === 'all') {
                $targetStationId = null;
            } else {
                $targetStationId = (int)$targetRaw;
                if (!isset($stationNamesById[$targetStationId])) {
                    $err = 'Please choose a valid barangay.';
                }
            }
        } else {
            // non-super admins can only post to their assigned station
            $targetStationId = $assignedStationId;
        }

        if (!$err) {
            try {
                $result = postAnnouncement($pdo, (int)$admin['id'], $title, $message, $targetStationId);
                $targetLabel = announcementTargetLabel($targetStationId, $stationNamesById);
                $msg = "Announcement posted successfully to {$targetLabel}. Queued SMS to " .
                    (int)$result['queued_count'] . ' resident' .
                    ((int)$result['queued_count'] === 1 ? '' : 's') . '.';
            } catch (Exception $e) {
                $err = 'Unable to post announcement. Please try again.';
            }
        }
    } else {
        $err = 'Title and message are both required.';
    }
}

// Fetch announcements: superadmin sees all, others see only their station
if ($isSuperAdmin) {
    $stmt = $pdo->prepare(
        "SELECT a.*, h.barangay_name, ad.full_name AS posted_by_name
        FROM announcements a
        LEFT JOIN health_stations h ON h.id = a.target_station_id
        JOIN admins ad ON ad.id = a.posted_by
        ORDER BY a.created_at DESC"
    );
    $stmt->execute();
} else {
    $stmt = $pdo->prepare(
        "SELECT a.*, h.barangay_name, ad.full_name AS posted_by_name
        FROM announcements a
        LEFT JOIN health_stations h ON h.id = a.target_station_id
        JOIN admins ad ON ad.id = a.posted_by
        WHERE a.target_station_id = ? OR a.target_station_id IS NULL
        ORDER BY a.created_at DESC"
    );
    $stmt->execute([$assignedStationId]);
}
$announcements = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Announcements — BayaniServe Admin</title>
<?php include __DIR__ . '/includes/layout_head.php'; ?>
<style>
.ann-item { padding:12px 0; border-bottom:1px solid #f1efe8; }
.ann-item:last-child { border-bottom:none; }
.ann-title { font-weight:600; font-size:14px; color:#1e3a5f; }
.ann-meta { font-size:11px; color:#888; margin:3px 0 6px; }
.ann-body { font-size:13px; color:#5f5e5a; line-height:1.5; }
.ann-station-badge { display:inline-block; background:#e0f2fe; color:#0369a1; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; margin-left:4px; }
.form-lbl { font-size:12px; color:#5f5e5a; display:block; margin-bottom:4px; font-weight:600; }
.form-inp { width:100%; padding:8px 10px; border:1px solid #b4b2a9; border-radius:8px; font-size:13px; margin-bottom:12px; }
.form-inp:focus { border-color:#185FA5; outline:none; }
.station-locked { display:inline-block; background:#f0fdf4; border:1px solid #86efac; color:#15803d; padding:6px 12px; border-radius:6px; font-size:13px; font-weight:600; margin-bottom:14px; }
.alert-success { background:#dcfce7; color:#14532d; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:13px; }
.alert-danger  { background:#fcebeb; color:#a32d2d; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:13px; }
</style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
    <div class="topbar">
        <div>
            <div class="tb-title">Announcements</div>
            <div class="tb-sub">
                Post announcements for your barangay residents
            </div>
        </div>
    </div>
    <div class="content">

        <?php if ($msg): ?><div class="alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <!-- Post form -->
        <div class="card" style="margin-bottom:16px;">
            <div class="card-h">Post New Announcement</div>
            <form method="POST">
            <?php if ($isSuperAdmin): ?>
                <label class="form-lbl">Target Barangay</label>
                <select name="target_station" class="form-inp">
                    <option value="all">All Barangays</option>
                    <?php foreach ($stations as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['barangay_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <div class="station-locked">
                    <i class="bi bi-geo-alt-fill"></i> Posting to: <?= htmlspecialchars($admin['full_name']) ?>'s Station (Locked)
                </div>
            <?php endif; ?>
                <label class="form-lbl">Title *</label>
                <input type="text" name="title" class="form-inp" required placeholder="e.g. Libreng Bakuna — July 5">

                <label class="form-lbl">Message *</label>
                <textarea name="message" class="form-inp" rows="4" required placeholder="Type your announcement here..."></textarea>

                <button class="btn btn-primary" type="submit"><i class="bi bi-megaphone"></i> Post Announcement</button>
            </form>
        </div>

        <div class="card">
            <div class="card-h">
                <?= $isSuperAdmin ? 'All Announcements' : "Your Station's Announcements" ?>
            </div>
            <?php if (empty($announcements)): ?>
                <p style="font-size:13px;color:#888;padding:10px 0;">No announcements posted yet.</p>
            <?php endif; ?>
            <?php foreach ($announcements as $a): ?>
            <div class="ann-item">
                <div class="ann-title">
                    <?= htmlspecialchars($a['title']) ?>
                    <?php if ($a['target_station_id'] === null): ?>
                        <span class="ann-station-badge">All Barangays</span>
                    <?php elseif ($a['barangay_name']): ?>
                        <span class="ann-station-badge"><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($a['barangay_name']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="ann-meta">
                    Posted by <?= htmlspecialchars($a['posted_by_name']) ?>
                    · <?= date('M j, Y g:i A', strtotime($a['created_at'])) ?>
                </div>
                <div class="ann-body"><?= nl2br(htmlspecialchars($a['message'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</main>
</body>
</html>
