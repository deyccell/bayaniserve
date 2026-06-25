<?php
$current = basename($_SERVER['PHP_SELF']);

if (isSuperAdmin()) {
    $pendingRqn = (int)$pdo->query("SELECT COUNT(*) FROM requisitions WHERE status='pending'")->fetchColumn();
    $outOfStock = (int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE status='out_of_stock'")->fetchColumn();
} else {
    $sid = (int)($admin['station_id'] ?? 0);
    $s = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE status='pending' AND station_id=?");
    $s->execute([$sid]); $pendingRes = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM requisitions WHERE status='pending' AND station_id=?");
    $s->execute([$sid]); $pendingRqn = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE status='out_of_stock' AND station_id=?");
    $s->execute([$sid]); $outOfStock = (int)$s->fetchColumn();
}
?>
<aside class="sidebar">
  <div class="logo">
    <div class="logo-name">BayaniServe</div>
    <div class="logo-sub"><?= isSuperAdmin() ? 'City Health Office' : 'Barangay Health Station' ?></div>
  </div>

  <div style="overflow-y:auto;flex:1;padding-bottom:8px;">

    <div class="nav-sec">Overview</div>
    <a class="nav <?= $current==='dashboard.php'?'active':'' ?>" href="dashboard.php">
      <i class="bi bi-house-door"></i> Dashboard
    </a>

    <?php if (isSuperAdmin()): ?>
    <!-- ── CITY HEALTH OFFICE ──────────────────────────────── -->

    <div class="nav-sec">Supply Chain</div>
    <a class="nav <?= $current==='requisitions.php'?'active':'' ?>" href="requisitions.php">
      <i class="bi bi-clipboard"></i> Requisitions
      <?php if ($pendingRqn): ?>
        <span class="count urgent"><?= $pendingRqn ?></span>
      <?php endif; ?>
    </a>

    <div class="nav-sec">Inventory</div>
    <a class="nav <?= $current==='inventory.php'?'active':'' ?>" href="inventory.php">
      <i class="bi bi-capsule"></i> Stock / Inventory
    </a>
    <a class="nav <?= $current==='monthly_report.php'?'active':'' ?>" href="monthly_report.php">
      <i class="bi bi-calendar3"></i> Monthly Reports
    </a>
    <a class="nav <?= $current==='activity_log.php'?'active':'' ?>" href="activity_log.php">
      <i class="bi bi-journal-text"></i> Activity Log
    </a>

    <div class="nav-sec">Intelligence</div>
    <a class="nav <?= $current==='analytics.php'?'active':'' ?>" href="analytics.php">
      <i class="bi bi-bar-chart-line"></i> Analytics &amp; Forecast
    </a>
    <a class="nav <?= $current==='emergency.php'?'active':'' ?>" href="emergency.php">
      <i class="bi bi-exclamation-triangle-fill"></i> Emergency Mode
      <?php
        try {
          $em = $pdo->query("SELECT is_active FROM emergency_mode WHERE id=1")->fetch();
          if ($em && $em['is_active']) echo '<span class="count urgent">ON</span>';
        } catch(Exception $e) {}
      ?>
    </a>

    <div class="nav-sec">Management</div>
    <a class="nav <?= $current==='user_management.php'?'active':'' ?>" href="user_management.php">
      <i class="bi bi-people"></i> User Management
    </a>
    <a class="nav <?= $current==='announcements.php'?'active':'' ?>" href="announcements.php">
      <i class="bi bi-megaphone"></i> Announcements
    </a>

    <?php else: ?>
    <!-- ── BARANGAY ADMIN ─────────────────────────────────── -->

    <div class="nav-sec">Residents</div>
    <a class="nav <?= $current==='reservations.php'?'active':'' ?>" href="reservations.php">
      <i class="bi bi-box-arrow-in-down"></i> Reservations
      <?php if ($pendingRes): ?><span class="count"><?= $pendingRes ?></span><?php endif; ?>
    </a>
    <a class="nav <?= $current==='residents.php'?'active':'' ?>" href="residents.php">
      <i class="bi bi-person-vcard"></i> Resident Records
    </a>

    <div class="nav-sec">Supply Chain</div>
    <a class="nav <?= $current==='requisitions.php'?'active':'' ?>" href="requisitions.php">
      <i class="bi bi-clipboard"></i> Requisitions
      <?php if ($pendingRqn): ?><span class="count urgent"><?= $pendingRqn ?></span><?php endif; ?>
    </a>

    <div class="nav-sec">Inventory</div>
    <a class="nav <?= $current==='inventory.php'?'active':'' ?>" href="inventory.php">
      <i class="bi bi-capsule"></i> Stock / Inventory
      <?php if ($outOfStock): ?><span class="count urgent"><?= $outOfStock ?></span><?php endif; ?>
    </a>
    <a class="nav <?= $current==='dispense.php'?'active':'' ?>" href="dispense.php">
      <i class="bi bi-prescription2"></i> Dispense Medicine
    </a>
    <a class="nav <?= $current==='monthly_report.php'?'active':'' ?>" href="monthly_report.php">
      <i class="bi bi-calendar3"></i> Monthly Reports
    </a>
    <a class="nav <?= $current==='activity_log.php'?'active':'' ?>" href="activity_log.php">
      <i class="bi bi-journal-text"></i> Activity Log
    </a>

    <div class="nav-sec">Intelligence</div>
    <a class="nav <?= $current==='analytics.php'?'active':'' ?>" href="analytics.php">
      <i class="bi bi-bar-chart-line"></i> Analytics &amp; Forecast
    </a>
    <a class="nav <?= $current==='emergency.php'?'active':'' ?>" href="emergency.php">
      <i class="bi bi-exclamation-triangle-fill"></i> Emergency Mode
      <?php
        try {
          $em2 = $pdo->query("SELECT is_active FROM emergency_mode WHERE id=1")->fetch();
          if ($em2 && $em2['is_active']) echo '<span class="count urgent">ON</span>';
        } catch(Exception $e) {}
      ?>
    </a>

    <div class="nav-sec">Communications</div>
    <a class="nav <?= $current==='announcements.php'?'active':'' ?>" href="announcements.php">
      <i class="bi bi-megaphone"></i> Announcements
    </a>

    <?php endif; ?>
  </div>

  <div class="sidebar-foot">
    <div class="foot-name"><?= htmlspecialchars($admin['full_name']) ?></div>
    <div class="foot-role"><?= $admin['role'] === 'super_admin' ? 'City Health Officer' : 'Barangay Health Admin' ?></div>
    <a class="logout" href="logout.php">Sign out →</a>
  </div>
</aside>
