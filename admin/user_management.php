<?php
require_once __DIR__ . '/includes/auth.php';
requireSuperAdmin();   // hard 403 for anyone who isn't super_admin
$admin = currentAdmin();
$pdo   = getDB();

$msg  = '';
$errs = [];

// ── Create new barangay admin ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'create') {
        $username  = trim($_POST['username'] ?? '');
        $fullName  = trim($_POST['full_name'] ?? '');
        $password  = $_POST['password'] ?? '';
        $stationId = (int)($_POST['station_id'] ?? 0);

        if ($username === '' || $fullName === '' || $password === '' || $stationId <= 0) {
            $errs[] = 'All fields are required.';
        } elseif (strlen($password) < 6) {
            $errs[] = 'Password must be at least 6 characters.';
        } else {
            // Check username uniqueness
            $dup = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE username = ?");
            $dup->execute([$username]);
            if ($dup->fetchColumn() > 0) {
                $errs[] = 'Username already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("
                    INSERT INTO admins (username, password_hash, full_name, role, station_id, created_by)
                    VALUES (?, ?, ?, 'barangay_admin', ?, ?)
                ")->execute([$username, $hash, $fullName, $stationId, $admin['id']]);

                $newId = $pdo->lastInsertId();
                $stName = $pdo->prepare("SELECT barangay_name FROM health_stations WHERE id = ?");
                $stName->execute([$stationId]);
                $stNameStr = $stName->fetchColumn();

                logActivity('account_created',
                    "{$admin['full_name']} created barangay admin account '{$username}' for {$stNameStr}.",
                    $stationId, null, null, $newId, 'admins');
                $msg = "Account '{$username}' created successfully.";
            }
        }
    }

    elseif ($_POST['action'] === 'toggle_active') {
        $targetId  = (int)($_POST['target_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? 0);

        // Can't deactivate yourself
        if ($targetId === (int)$admin['id']) {
            $errs[] = 'You cannot deactivate your own account.';
        } else {
            $pdo->prepare("UPDATE admins SET is_active = ? WHERE id = ? AND role IN ('barangay_admin', 'bhw')")
                ->execute([$newStatus, $targetId]);

            $targetAdmin = $pdo->prepare("SELECT full_name, station_id FROM admins WHERE id = ?");
            $targetAdmin->execute([$targetId]);
            $ta = $targetAdmin->fetch();

            $action = $newStatus ? 'account_reactivated' : 'account_deactivated';
            logActivity($action,
                "{$admin['full_name']} " . ($newStatus ? 'reactivated' : 'deactivated') . " account for {$ta['full_name']}.",
                $ta['station_id'], null, null, $targetId, 'admins');
            $msg = "Account " . ($newStatus ? 'reactivated' : 'deactivated') . ".";
        }
    }

    elseif ($_POST['action'] === 'reset_password') {
        $targetId   = (int)($_POST['target_id'] ?? 0);
        $newPw      = $_POST['new_password'] ?? '';
        if (strlen($newPw) < 6) {
            $errs[] = 'New password must be at least 6 characters.';
        } else {
            $hash = password_hash($newPw, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE admins SET password_hash = ? WHERE id = ? AND role IN ('barangay_admin', 'bhw')")
                ->execute([$hash, $targetId]);
            $msg = 'Password updated.';
        }
    }
}

$stations   = $pdo->query("SELECT id, barangay_name FROM health_stations ORDER BY barangay_name")->fetchAll();
$allAdmins  = $pdo->query("
    SELECT a.*, h.barangay_name, c.full_name AS creator_name
    FROM admins a
    LEFT JOIN health_stations h ON h.id = a.station_id
    LEFT JOIN admins c          ON c.id = a.created_by
    ORDER BY a.role, a.full_name
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Management — BayaniServe</title>
<?php include __DIR__ . '/includes/layout_head.php'; ?>
<style>
.form-group { display:flex; flex-direction:column; gap:4px; margin-bottom:12px; }
.form-group label { font-size:12px; font-weight:600; color:#444; }
.form-group input, .form-group select { padding:8px 12px; border:1px solid #ccc; border-radius:6px; font-size:14px; }
.inline-btn { padding:8px 16px; background:#185FA5; color:white; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:13px; }
.inline-btn:hover { background:#0c447c; }
.btn-danger { background:#dc2626; }
.btn-danger:hover { background:#b91c1c; }
.btn-success { background:#16a34a; }
.btn-success:hover { background:#15803d; }
.alert-success { background:#dcfce7; color:#14532d; padding:12px; border-radius:8px; margin-bottom:16px; font-size:14px; }
.alert-danger { background:#fee2e2; color:#7f1d1d; padding:12px; border-radius:8px; margin-bottom:16px; font-size:14px; }
.deactivated { opacity:0.55; }
.role-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
.role-super { background:#fef3c7; color:#92400e; }
.role-brgy  { background:#e0f2fe; color:#0369a1; }
</style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
    <div class="topbar">
        <div>
            <div class="tb-title">User Management</div>
            <div class="tb-sub">City Health — Create and manage barangay admin accounts</div>
        </div>
    </div>
    <div class="content">

        <?php if ($msg): ?><div class="alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($errs): ?>
            <div class="alert-danger"><ul><?php foreach ($errs as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <div class="card" style="max-width:520px;margin-bottom:20px;">
            <div class="card-h">Create New Barangay Admin</div>
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" name="username" required placeholder="e.g. camugao_bhw">
                </div>
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" required placeholder="e.g. Camugao Midwife">
                </div>
                <div class="form-group">
                    <label>Initial Password * (min 6 characters)</label>
                    <input type="password" name="password" required minlength="6">
                </div>
                <div class="form-group">
                    <label>Assigned Barangay Station *</label>
                    <select name="station_id" required>
                        <option value="">— Select station —</option>
                        <?php foreach ($stations as $st): ?>
                            <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['barangay_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="inline-btn" type="submit">Create Account</button>
            </form>
        </div>

        <div class="card">
            <div class="card-h">All Admin Accounts</div>
            <table class="tbl">
                <tr><th>Username</th><th>Full Name</th><th>Role</th><th>Station</th><th>Status</th><th>Created By</th><th>Actions</th></tr>
                <?php foreach ($allAdmins as $u): ?>
                <tr class="<?= $u['is_active'] ? '' : 'deactivated' ?>">
                    <td><b><?= htmlspecialchars($u['username']) ?></b></td>
                    <td><?= htmlspecialchars($u['full_name']) ?></td>
                    <td>
                        <span class="role-badge <?= $u['role'] === 'super_admin' ? 'role-super' : 'role-brgy' ?>">
                            <?= $u['role'] === 'super_admin' ? 'City Health' : 'Barangay Admin' ?>
                        </span>
                    </td>
                    <td><?= $u['barangay_name'] ? '<i class="bi bi-geo-alt-fill text-muted"></i> ' . htmlspecialchars($u['barangay_name']) : '— (City Health)' ?></td>
                    <td><?= $u['is_active'] ? '<span style="color:#16a34a;font-weight:600;">Active</span>' : '<span style="color:#dc2626;">Deactivated</span>' ?></td>
                    <td style="font-size:12px;color:#666;"><?= htmlspecialchars($u['creator_name'] ?? '— (seed)') ?></td>
                    <td>
                        <?php if ($u['role'] !== 'super_admin'): ?>
                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                            <!-- Toggle active/inactive -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="new_status" value="<?= $u['is_active'] ? 0 : 1 ?>">
                                <button class="inline-btn <?= $u['is_active'] ? 'btn-danger' : 'btn-success' ?>"
                                        style="font-size:12px;padding:5px 10px;" type="submit"
                                        onclick="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?> this account?')">
                                    <?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?>
                                </button>
                            </form>
                            <!-- Reset password -->
                            <form method="POST" style="display:inline;display:flex;gap:4px;align-items:center;">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                                <input type="password" name="new_password" placeholder="New password" minlength="6"
                                       style="padding:5px 8px;border:1px solid #ccc;border-radius:5px;font-size:12px;width:120px;">
                                <button class="inline-btn" style="font-size:12px;padding:5px 10px;background:#475569;" type="submit">Reset</button>
                            </form>
                        </div>
                        <?php else: ?>
                            <span style="color:#888;font-size:12px;">— (protected)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

    </div>
</main>
</body>
</html>
