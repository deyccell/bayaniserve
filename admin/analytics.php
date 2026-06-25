<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
$admin = currentAdmin();
$pdo   = getDB();
require_once __DIR__ . '/../includes/smart_inventory.php';
$analyticsMsg = '';

// ── Filters ──────────────────────────────────────────────────────
$selectedMedicine = (int)($_GET['medicine_id'] ?? 0);
$selectedStation  = isSuperAdmin()
    ? (int)($_GET['station_id'] ?? 0)
    : (int)$admin['station_id'];
$months           = max(3, min(24, (int)($_GET['months'] ?? 10)));

// ── Data: all medicines & stations for filter dropdowns ──────────
$medicines = $pdo->query("SELECT id, name FROM medicines ORDER BY name")->fetchAll();
$stations  = $pdo->query("SELECT id, barangay_name FROM health_stations ORDER BY barangay_name")->fetchAll();

if (!$selectedMedicine && $medicines) {
    $selectedMedicine = (int)$medicines[0]['id'];
}
if (!$selectedStation && $stations) {
    $selectedStation = (int)$stations[0]['id'];
}

// ── Helper: linear regression ─────────────────────────────────────
function linearRegression(array $ys): array {
    $n = count($ys);
    if ($n < 2) return ['slope' => 0, 'intercept' => $ys[0] ?? 0];
    $xs   = range(0, $n - 1);
    $sumX = array_sum($xs);
    $sumY = array_sum($ys);
    $sumXY = 0; $sumX2 = 0;
    foreach ($xs as $i => $x) { $sumXY += $x * $ys[$i]; $sumX2 += $x * $x; }
    $denom = $n * $sumX2 - $sumX * $sumX;
    if ($denom == 0) return ['slope' => 0, 'intercept' => $sumY / $n];
    $slope     = ($n * $sumXY - $sumX * $sumY) / $denom;
    $intercept = ($sumY - $slope * $sumX) / $n;
    return ['slope' => $slope, 'intercept' => $intercept];
}

// ── Query: monthly snapshot history ──────────────────────────────
$snapshotRows = [];
if ($selectedMedicine && $selectedStation) {
    $stmt = $pdo->prepare("
        SELECT snapshot_month, quantity_distributed, closing_quantity, quantity_received
        FROM   monthly_inventory_snapshots
        WHERE  medicine_id = ? AND station_id = ?
        ORDER  BY snapshot_month ASC
        LIMIT  {$months}
    ");
    $stmt->execute([$selectedMedicine, $selectedStation]);
    $snapshotRows = $stmt->fetchAll();
}

// ── Query: all-station distribution totals (last 6 months) ───────
$allStationDist = $pdo->query("
    SELECT h.barangay_name,
           SUM(s.quantity_distributed) AS total_dist,
           SUM(s.quantity_received)    AS total_recv
    FROM   monthly_inventory_snapshots s
    JOIN   health_stations h ON h.id = s.station_id
    WHERE  s.snapshot_month >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 6 MONTH),'%Y-%m')
    GROUP  BY h.id, h.barangay_name
    ORDER  BY total_dist DESC
")->fetchAll();

