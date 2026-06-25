<?php
/**
 * BayaniServe - Admin Authentication & Role Helpers
 * Only included by files inside /admin/.
 */

session_name('bayaniserve_admin_session');
session_start();

require_once __DIR__ . '/../../config/database.php';

// ── Login / logout ──────────────────────────────────────────────

function requireAdminLogin() {
    if (!isset($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

function requireSuperAdmin() {
    requireAdminLogin();
    if ($_SESSION['admin_role'] !== 'super_admin') {
        http_response_code(403);
        echo '<h2 style="font-family:sans-serif;color:#7f1d1d;padding:2rem;">403 — City Health access only.</h2>';
        exit;
    }
}

function adminLogin($username, $password) {
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        $_SESSION['admin_id']         = $admin['id'];
        $_SESSION['admin_username']   = $admin['username'];
        $_SESSION['admin_full_name']  = $admin['full_name'];
        $_SESSION['admin_role']       = $admin['role'];
        $_SESSION['admin_station_id'] = $admin['station_id'];
        return true;
    }
    return false;
}

function adminLogout() {
    $_SESSION = [];
    session_destroy();
}

function currentAdmin() {
    return [
        'id'         => $_SESSION['admin_id']         ?? null,
        'username'   => $_SESSION['admin_username']   ?? null,
        'full_name'  => $_SESSION['admin_full_name']  ?? null,
        'role'       => $_SESSION['admin_role']       ?? null,
        'station_id' => $_SESSION['admin_station_id'] ?? null,
    ];
}

// ── Role helpers ─────────────────────────────────────────────────

function isSuperAdmin(): bool {
    return ($_SESSION['admin_role'] ?? '') === 'super_admin';
}

function isBarangayAdmin(): bool {
    $role = $_SESSION['admin_role'] ?? '';
    return $role === 'barangay_admin' || $role === 'bhw';
}

// ── Audit trail ──────────────────────────────────────────────────

/**
 * Write one line to activity_log.
 *
 * @param string   $actionType   e.g. 'stock_added', 'reservation_approved'
 * @param string   $description  Human-readable sentence
 * @param int|null $stationId    Which station was affected
 * @param int|null $qtyBefore
 * @param int|null $qtyAfter
 * @param int|null $refId        PK of the affected row
 * @param string|null $refTable  Table name (e.g. 'inventory', 'requisitions')
 */
function logActivity(
    string  $actionType,
    string  $description,
    ?int    $stationId  = null,
    ?int    $qtyBefore  = null,
    ?int    $qtyAfter   = null,
    ?int    $refId      = null,
    ?string $refTable   = null
): void {
    $adminId = $_SESSION['admin_id'] ?? null;
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            INSERT INTO activity_log
                (admin_id, station_id, action_type, description,
                 quantity_before, quantity_after, reference_id, reference_table)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$adminId, $stationId, $actionType, $description,
                        $qtyBefore, $qtyAfter, $refId, $refTable]);
    } catch (Exception $e) {
        // Log silently; never break the main flow
    }
}
