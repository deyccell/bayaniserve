<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/announcement_helpers.php';
requireAdminLogin();
$admin = currentAdmin();
$pdo   = getDB();

// ─────────────────────────────────────────────────────────────────
// SUPERADMIN — City Health Office
// ─────────────────────────────────────────────────────────────────
if (isSuperAdmin()) {

    $pendingRequisitions = (int)$pdo->query("SELECT COUNT(*) FROM requisitions WHERE status='pending'")->fetchColumn();
    $totalRequisitions   = (int)$pdo->query("SELECT COUNT(*) FROM requisitions")->fetchColumn();

    $outOfStockRows = $pdo->query("
        SELECT m.name AS medicine_name, h.barangay_name, i.quantity
        FROM inventory i
        JOIN medicines m       ON m.id = i.medicine_id
        JOIN health_stations h ON h.id = i.station_id
        WHERE i.status = 'out_of_stock'
        ORDER BY h.barangay_name, m.name
    ")->fetchAll();
    $outOfStockCount = count($outOfStockRows);

    $lowStockCount = (int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE status='low_stock'")->fetchColumn();
    $totalStations = (int)$pdo->query("SELECT COUNT(*) FROM health_stations")->fetchColumn();
    $totalMedicines= (int)$pdo->query("SELECT COUNT(*) FROM medicines")->fetchColumn();
    $activeBHWs    = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role IN ('barangay_admin','bhw') AND is_active=1")->fetchColumn();

    $stockByStation = $pdo->query("
        SELECT h.barangay_name, SUM(i.quantity) AS total_units
        FROM inventory i
        JOIN health_stations h ON h.id = i.station_id
        GROUP BY h.id, h.barangay_name
        ORDER BY h.barangay_name
    ")->fetchAll();

    $recentActivity = $pdo->query("
        SELECT al.*, a.full_name AS admin_name, h.barangay_name
        FROM activity_log al
        LEFT JOIN admins a          ON a.id  = al.admin_id
        LEFT JOIN health_stations h ON h.id = al.station_id
        ORDER BY al.created_at DESC LIMIT 12
    ")->fetchAll();

    // Requisitions needing action (newest first)
    $pendingReqList = $pdo->query("
        SELECT r.id, r.requested_qty, r.created_at,
               COALESCE(m.name, r.custom_medicine_name) AS medicine_name,
               h.barangay_name, a.full_name AS submitted_by
        FROM requisitions r
        LEFT JOIN medicines m  ON m.id = r.medicine_id
        JOIN health_stations h ON h.id = r.station_id
        JOIN admins a          ON a.id = r.submitted_by
        WHERE r.status = 'pending'
        ORDER BY r.created_at ASC
        LIMIT 5
    ")->fetchAll();

// ─────────────────────────────────────────────────────────────────
// BARANGAY ADMIN
// ─────────────────────────────────────────────────────────────────
} else {
    $sid = (int)$admin['station_id'];
    if (!$sid) {
        $sf = $pdo->prepare("SELECT station_id FROM admins WHERE id=?");
        $sf->execute([$admin['id']]);
        $sid = (int)$sf->fetchColumn();
        $_SESSION['admin_station_id'] = $sid;
    }

    $s = $pdo->prepare("SELECT barangay_name FROM health_stations WHERE id=?");
    $s->execute([$sid]);
    $stationName = $s->fetchColumn() ?: 'Your Barangay';

    $s = $pdo->prepare("SELECT COUNT(*) FROM residents WHERE station_id=?");
    $s->execute([$sid]); $totalResidents = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE status='pending' AND station_id=?");
    $s->execute([$sid]); $pendingReservations = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE status='out_of_stock' AND station_id=?");
    $s->execute([$sid]); $outOfStockCount = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE status='low_stock' AND station_id=?");
    $s->execute([$sid]); $lowStockCount = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM requisitions WHERE station_id=? AND status='pending'");
    $s->execute([$sid]); $pendingRequisitions = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM requisitions WHERE station_id=? AND status IN ('approved','partial')");
    $s->execute([$sid]); $approvedRequisitions = (int)$s->fetchColumn();

    // Low/out-of-stock medicines
    $s = $pdo->prepare("
        SELECT m.name, i.quantity, i.status
        FROM inventory i
        JOIN medicines m ON m.id = i.medicine_id
        WHERE i.status IN ('low_stock','out_of_stock') AND i.station_id=?
        ORDER BY FIELD(i.status,'out_of_stock','low_stock'), i.quantity ASC
        LIMIT 8
    ");
    $s->execute([$sid]); $stockAlerts = $s->fetchAll();

    // Recent reservations
    $s = $pdo->prepare("
        SELECT rv.resident_name, rv.created_at, m.name AS medicine_name, rv.status
        FROM reservations rv
        JOIN medicines m ON m.id = rv.medicine_id
        WHERE rv.station_id=?
        ORDER BY rv.created_at DESC LIMIT 6
    ");
    $s->execute([$sid]); $recentReservations = $s->fetchAll();

    // Latest approved/pending requisitions
    $s = $pdo->prepare("
        SELECT r.id, r.status, r.created_at, r.approved_qty, r.requested_qty,
               COALESCE(m.name, r.custom_medicine_name) AS medicine_name
        FROM requisitions r
        LEFT JOIN medicines m ON m.id = r.medicine_id
        WHERE r.station_id=?
        ORDER BY r.created_at DESC LIMIT 5
    ");
    $s->execute([$sid]); $recentRequisitions = $s->fetchAll();

    // Brgy announcements (only own brgy, not superadmin)
    $s = $pdo->prepare("
        SELECT a.title, a.created_at
        FROM announcements a
        JOIN admins ad ON ad.id = a.posted_by
        WHERE a.target_station_id=? AND ad.role != 'super_admin'
        ORDER BY a.created_at DESC LIMIT 3
    ");
    $s->execute([$sid]); $brgyAnnouncements = $s->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard — BayaniServe</title>
<?php include __DIR__ . '/includes/layout_head.php'; ?>
<style>
/* Shortage banner */
.shortage-banner {
  background:var(--red-lt); border:1px solid #f0b8b8;
  border-radius:var(--radius); padding:14px 18px; margin-bottom:20px;
}
.shortage-title { font-size:13px; font-weight:700; color:var(--red); margin-bottom:10px; }
.shortage-tags  { display:flex; flex-wrap:wrap; gap:8px; }
.shortage-tag   {
  background:#fff; border:1px solid #f0b8b8; border-radius:6px;
  padding:4px 12px; font-size:12px; color:var(--red);
}

/* Pending requisition list */
.req-item {
  display:flex; justify-content:space-between; align-items:center;
  padding:10px 0; border-bottom:1px solid var(--border2); gap:12px;
}
.req-item:last-child { border-bottom:none; }
.req-med  { font-size:13px; font-weight:600; color:var(--text1); }
.req-meta { font-size:11px; color:var(--text3); margin-top:2px; }

/* Chart wrap */
.chart-wrap { position:relative; height:200px; }

/* Station header (brgy) */
.station-hdr {
  background:linear-gradient(135deg, var(--blue) 0%, var(--blue-dk) 100%);
  color:#fff; border-radius:var(--radius); padding:20px 24px; margin-bottom:20px;
}
.station-hdr-name { font-size:20px; font-weight:800; margin-bottom:4px; }
.station-hdr-sub  { font-size:12px; opacity:.8; }

/* Quick actions */
.qa-grid { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; }
.qa-btn {
  display:inline-flex; align-items:center; gap:8px;
  padding:10px 18px; border-radius:8px; font-size:13px; font-weight:600;
  text-decoration:none; border:1px solid var(--border);
  background:var(--surface); color:var(--text1); transition:all .12s;
}
.qa-btn:hover { background:var(--bg); }
.qa-btn.primary { background:var(--blue); color:#fff; border-color:var(--blue); }
.qa-btn.primary:hover { background:var(--blue-dk); }
.qa-btn.danger  { background:var(--red-lt); color:var(--red); border-color:#f0b8b8; }
.qa-btn.danger:hover  { background:#f8d8d8; }

/* Announcement mini list */
.ann-mini { padding:8px 0; border-bottom:1px solid var(--border2); }
.ann-mini:last-child { border-bottom:none; }
.ann-mini-title { font-size:13px; font-weight:600; color:var(--text1); }
.ann-mini-time  { font-size:11px; color:var(--text3); margin-top:2px; }
</style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
  <div class="topbar">
    <div class="tb-title">Dashboard</div>
    <div class="tb-sub">
      <?= isSuperAdmin()
        ? 'City Health Office — Governance &amp; Supply Chain Overview'
        : 'Barangay ' . htmlspecialchars($stationName) . ' — ' . date('l, F j, Y') ?>
    </div>
  </div>
  <div class="content">

  <?php if (isSuperAdmin()): ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!--  CITY HEALTH OFFICE DASHBOARD                              -->
  <!-- ═══════════════════════════════════════════════════════════ -->

    <?php if ($outOfStockCount > 0): ?>
    <div class="shortage-banner">
      <div class="shortage-title">⚠️ Critical Shortage — <?= $outOfStockCount ?> medicine<?= $outOfStockCount > 1?'s':'' ?> at zero stock</div>
      <div class="shortage-tags">
        <?php foreach ($outOfStockRows as $cs): ?>
        <div class="shortage-tag"><?= htmlspecialchars($cs['medicine_name']) ?> · <?= htmlspecialchars($cs['barangay_name']) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- KPI Stats -->
    <div class="g5" style="grid-template-columns:repeat(5,1fr);margin-bottom:20px;">
      <div class="stat <?= $pendingRequisitions>0?'warn':'' ?>">
        <div class="stat-label">Pending Requisitions</div>
        <div class="stat-value"><?= $pendingRequisitions ?></div>
        <div class="stat-sub">Awaiting City Health decision</div>
        <?php if ($pendingRequisitions>0): ?>
        <a class="stat-link" href="requisitions.php">Review now →</a>
        <?php endif; ?>
      </div>
      <div class="stat <?= $outOfStockCount>0?'danger':'' ?>">
        <div class="stat-label">Out of Stock</div>
        <div class="stat-value"><?= $outOfStockCount ?></div>
        <div class="stat-sub">Across all barangays</div>
      </div>
      <div class="stat <?= $lowStockCount>0?'warn':'' ?>">
        <div class="stat-label">Low Stock Items</div>
        <div class="stat-value"><?= $lowStockCount ?></div>
        <div class="stat-sub">Below minimum threshold</div>
      </div>
      <div class="stat">
        <div class="stat-label">Active BHW Admins</div>
        <div class="stat-value"><?= $activeBHWs ?></div>
        <div class="stat-sub"><?= $totalStations ?> registered stations</div>
      </div>
      <div class="stat">
        <div class="stat-label">Medicine Catalogue</div>
        <div class="stat-value"><?= $totalMedicines ?></div>
        <div class="stat-sub">Registered medicine types</div>
      </div>
    </div>

    <div class="g2">
      <!-- Pending requisitions -->
      <div class="card">
        <div class="card-h">
          Pending Requisitions
          <a href="requisitions.php">View all →</a>
        </div>
        <?php if (empty($pendingReqList)): ?>
          <p style="font-size:13px;color:var(--text3);padding:20px 0;text-align:center;">
            ✓ No pending requisitions.
          </p>
        <?php else: ?>
          <?php foreach ($pendingReqList as $rq): ?>
          <div class="req-item">
            <div>
              <div class="req-med"><?= htmlspecialchars($rq['medicine_name']) ?></div>
              <div class="req-meta">
                <?= htmlspecialchars($rq['barangay_name']) ?> · <?= $rq['requested_qty'] ?> units
                · <?= date('M j', strtotime($rq['created_at'])) ?>
              </div>
            </div>
            <a href="requisitions.php" class="btn btn-primary btn-sm">Decide</a>
          </div>
          <?php endforeach; ?>
          <?php if ($pendingRequisitions > 5): ?>
          <p style="font-size:12px;color:var(--text3);padding-top:10px;text-align:center;">
            +<?= $pendingRequisitions - 5 ?> more · <a href="requisitions.php" style="color:var(--blue);">View all</a>
          </p>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- City-wide stock chart -->
      <div class="card">
        <div class="card-h">City-Wide Stock Distribution</div>
        <?php if (empty($stockByStation)): ?>
          <p style="font-size:13px;color:var(--text3);">No inventory data.</p>
        <?php else: ?>
        <div class="chart-wrap">
          <canvas id="stockChart"></canvas>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Activity feed -->
    <div class="card">
      <div class="card-h">
        System Activity Feed
        <a href="activity_log.php">Full log →</a>
      </div>
      <?php if (empty($recentActivity)): ?>
        <p style="font-size:13px;color:var(--text3);">No activity yet.</p>
      <?php endif; ?>
      <?php
      $typeMap = [
        'stock_added'=>['bi bi-box-seam text-primary','Stock added'],
        'stock_added_bulk'=>['bi bi-box-seam text-primary','Bulk stock'],
        'reservation_approved'=>['bi bi-check-circle-fill text-success','Approved'],
        'reservation_declined'=>['bi bi-x-circle-fill text-danger','Declined'],
        'requisition_submitted'=>['bi bi-clipboard-text text-warning','Requisition'],
        'requisition_approved'=>['bi bi-check-circle-fill text-success','Approved'],
        'requisition_partial'=>['bi bi-exclamation-circle-fill text-warning','Partial'],
        'requisition_rejected'=>['bi bi-x-circle-fill text-danger','Rejected'],
        'requisition_delivered'=>['bi bi-truck text-success','Delivered'],
        'account_created'=>['bi bi-person-plus-fill text-info','Account'],
        'account_deactivated'=>['bi bi-lock-fill text-muted','Deactivated'],
      ];
      foreach ($recentActivity as $log):
        [$icon, $label] = $typeMap[$log['action_type']] ?? ['bi bi-info-circle text-secondary', $log['action_type']];
      ?>
      <div class="act-row">
        <div class="act-icon"><i class="<?= $icon ?>"></i></div>
        <div class="act-body">
          <div class="act-desc"><?= htmlspecialchars($log['description']) ?></div>
          <div class="act-meta">
            <?= date('M j, g:i A', strtotime($log['created_at'])) ?>
            <?php if ($log['barangay_name']): ?> · <?= htmlspecialchars($log['barangay_name']) ?><?php endif; ?>
            <?php if ($log['admin_name']): ?> · <?= htmlspecialchars($log['admin_name']) ?><?php endif; ?>
          </div>
        </div>
        <?php if ($log['quantity_before'] !== null && $log['quantity_after'] !== null): ?>
        <span class="badge bb" style="flex-shrink:0;"><?= $log['quantity_before'] ?>→<?= $log['quantity_after'] ?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

  <?php else: ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!--  BARANGAY ADMIN DASHBOARD                                  -->
  <!-- ═══════════════════════════════════════════════════════════ -->

    <!-- Station Header -->
    <div class="station-hdr">
      <div class="station-hdr-name">Brgy. <?= htmlspecialchars($stationName) ?> Health Station</div>
      <div class="station-hdr-sub">
        Logged in as <?= htmlspecialchars($admin['full_name']) ?> &nbsp;·&nbsp; <?= date('F j, Y') ?>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="qa-grid">
      <a href="inventory.php" class="qa-btn primary">💊 Manage Inventory</a>
      <a href="reservations.php" class="qa-btn">📥 Reservations <?php if($pendingReservations): ?><span class="badge br"><?= $pendingReservations ?></span><?php endif; ?></a>
      <a href="requisitions.php" class="qa-btn <?= $outOfStockCount>0?'danger':'' ?>">
        📋 Request Supplies <?php if($outOfStockCount>0): ?><span class="badge br"><?= $outOfStockCount ?> out</span><?php endif; ?>
      </a>
      <a href="announcements.php" class="qa-btn">📢 Post Announcement</a>
    </div>

    <!-- Stat Cards -->
    <div class="g4" style="margin-bottom:20px;">
      <div class="stat">
        <div class="stat-label">Registered Residents</div>
        <div class="stat-value"><?= $totalResidents ?></div>
        <div class="stat-sub">Brgy. <?= htmlspecialchars($stationName) ?></div>
        <a class="stat-link" href="residents.php">Manage →</a>
      </div>
      <div class="stat <?= $pendingReservations>0?'warn':'' ?>">
        <div class="stat-label">Pending Reservations</div>
        <div class="stat-value"><?= $pendingReservations ?></div>
        <div class="stat-sub">Awaiting your approval</div>
        <?php if($pendingReservations>0): ?><a class="stat-link" href="reservations.php">Review →</a><?php endif; ?>
      </div>
      <div class="stat <?= $outOfStockCount>0?'danger':($lowStockCount>0?'warn':'') ?>">
        <div class="stat-label">Stock Issues</div>
        <div class="stat-value"><?= $outOfStockCount + $lowStockCount ?></div>
        <div class="stat-sub"><?= $outOfStockCount ?> out · <?= $lowStockCount ?> low</div>
        <?php if($outOfStockCount>0||$lowStockCount>0): ?><a class="stat-link" href="inventory.php">View →</a><?php endif; ?>
      </div>
      <div class="stat <?= $pendingRequisitions>0?'warn':'ok' ?>">
        <div class="stat-label">Requisitions</div>
        <div class="stat-value"><?= $pendingRequisitions ?></div>
        <div class="stat-sub">Pending · <?= $approvedRequisitions ?> approved</div>
        <a class="stat-link" href="requisitions.php">Track →</a>
      </div>
    </div>

    <div class="g2">
      <!-- Stock Alerts -->
      <div class="card">
        <div class="card-h">
          Stock Alerts
          <a href="inventory.php">Manage →</a>
        </div>
        <?php if (empty($stockAlerts)): ?>
          <p style="font-size:13px;color:var(--green);padding:14px 0;">✓ All stock levels are healthy.</p>
        <?php else: ?>
        <table class="tbl">
          <tr><th>Medicine</th><th>Qty</th><th>Status</th><th></th></tr>
          <?php foreach ($stockAlerts as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td><strong><?= $row['quantity'] ?></strong></td>
            <td>
              <span class="badge <?= $row['status']==='out_of_stock'?'br':'ba' ?>">
                <?= $row['status']==='out_of_stock'?'Out of Stock':'Low Stock' ?>
              </span>
            </td>
            <td>
              <a href="requisitions.php" class="badge bb" style="text-decoration:none;cursor:pointer;">Request</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php if ($outOfStockCount > 0 || $lowStockCount > 0): ?>
        <div style="margin-top:14px;">
          <a href="requisitions.php" class="btn btn-primary btn-sm">📋 Submit Requisition to City Health</a>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Right column: recent activity + announcements -->
      <div style="display:flex;flex-direction:column;gap:16px;">
        <div class="card">
          <div class="card-h">
            Recent Reservations
            <a href="reservations.php">View all →</a>
          </div>
          <?php if (empty($recentReservations)): ?>
            <p style="font-size:13px;color:var(--text3);">No reservations yet.</p>
          <?php else: ?>
          <?php foreach ($recentReservations as $rv): ?>
          <div class="act-row">
            <?php
            $resIconClass = [
              'pending'   => 'bi bi-hourglass-split text-warning',
              'approved'  => 'bi bi-check-circle-fill text-success',
              'declined'  => 'bi bi-x-circle-fill text-danger',
              'completed' => 'bi bi-check2-all text-info'
            ][$rv['status']] ?? 'bi bi-info-circle text-secondary';
            ?>
            <div class="act-icon"><i class="<?= $resIconClass ?>"></i></div>
            <div class="act-body">
              <div class="act-desc"><?= htmlspecialchars($rv['resident_name']) ?> · <em><?= htmlspecialchars($rv['medicine_name']) ?></em></div>
              <div class="act-meta"><?= date('M j, g:i A', strtotime($rv['created_at'])) ?></div>
            </div>
            <span class="badge <?= $rv['status']==='pending'?'ba':($rv['status']==='approved'?'bg':'br') ?>">
              <?= ucfirst($rv['status']) ?>
            </span>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div class="card">
          <div class="card-h">
            Requisition Status
            <a href="requisitions.php">View all →</a>
          </div>
          <?php if (empty($recentRequisitions)): ?>
            <p style="font-size:13px;color:var(--text3);">No requisitions yet. <a href="requisitions.php" style="color:var(--blue);">Submit one →</a></p>
          <?php else: ?>
          <?php
          $rColors=['pending'=>'ba','approved'=>'bg','partial'=>'bb','rejected'=>'br','delivered'=>'bg'];
          foreach ($recentRequisitions as $rq): ?>
          <div class="act-row">
            <div class="act-icon"><i class="bi bi-clipboard"></i></div>
            <div class="act-body">
              <div class="act-desc"><?= htmlspecialchars($rq['medicine_name'] ?? '—') ?> · <?= $rq['requested_qty'] ?> units</div>
              <div class="act-meta"><?= date('M j, Y', strtotime($rq['created_at'])) ?></div>
            </div>
            <span class="badge <?= $rColors[$rq['status']] ?? 'ba' ?>"><?= ucfirst($rq['status']) ?></span>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <?php if (!empty($brgyAnnouncements)): ?>
        <div class="card">
          <div class="card-h">Your Announcements <a href="announcements.php">Manage →</a></div>
          <?php foreach ($brgyAnnouncements as $ann): ?>
          <div class="ann-mini">
            <div class="ann-mini-title"><?= htmlspecialchars($ann['title']) ?></div>
            <div class="ann-mini-time"><?= date('M j, Y g:i A', strtotime($ann['created_at'])) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

  <?php endif; ?>
  </div><!-- /content -->
</main>

<?php if (isSuperAdmin() && !empty($stockByStation)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('stockChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($stockByStation,'barangay_name')) ?>,
    datasets: [{
      label: 'Total Units in Stock',
      data: <?= json_encode(array_map('intval', array_column($stockByStation,'total_units'))) ?>,
      backgroundColor: 'rgba(24,95,165,.75)',
      hoverBackgroundColor: '#185FA5',
      borderRadius: 6,
      borderSkipped: false,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend:{ display:false }, tooltip:{ callbacks:{ label: c=>' '+c.parsed.y+' units' }}},
    scales: {
      y: { beginAtZero:true, grid:{ color:'#EDE9E2' }, ticks:{ precision:0 }},
      x: { grid:{ display:false }}
    }
  }
});
</script>
<?php endif; ?>
</body>
</html>