// ── Query: top-requested medicines ───────────────────────────────
$topMeds = $pdo->query("
    SELECT medicine_name, COUNT(*) AS request_count
    FROM   medicine_requests
    GROUP  BY medicine_name
    ORDER  BY request_count DESC
    LIMIT  8
")->fetchAll();

// ── Query: monthly totals for selected station ────────────────────
$stationMonthly = [];
if ($selectedStation) {
    $stmt = $pdo->prepare("
        SELECT snapshot_month, SUM(quantity_distributed) AS total_dist
        FROM   monthly_inventory_snapshots
        WHERE  station_id = ?
        GROUP  BY snapshot_month
        ORDER  BY snapshot_month ASC
        LIMIT  12
    ");
    $stmt->execute([$selectedStation]);
    $stationMonthly = $stmt->fetchAll();
}

// ── SUPERADMIN ONLY: Budget / performance queries ─────────────────
if (isSuperAdmin()) {
    // Total units distributed all-time across all stations
    $totalUnitsAllTime = $pdo->query("SELECT SUM(quantity_distributed) FROM monthly_inventory_snapshots")->fetchColumn() ?: 0;

    // Admin performance: requisitions submitted per station (proxy for workload)
    $adminPerf = $pdo->query("
        SELECT h.barangay_name, COUNT(r.id) AS total_req,
               SUM(r.status='approved') AS approved,
               SUM(r.status='rejected') AS rejected
        FROM requisitions r
        JOIN health_stations h ON h.id = r.station_id
        GROUP BY h.id, h.barangay_name
        ORDER BY total_req DESC
    ")->fetchAll();

    // Community vulnerability: stations with the most out-of-stock events
    $vulnerability = $pdo->query("
        SELECT h.barangay_name, COUNT(*) AS stockout_count
        FROM inventory i
        JOIN health_stations h ON h.id = i.station_id
        WHERE i.status = 'out_of_stock'
        GROUP BY h.id, h.barangay_name
        ORDER BY stockout_count DESC
    ")->fetchAll();

    // Pending vs approved requisitions summary
    $reqSummary = $pdo->query("
        SELECT status, COUNT(*) AS cnt
        FROM requisitions
        GROUP BY status
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// ── Build forecast data ────────────────────────────────────────────
$trendLabels  = [];
$distData     = [];
$closingData  = [];
$forecastData = [];

foreach ($snapshotRows as $r) {
    $trendLabels[] = date('M Y', strtotime($r['snapshot_month'] . '-01'));
    $distData[]    = (int)$r['quantity_distributed'];
    $closingData[] = (int)$r['closing_quantity'];
}

$forecastMonths = 3;
if (count($distData) >= 2) {
    $reg    = linearRegression($distData);
    $n      = count($distData);
    $forecastData = array_fill(0, $n - 1, null);
    $forecastData[] = round($reg['intercept'] + $reg['slope'] * ($n - 1));
    for ($i = 1; $i <= $forecastMonths; $i++) {
        $pred           = $reg['intercept'] + $reg['slope'] * ($n - 1 + $i);
        $forecastData[] = max(0, round($pred));
        $lastMonth      = end($snapshotRows)['snapshot_month'];
        $trendLabels[]  = date('M Y', strtotime($lastMonth . '-01 +' . $i . ' month'));
    }
    $distData    = array_pad($distData,   count($trendLabels), null);
    $closingData = array_pad($closingData, count($trendLabels), null);

    $actualDistData = array_values(array_filter($distData, fn($v) => $v !== null));
    $avgMonthlyDist = count($actualDistData) ? array_sum($actualDistData) / count($actualDistData) : 0;
    $lastSnapshot   = end($snapshotRows);
    $lastClosing    = (int)$lastSnapshot['closing_quantity'];
    $daysOfStockRemaining = $avgMonthlyDist > 0
        ? (int)round(($lastClosing / $avgMonthlyDist) * 30.4)
        : null;
    $weeksToStockout = $daysOfStockRemaining !== null
        ? round($daysOfStockRemaining / 7, 1)
        : null;
} else {
    $weeksToStockout = null;
    $daysOfStockRemaining = null;
    $avgMonthlyDist = 0;
    $lastClosing = null;
    $lastSnapshot = null;
}

// ── Medicine / station names ──────────────────────────────────────
$medName      = '—';
$stationName  = '—';
foreach ($medicines as $m) { if ((int)$m['id'] === $selectedMedicine) $medName = $m['name']; }
foreach ($stations  as $s) { if ((int)$s['id'] === $selectedStation)  $stationName = $s['barangay_name']; }

// ── Actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Brgy admin: Generate Requisition from forecast
    if ($action === 'generate_requisition' && !isSuperAdmin()) {
        if ($selectedMedicine && $selectedStation && $daysOfStockRemaining !== null) {
            $suggestedQty = max(1, (int)round($avgMonthlyDist * 2));
            $notes = "AI-generated requisition based on forecast: {$medName} has ~{$daysOfStockRemaining} days remaining at current depletion rate. Suggested reorder: {$suggestedQty} units.";
            $stmt = $pdo->prepare("INSERT INTO requisitions (station_id, medicine_id, quantity_requested, notes, status, created_by) VALUES (?, ?, ?, ?, 'pending', ?)");
            $stmt->execute([$selectedStation, $selectedMedicine, $suggestedQty, $notes, $admin['id']]);

            // Log it
            logActivity('requisition_submitted',
                "{$admin['full_name']} generated a system-predicted requisition for {$medName} ({$suggestedQty} units) from analytics forecast.",
                $selectedStation, null, null, (int)$pdo->lastInsertId(), 'requisitions');

            $analyticsMsg = "Requisition for {$medName} submitted to City Health ({$suggestedQty} units suggested). Track it in Supply Requisitions.";
        } else {
            $analyticsMsg = 'Not enough forecast data to generate a requisition. Add monthly snapshots first.';
        }
    }

    // Superadmin: Draft DoSR notification
    if ($action === 'draft_dosr_notification' && isSuperAdmin()) {
        if ($selectedMedicine && $selectedStation && $daysOfStockRemaining !== null) {
            $severity = $daysOfStockRemaining <= 7 ? 'critical' : 'warning';
            $title = "Inventory reorder needed: {$medName}";
            $message =
                "{$stationName} has an estimated {$daysOfStockRemaining} day(s) of {$medName} remaining " .
                "based on recent monthly distribution trends. Barangay should prepare a requisition or request stock transfer.";

            smartInventoryCreateNotification($pdo, 'cityhealth', $title, $message, $severity, $selectedStation, 'dosr_forecast', $selectedMedicine);
            smartInventoryCreateNotification($pdo, 'superadmin', $title, $message, $severity, $selectedStation, 'dosr_forecast', $selectedMedicine);
            $analyticsMsg = 'Dashboard notification drafted for City Health and Superadmin.';
        } else {
            $analyticsMsg = 'Not enough data to draft a stock depletion notification.';
        }
    }
}

// ── JSON encode for JS ────────────────────────────────────────────
$jsLabels   = json_encode($trendLabels);
$jsDist     = json_encode($distData);
$jsClosing  = json_encode($closingData);
$jsForecast = json_encode($forecastData);

$jsAllStationNames = json_encode(array_column($allStationDist, 'barangay_name'));
$jsAllStationDist  = json_encode(array_map(fn($r) => (int)$r['total_dist'], $allStationDist));
$jsAllStationRecv  = json_encode(array_map(fn($r) => (int)$r['total_recv'], $allStationDist));

$jsTopMedNames  = json_encode(array_column($topMeds, 'medicine_name'));
$jsTopMedCounts = json_encode(array_map(fn($r) => (int)$r['request_count'], $topMeds));

$jsStMLabels = json_encode(array_map(fn($r)=>date('M Y',strtotime($r['snapshot_month'].'-01')),$stationMonthly));
$jsStMDist   = json_encode(array_map(fn($r)=>(int)$r['total_dist'], $stationMonthly));

if (isSuperAdmin()) {
    $jsPerfLabels    = json_encode(array_column($adminPerf, 'barangay_name'));
    $jsPerfApproved  = json_encode(array_map(fn($r)=>(int)$r['approved'], $adminPerf));
    $jsPerfRejected  = json_encode(array_map(fn($r)=>(int)$r['rejected'], $adminPerf));
    $jsVulnLabels    = json_encode(array_column($vulnerability, 'barangay_name'));
    $jsVulnCounts    = json_encode(array_map(fn($r)=>(int)$r['stockout_count'], $vulnerability));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics & Forecast — BayaniServe</title>
<?php require_once __DIR__ . '/includes/layout_head.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
.filter-bar { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:18px; }
.filter-bar label { font-size:11px; color:#5f5e5a; display:block; margin-bottom:4px; }
.filter-bar select, .filter-bar input { font-size:13px; padding:6px 10px; border:1px solid #ddd; border-radius:8px; background:#fff; color:#2c2c2a; }
.filter-bar button { padding:7px 16px; background:#185FA5; color:#fff; border:none; border-radius:8px; font-size:13px; cursor:pointer; }
.g3 { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:18px; }
@media(max-width:720px){ .g3{grid-template-columns:1fr;} }
.mc { background:#fff; border:1px solid #e0ded5; border-radius:10px; padding:14px 16px; }
.mc-l { font-size:11px; font-weight:600; color:#5f5e5a; text-transform:uppercase; letter-spacing:.4px; margin-bottom:8px; }
.mc-v { font-size:26px; font-weight:700; color:#2c2c2a; line-height:1.1; }
.mc-s { font-size:11px; color:#888780; margin-top:6px; }
.forecast-pill { display:inline-flex; align-items:center; gap:6px; font-size:11px; padding:3px 10px; border-radius:20px; }
.fp-warn { background:#FEF3C7; color:#92400E; }
.fp-critical { background:#FEE2E2; color:#991B1B; }
.fp-ok   { background:#EAF3DE; color:#3B6D11; }
.fp-na   { background:#F3F4F6; color:#6B7280; }
canvas { max-height:260px; }
.section-label { font-size:12px; font-weight:600; color:#5f5e5a; text-transform:uppercase; letter-spacing:.05em; margin:6px 0 12px; }
.insight-box { background:#EFF6FF; border:1px solid #BFDBFE; border-radius:8px; padding:12px 16px; font-size:13px; color:#1E40AF; line-height:1.6; }
.dosr-banner { display:flex; justify-content:space-between; align-items:center; gap:14px; background:#FFF7ED; border:1px solid #FED7AA; color:#7C2D12; border-radius:8px; padding:14px 16px; margin-bottom:14px; font-size:13px; line-height:1.5; }
.dosr-banner strong { color:#9A3412; }
.dosr-banner button { padding:8px 12px; background:#C2410C; color:#fff; border:0; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; white-space:nowrap; }
.notice-ok { background:#DCFCE7; color:#14532D; border:1px solid #86EFAC; border-radius:8px; padding:12px 14px; margin-bottom:14px; font-size:13px; }
.gen-req-btn { display:inline-flex; align-items:center; gap:8px; padding:10px 20px; background:#185FA5; color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; margin-top:12px; }
.gen-req-btn:hover { background:#0c447c; }
.exec-badge { display:inline-block; background:#1e3a5f; color:#fff; font-size:10px; font-weight:700; padding:2px 8px; border-radius:10px; text-transform:uppercase; letter-spacing:.5px; margin-left:8px; }
.vuln-row { display:flex; justify-content:space-between; align-items:center; padding:7px 0; border-bottom:1px solid #f1efe8; font-size:13px; }
.vuln-row:last-child { border-bottom:none; }
.perf-tbl { width:100%; border-collapse:collapse; }
.perf-tbl th { text-align:left; font-size:11px; color:#5f5e5a; text-transform:uppercase; padding:6px 8px; border-bottom:1px solid #e0ded5; }
.perf-tbl td { padding:7px 8px; font-size:13px; color:#2c2c2a; border-bottom:1px solid #f1efe8; }
.perf-tbl tr:last-child td { border-bottom:none; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div class="tb-title">
        Analytics &amp; Forecast
        <?php if (isSuperAdmin()): ?>
        <span class="exec-badge">Executive View</span>
        <?php else: ?>
        <span style="font-size:11px;color:#888780;font-weight:400;margin-left:8px;">Operational · <?= htmlspecialchars($stationName) ?></span>
        <?php endif; ?>
    </div>
    <div class="tb-sub">
        <?= isSuperAdmin()
            ? 'Budget allocations · Community vulnerability · Admin performance · Requisition approvals'
            : 'Stock depletion forecasts · Seasonal medicine demand · Generate supply requisitions' ?>
    </div>
  </div>
  <div class="content">
    <?php if ($analyticsMsg): ?>
      <div class="notice-ok"><?= htmlspecialchars($analyticsMsg) ?></div>
    <?php endif; ?>

    <!-- Filter bar -->
    <form method="GET" class="filter-bar">
      <?php if (isSuperAdmin()): ?>
      <div>
        <label>Barangay Station</label>
        <select name="station_id">
          <?php foreach ($stations as $s): ?>
            <option value="<?= $s['id'] ?>" <?= (int)$s['id']===$selectedStation?'selected':'' ?>>
              <?= htmlspecialchars($s['barangay_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div>
        <label>Medicine</label>
        <select name="medicine_id">
          <?php foreach ($medicines as $m): ?>
            <option value="<?= $m['id'] ?>" <?= (int)$m['id']===$selectedMedicine?'selected':'' ?>>
              <?= htmlspecialchars($m['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>History (months)</label>
        <select name="months">
          <?php foreach ([3,6,10,12,18,24] as $mo): ?>
            <option value="<?= $mo ?>" <?= $mo===$months?'selected':'' ?>><?= $mo ?> months</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>&nbsp;</label><button type="submit">Apply</button></div>
    </form>

    <?php if (isSuperAdmin()): ?>
    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- SUPERADMIN — EXECUTIVE VIEW                                 -->
    <!-- ═══════════════════════════════════════════════════════════ -->

    <!-- Executive KPI cards -->
    <div class="g3" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));">
      <div class="mc">
        <div class="mc-l">Total Units Distributed</div>
        <div class="mc-v"><?= number_format((int)$totalUnitsAllTime) ?></div>
        <div class="mc-s">All stations · All time</div>
      </div>
      <div class="mc">
        <div class="mc-l">Pending Requisitions</div>
        <div class="mc-v" style="color:<?= ($reqSummary['pending'] ?? 0) > 0 ? '#854F0B' : '#2c2c2a' ?>"><?= (int)($reqSummary['pending'] ?? 0) ?></div>
        <div class="mc-s"><a href="requisitions.php" style="color:#185FA5;font-size:11px;">Approve / Deny &rarr;</a></div>
      </div>
      <div class="mc">
        <div class="mc-l">Approved Requisitions</div>
        <div class="mc-v"><?= (int)($reqSummary['approved'] ?? 0) ?></div>
        <div class="mc-s"><?= (int)($reqSummary['rejected'] ?? 0) ?> rejected</div>
      </div>
      <div class="mc">
        <div class="mc-l">Stations w/ Stockout Risk</div>
        <div class="mc-v" style="color:<?= count($vulnerability) > 0 ? '#A32D2D' : '#3B6D11' ?>"><?= count($vulnerability) ?></div>
        <div class="mc-s">Currently out of stock</div>
      </div>
      <div class="mc">
        <div class="mc-l">Registered Stations</div>
        <div class="mc-v"><?= count($stations) ?></div>
        <div class="mc-s">Active barangay health stations</div>
      </div>
    </div>

    <!-- Trend for selected station (superadmin can also drill in) -->
    <div class="card" style="margin-bottom:14px;">
      <div class="card-h">
        Demand Trend &amp; Forecast
        <span style="font-size:11px;color:#888780;font-weight:400;"><?= htmlspecialchars($medName) ?> · <?= htmlspecialchars($stationName) ?></span>
      </div>
      <?php if (count($snapshotRows) < 2): ?>
        <p style="color:#888780;font-size:13px;text-align:center;padding:30px 0;">
          Not enough snapshot data for this medicine/station combination.
        </p>
      <?php else: ?>
        <canvas id="trendChart"></canvas>
        <?php if ($daysOfStockRemaining !== null && $daysOfStockRemaining <= 30): ?>
        <div class="dosr-banner" style="margin-top:12px;">
          <div>
            <strong>DoSR Alert:</strong>
            <?= htmlspecialchars($stationName) ?> has approximately
            <strong><?= $daysOfStockRemaining ?> day(s)</strong> of
            <strong><?= htmlspecialchars($medName) ?></strong> remaining.
          </div>
          <form method="POST">
            <input type="hidden" name="action" value="draft_dosr_notification">
            <button type="submit">Draft Alert</button>
          </form>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Executive charts grid -->
    <div class="g2">
      <div class="card">
        <div class="card-h">Distribution by Station <span style="font-size:11px;color:#888780;font-weight:400;">Last 6 months</span></div>
        <?php if ($allStationDist): ?>
          <canvas id="stationChart"></canvas>
        <?php else: ?>
          <p style="color:#888780;font-size:13px;text-align:center;padding:30px 0;">No snapshot data yet.</p>
        <?php endif; ?>
      </div>
      <div class="card">
        <div class="card-h">Community Vulnerability Mapping</div>
        <?php if (empty($vulnerability)): ?>
          <p style="color:#3B6D11;font-size:13px;padding:10px 0;"><i class="bi bi-check-circle-fill"></i> No stations currently in stockout.</p>
        <?php else: ?>
          <?php foreach ($vulnerability as $v): ?>
          <div class="vuln-row">
            <span><?= htmlspecialchars($v['barangay_name']) ?></span>
            <span class="badge br"><?= $v['stockout_count'] ?> stockout<?= $v['stockout_count']>1?'s':'' ?></span>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="g2">
      <div class="card">
        <div class="card-h">Admin Performance Log <span style="font-size:11px;color:#888780;font-weight:400;">Requisitions per station</span></div>
        <?php if (empty($adminPerf)): ?>
          <p style="color:#888780;font-size:13px;padding:10px 0;">No requisitions submitted yet.</p>
        <?php else: ?>
          <canvas id="perfChart"></canvas>
          <table class="perf-tbl" style="margin-top:14px;">
            <tr><th>Barangay</th><th>Total</th><th>Approved</th><th>Rejected</th></tr>
            <?php foreach ($adminPerf as $p): ?>
            <tr>
              <td><?= htmlspecialchars($p['barangay_name']) ?></td>
              <td><?= $p['total_req'] ?></td>
              <td><span class="badge bg"><?= $p['approved'] ?></span></td>
              <td><span class="badge br"><?= $p['rejected'] ?></span></td>
            </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>
      <div class="card">
        <div class="card-h">Top Requested Medicines</div>
        <?php if ($topMeds): ?>
          <canvas id="topMedsChart"></canvas>
        <?php else: ?>
          <p style="color:#888780;font-size:13px;text-align:center;padding:30px 0;">No requests recorded yet.</p>
        <?php endif; ?>
      </div>
    </div>

    <?php else: ?>
    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- BARANGAY ADMIN — OPERATIONAL VIEW                          -->
    <!-- ═══════════════════════════════════════════════════════════ -->

    <!-- Operational metric cards -->
    <div class="g3">
      <div class="mc">
        <div class="mc-l">Avg Monthly Demand</div>
        <div class="mc-v"><?= $avgMonthlyDist > 0 ? round($avgMonthlyDist) : '—' ?> units</div>
        <div class="mc-s"><?= htmlspecialchars($medName) ?> · <?= htmlspecialchars($stationName) ?></div>
      </div>
      <div class="mc">
        <div class="mc-l">Days of Stock Remaining</div>
        <div class="mc-v">
          <?php if ($daysOfStockRemaining !== null): ?>
            <?= $daysOfStockRemaining ?> days
          <?php else: ?>—<?php endif; ?>
        </div>
        <div class="mc-s">
          <?php if ($daysOfStockRemaining !== null): ?>
            <span class="forecast-pill <?= $daysOfStockRemaining <= 7 ? 'fp-critical' : ($daysOfStockRemaining <= 30 ? 'fp-warn' : 'fp-ok') ?>">
              <?= $daysOfStockRemaining <= 7 ? 'Critical — reorder now' : ($daysOfStockRemaining <= 30 ? 'Order soon' : 'Stock adequate') ?>
            </span>
          <?php else: ?>
            <span class="forecast-pill fp-na">Insufficient data</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="mc">
        <div class="mc-l">Current Closing Stock</div>
        <div class="mc-v">
          <?php echo $lastSnapshot ? (int)$lastSnapshot['closing_quantity'] : '—'; ?> units
        </div>
        <div class="mc-s">As of <?= $lastSnapshot ? date('M Y', strtotime($lastSnapshot['snapshot_month'].'-01')) : '—' ?></div>
      </div>
    </div>

    <!-- Main trend + forecast chart -->
    <div class="card" style="margin-bottom:14px;">
      <div class="card-h">
        Demand Trend &amp; 3-Month Forecast
        <span style="font-size:11px;color:#888780;font-weight:400;">
          <?= htmlspecialchars($medName) ?> · <?= htmlspecialchars($stationName) ?>
        </span>
      </div>
      <?php if (count($snapshotRows) < 2): ?>
        <p style="color:#888780;font-size:13px;text-align:center;padding:30px 0;">
          Not enough snapshot data yet for this medicine/station combination.<br>
          Snapshots are recorded monthly via the Monthly Report page.
        </p>
      <?php else: ?>
        <canvas id="trendChart"></canvas>

        <?php if ($daysOfStockRemaining !== null && $daysOfStockRemaining <= 30): ?>
        <div class="dosr-banner" style="margin-top:12px;">
          <div>
            <strong>Reorder Alert:</strong>
            Based on average monthly demand of <strong><?= round($avgMonthlyDist) ?> units</strong>,
            your stock of <strong><?= htmlspecialchars($medName) ?></strong> is estimated to last
            approximately <strong><?= $daysOfStockRemaining ?> day(s)</strong>.
            Generate a requisition now to prevent a stockout.
          </div>
        </div>
        <?php endif; ?>

        <?php if ($daysOfStockRemaining !== null): ?>
        <form method="POST" style="margin-top:14px;">
          <input type="hidden" name="action" value="generate_requisition">
          <input type="hidden" name="medicine_id" value="<?= $selectedMedicine ?>">
          <input type="hidden" name="station_id" value="<?= $selectedStation ?>">
          <input type="hidden" name="months" value="<?= $months ?>">
          <button type="submit" class="gen-req-btn">
            <i class="bi bi-file-earmark-plus"></i> Generate Requisition Request
          </button>
          <div style="font-size:11px;color:#5f5e5a;margin-top:6px;">
            System will calculate the suggested quantity and submit it to City Health for approval.
          </div>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Bottom row: distribution + seasonal demand -->
    <div class="g2">
      <div class="card">
        <div class="card-h">Station Distribution vs. Received <span style="font-size:11px;color:#888780;font-weight:400;">Last 6 months · All stations</span></div>
        <?php if ($allStationDist): ?>
          <canvas id="stationChart"></canvas>
        <?php else: ?>
          <p style="color:#888780;font-size:13px;text-align:center;padding:30px 0;">No snapshot data yet.</p>
        <?php endif; ?>
      </div>
      <div class="card">
        <div class="card-h">Seasonal / High-Demand Medicines</div>
        <?php if ($topMeds): ?>
          <canvas id="topMedsChart"></canvas>
        <?php else: ?>
          <p style="color:#888780;font-size:13px;text-align:center;padding:30px 0;">No requests recorded yet.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Station overall monthly total -->
    <div class="card" style="margin-bottom:14px;">
      <div class="card-h">
        Overall Monthly Distribution · <?= htmlspecialchars($stationName) ?>
        <span style="font-size:11px;color:#888780;font-weight:400;">All medicines combined</span>
      </div>
      <?php if ($stationMonthly): ?>
        <canvas id="stMonthlyChart"></canvas>
      <?php else: ?>
        <p style="color:#888780;font-size:13px;text-align:center;padding:30px 0;">No data yet.</p>
      <?php endif; ?>
    </div>

    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->

<script>
const C = {
  blue:    '#185FA5',
  green:   '#3B6D11',
  amber:   '#854F0B',
  red:     '#A32D2D',
  lblue:   'rgba(24,95,165,.12)',
  lgreen:  'rgba(59,109,17,.12)',
  dash:    [6,4],
  gridC:   '#e0ded5',
  font:    '-apple-system, sans-serif',
};

Chart.defaults.font.family = C.font;
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#5f5e5a';

// ── Trend + forecast ─────────────────────────────────────────────
<?php if (count($snapshotRows) >= 2): ?>
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: <?= $jsLabels ?>,
    datasets: [
      {
        label: 'Units Distributed',
        data:  <?= $jsDist ?>,
        borderColor: C.blue,
        backgroundColor: C.lblue,
        fill: true,
        tension: 0.35,
        pointRadius: 4,
        spanGaps: false,
      },
      {
        label: 'Closing Stock',
        data:  <?= $jsClosing ?>,
        borderColor: C.green,
        backgroundColor: 'transparent',
        tension: 0.35,
        pointRadius: 3,
        borderDash: C.dash,
        spanGaps: false,
      },
      {
        label: 'Forecast (demand)',
        data:  <?= $jsForecast ?>,
        borderColor: C.amber,
        backgroundColor: 'rgba(133,79,11,.1)',
        fill: true,
        tension: 0.2,
        pointRadius: 5,
        pointStyle: 'rectRot',
        borderDash: [4,3],
        spanGaps: true,
      },
    ]
  },
  options: {
    responsive: true,
    interaction: { mode:'index', intersect:false },
    plugins: {
      legend: { position:'top' },
      tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + (ctx.parsed.y ?? '—') + ' units' } }
    },
    scales: {
      x: { grid: { color: C.gridC } },
      y: { beginAtZero:true, grid: { color: C.gridC }, ticks:{ stepSize:50 } }
    }
  }
});
<?php endif; ?>

// ── Station comparison ────────────────────────────────────────────
<?php if ($allStationDist): ?>
new Chart(document.getElementById('stationChart'), {
  type: 'bar',
  data: {
    labels: <?= $jsAllStationNames ?>,
    datasets: [
      { label:'Distributed', data: <?= $jsAllStationDist ?>, backgroundColor: C.blue, borderRadius: 6 },
      { label:'Received',    data: <?= $jsAllStationRecv ?>, backgroundColor: C.lblue, borderColor: C.blue, borderWidth:1, borderRadius: 6 },
    ]
  },
  options: {
    responsive:true,
    plugins:{ legend:{ position:'top' } },
    scales:{ x:{ grid:{ color:C.gridC } }, y:{ beginAtZero:true, grid:{ color:C.gridC } } }
  }
});
<?php endif; ?>

// ── Top medicines ─────────────────────────────────────────────────
<?php if ($topMeds): ?>
new Chart(document.getElementById('topMedsChart'), {
  type: 'bar',
  data: {
    labels: <?= $jsTopMedNames ?>,
    datasets:[{
      label:'Requests',
      data: <?= $jsTopMedCounts ?>,
      backgroundColor: ['#185FA5','#3B6D11','#854F0B','#A32D2D','#6B7280','#0891B2','#7C3AED','#D97706'],
      borderRadius: 6,
    }]
  },
  options: {
    indexAxis: 'y',
    responsive:true,
    plugins:{ legend:{ display:false } },
    scales:{ x:{ beginAtZero:true, grid:{ color:C.gridC } }, y:{ grid:{ display:false } } }
  }
});
<?php endif; ?>

// ── Station monthly total (brgy admin) ────────────────────────────
<?php if (!isSuperAdmin() && $stationMonthly): ?>
new Chart(document.getElementById('stMonthlyChart'), {
  type: 'bar',
  data: {
    labels: <?= $jsStMLabels ?>,
    datasets:[{
      label:'Total Units Distributed',
      data: <?= $jsStMDist ?>,
      backgroundColor: C.blue,
      borderRadius: 6,
    }]
  },
  options:{
    responsive:true,
    plugins:{ legend:{ display:false } },
    scales:{ x:{ grid:{ color:C.gridC } }, y:{ beginAtZero:true, grid:{ color:C.gridC } } }
  }
});
<?php endif; ?>

// ── Superadmin: Admin performance bar ────────────────────────────
<?php if (isSuperAdmin() && !empty($adminPerf)): ?>
new Chart(document.getElementById('perfChart'), {
  type: 'bar',
  data: {
    labels: <?= $jsPerfLabels ?>,
    datasets: [
      { label:'Approved', data: <?= $jsPerfApproved ?>, backgroundColor: '#3B6D11', borderRadius:6 },
      { label:'Rejected', data: <?= $jsPerfRejected ?>, backgroundColor: '#A32D2D', borderRadius:6 },
    ]
  },
  options: {
    responsive:true,
    plugins:{ legend:{ position:'top' } },
    scales:{ x:{ grid:{ color:C.gridC } }, y:{ beginAtZero:true, grid:{ color:C.gridC } } }
  }
});
<?php endif; ?>
</script>
</body>
</html>
