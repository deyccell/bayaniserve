<?php
/**
 * BayaniServe — Emergency Admin Password Reset
 * --------------------------------------------
 * USE THIS IF YOU EVER GET LOCKED OUT.
 *
 * HOW TO USE:
 *   1. Open this page in browser: http://localhost/bayaniserve/admin/reset_password.php
 *   2. Enter the username and the new password you want
 *   3. Click Reset
 *   4. DELETE THIS FILE immediately after (or it's a security hole)
 *
 * SECURITY: Delete this file after use. Do NOT leave it on your server.
 */

// Block access if called from outside localhost
if (!in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1', 'localhost'])) {
    http_response_code(403);
    die('Access denied. This tool only works from localhost.');
}

require_once __DIR__ . '/../config/database.php';

$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $newPass  = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($username) || empty($newPass)) {
        $msg = 'Username and password are required.';
        $msgType = 'error';
    } elseif ($newPass !== $confirm) {
        $msg = 'Passwords do not match.';
        $msgType = 'error';
    } elseif (strlen($newPass) < 4) {
        $msg = 'Password must be at least 4 characters.';
        $msgType = 'error';
    } else {
        $pdo = getDB();
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE username = ?");
        $stmt->execute([$hash, $username]);

        if ($stmt->rowCount() > 0) {
            $msg = "✓ Password for \"$username\" has been reset successfully. You can now log in. DELETE THIS FILE NOW.";
            $msgType = 'success';
        } else {
            $msg = "Username \"$username\" not found in the database.";
            $msgType = 'error';
        }
    }
}

// Also show all current admin usernames (not passwords) to help
$pdo = getDB();
$admins = $pdo->query("SELECT username, full_name, role FROM admins")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Reset Admin Password — BayaniServe</title>
<style>
body { font-family: sans-serif; background: #fff3cd; min-height: 100vh; display:flex; align-items:center; justify-content:center; }
.card { background: #fff; border: 2px solid #f59e0b; border-radius: 12px; padding: 32px; width: 400px; }
h2 { color: #92400e; margin-bottom: 4px; }
.warn { background: #fef3c7; border: 1px solid #f59e0b; border-radius: 6px; padding: 10px; font-size: 13px; color: #78350f; margin-bottom: 20px; }
label { display:block; font-size: 13px; margin-bottom: 4px; margin-top: 12px; font-weight: 600; }
input { width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
button { width: 100%; margin-top: 16px; padding: 10px; background: #dc2626; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
.msg-success { background: #d1fae5; border: 1px solid #6ee7b7; color: #065f46; padding: 10px; border-radius: 6px; margin-top: 14px; font-size: 13px; font-weight: 600; }
.msg-error   { background: #fee2e2; border: 1px solid #fca5a5; color: #7f1d1d; padding: 10px; border-radius: 6px; margin-top: 14px; font-size: 13px; }
table { width: 100%; margin-top: 20px; border-collapse: collapse; font-size: 13px; }
th, td { border: 1px solid #e5e7eb; padding: 6px 10px; text-align: left; }
th { background: #f9fafb; }
</style>
</head>
<body>
<div class="card">
    <h2>⚠️ Password Reset Tool</h2>
    <div class="warn">
        <strong>Security warning:</strong> Delete this file immediately after use.<br>
        Path: <code>/admin/reset_password.php</code>
    </div>

    <form method="POST">
        <label>Admin Username</label>
        <input type="text" name="username" placeholder="admin" required>

        <label>New Password</label>
        <input type="password" name="new_password" required>

        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" required>

        <button type="submit">Reset Password</button>
    </form>

    <?php if ($msg): ?>
        <div class="msg-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <table>
        <thead><tr><th>Username</th><th>Name</th><th>Role</th></tr></thead>
        <tbody>
        <?php foreach ($admins as $a): ?>
            <tr>
                <td><?= htmlspecialchars($a['username']) ?></td>
                <td><?= htmlspecialchars($a['full_name']) ?></td>
                <td><?= htmlspecialchars($a['role']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
