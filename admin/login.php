<?php
require_once __DIR__ . '/includes/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (adminLogin($username, $password)) {
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Invalid username or password, or account is deactivated.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>BayaniServe — Staff Login</title>
<meta name="robots" content="noindex, nofollow">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, sans-serif; background: #f1efe8; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
.card { background: #fff; border: 1px solid #e0ded5; border-radius: 12px; padding: 32px 36px; width: 320px; }
.title { font-size: 18px; font-weight: 600; margin-bottom: 4px; color: #2c2c2a; }
.sub { font-size: 13px; color: #5f5e5a; margin-bottom: 24px; }
.fg { margin-bottom: 14px; }
.fl { font-size: 12px; color: #5f5e5a; margin-bottom: 4px; display: block; }
.fi { width: 100%; padding: 9px 12px; font-size: 14px; border: 1px solid #b4b2a9; border-radius: 8px; outline: none; }
.fi:focus { border-color: #185FA5; }
.btn { width: 100%; padding: 10px; background: #185FA5; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; margin-top: 6px; }
.btn:hover { background: #0c447c; }
.err { font-size: 12px; color: #A32D2D; margin-top: 10px; background: #fcebeb; padding: 8px 10px; border-radius: 6px; }
.note { font-size: 11px; color: #888780; margin-top: 18px; text-align: center; }
</style>
</head>
<body>
<div class="card">
    <div class="title">BayaniServe</div>
    <div class="sub">Staff portal — Barangay Admin / City Health only</div>
    <form method="POST">
        <div class="fg">
            <label class="fl">Username</label>
            <input class="fi" type="text" name="username" id="username" required autofocus>
        </div>
        <div class="fg">
            <label class="fl">Password</label>
            <input class="fi" type="password" name="password" id="password" required>
        </div>
        <button class="btn" type="submit">Sign in</button>
        <?php if ($error): ?>
            <div class="err"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
    </form>
    <div class="note">Restricted to authorized health personnel only.</div>
</div>
</body>
</html>
