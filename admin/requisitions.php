<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
$admin = currentAdmin();
$pdo   = getDB();

// ── DB migrations (safe) ──────────────────────────────────────────
foreach ([
  "ALTER TABLE requisitions MODIFY COLUMN medicine_id INT DEFAULT NULL",
  "ALTER TABLE requisitions ADD COLUMN custom_medicine_name VARCHAR(150) DEFAULT NULL",
  "ALTER TABLE requisitions ADD COLUMN reason_description TEXT DEFAULT NULL",
] as $sql) { try { $pdo->exec($sql); } catch (Exception $e) {} }

// ── Harden station_id ─────────────────────────────────────────────
$sid = null;
if (isBarangayAdmin()) {
    $sid = (int)$admin['station_id'];
    if (!$sid) {
        $sf = $pdo->prepare("SELECT station_id FROM admins WHERE id=?");
        $sf->execute([$admin['id']]);
        $sid = (int)$sf->fetchColumn();
        $_SESSION['admin_station_id'] = $sid;
    }
}

$msg  = '';
$errs = [];

// ── POST HANDLERS ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // BRGY: submit new requisition
    if ($action === 'submit' && isBarangayAdmin()) {
        $medId    = isset($_POST['medicine_id']) && $_POST['medicine_id'] !== '' ? (int)$_POST['medicine_id'] : null;
        $custom   = trim($_POST['custom_medicine_name'] ?? '');
        $qty      = (int)($_POST['requested_qty'] ?? 0);
        $reason   = trim($_POST['reason_description'] ?? '');

        if (!$medId && $custom === '')    $errs[] = 'Please select a medicine or enter a custom medicine name.';
        if ($qty <= 0)                    $errs[] = 'Quantity must be greater than 0.';
        if ($reason === '')               $errs[] = 'Please provide a reason for this request.';

        if (!$errs) {
            $pdo->prepare("
                INSERT INTO requisitions
                  (station_id, medicine_id, custom_medicine_name, requested_qty, reason_description, submitted_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$sid, $medId ?: null, $medId ? null : $custom, $qty, $reason, $admin['id']]);

            $rid = $pdo->lastInsertId();
            $mname = $custom;
            if ($medId) {
                $mname = $pdo->prepare("SELECT name FROM medicines WHERE id=?");
                $mname->execute([$medId]);
                $mname = $mname->fetchColumn();
            }
            $sname = $pdo->prepare("SELECT barangay_name FROM health_stations WHERE id=?");
            $sname->execute([$sid]);
            $sname = $sname->fetchColumn();

            logActivity('requisition_submitted',
                "{$admin['full_name']} submitted requisition for {$qty} units of {$mname} from {$sname}. Reason: {$reason}",
                $sid, null, null, $rid, 'requisitions');

            $msg = "Requisition #$rid submitted to City Health. You will be notified of the decision.";
        }
    }

    // SUPERADMIN: decide on a requisition
    elseif ($action === 'decide' && isSuperAdmin()) {
        $reqId    = (int)($_POST['req_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        $reason   = trim($_POST['reason'] ?? '');
        $appQty   = (int)($_POST['approved_qty'] ?? 0);

        if (!in_array($decision, ['approve_full','approve_partial','reject']))
            $errs[] = 'Invalid decision.';
        elseif (in_array($decision, ['approve_partial','reject']) && $reason === '')
            $errs[] = 'A reason is required for partial approvals and rejections.';
        elseif ($decision === 'approve_partial' && $appQty <= 0)
            $errs[] = 'Enter the approved quantity for partial approval.';
        else {
            $stmt = $pdo->prepare("SELECT * FROM requisitions WHERE id=?");
            $stmt->execute([$reqId]);
            $req = $stmt->fetch();
            if (!$req || $req['status'] !== 'pending') {
                $errs[] = 'Requisition not found or already decided.';
            } else {
                $now = date('Y-m-d H:i:s');
                if ($decision === 'approve_full') {
                    $pdo->prepare("UPDATE requisitions SET status='approved', approved_qty=?, decided_by=?, decided_at=? WHERE id=?")
                        ->execute([$req['requested_qty'], $admin['id'], $now, $reqId]);
                    $lt = 'requisition_approved';
                    $ld = "{$admin['full_name']} fully approved requisition #{$reqId} ({$req['requested_qty']} units).";
                } elseif ($decision === 'approve_partial') {
                    $pdo->prepare("UPDATE requisitions SET status='partial', approved_qty=?, rejection_reason=?, decided_by=?, decided_at=? WHERE id=?")
                        ->execute([$appQty, $reason, $admin['id'], $now, $reqId]);
                    $lt = 'requisition_partial';
                    $ld = "{$admin['full_name']} partially approved requisition #{$reqId} ({$appQty}/{$req['requested_qty']} units). {$reason}";
                } else {
                    $pdo->prepare("UPDATE requisitions SET status='rejected', rejection_reason=?, decided_by=?, decided_at=? WHERE id=?")
                        ->execute([$reason, $admin['id'], $now, $reqId]);
                    $lt = 'requisition_rejected';
                    $ld = "{$admin['full_name']} rejected requisition #{$reqId}. Reason: {$reason}";
                }
                logActivity($lt, $ld, $req['station_id'], null, null, $reqId, 'requisitions');
                $msg = 'Decision recorded successfully.';
            }
        }
    }

    // BRGY: confirm delivery received
    elseif ($action === 'confirm_delivery' && isBarangayAdmin()) {
        $reqId = (int)($_POST['req_id'] ?? 0);
        $stmt  = $pdo->prepare("SELECT * FROM requisitions WHERE id=? AND station_id=? AND status IN ('approved','partial')");
        $stmt->execute([$reqId, $sid]);
        $req = $stmt->fetch();
        if (!$req) {
            $errs[] = 'Requisition not found or not approved.';
        } else {
            $qtyToAdd = $req['approved_qty'];
            $medId    = $req['medicine_id'];
            if (!$medId && !empty($req['custom_medicine_name'])) {
                $ch = $pdo->prepare("SELECT id FROM medicines WHERE name=?");
                $ch->execute([$req['custom_medicine_name']]);
                $medId = $ch->fetchColumn();
                if (!$medId) {
                    $pdo->prepare("INSERT INTO medicines (name, category) VALUES (?, 'Requisitioned')")
                        ->execute([$req['custom_medicine_name']]);
                    $medId = (int)$pdo->lastInsertId();
                }
                $pdo->prepare("UPDATE requisitions SET medicine_id=? WHERE id=?")->execute([$medId, $reqId]);
            }
            $stmtQ = $pdo->prepare("SELECT quantity FROM inventory WHERE station_id=? AND medicine_id=?");
            $stmtQ->execute([$req['station_id'], $medId]);
            $qtyBefore = (int)($stmtQ->fetchColumn() ?: 0);
            $pdo->prepare("INSERT INTO inventory (station_id, medicine_id, quantity) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)")
                ->execute([$req['station_id'], $medId, $qtyToAdd]);
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE requisitions SET status='delivered', delivery_confirmed_by=?, delivery_confirmed_at=? WHERE id=?")
                ->execute([$admin['id'], $now, $reqId]);

            $mn = $pdo->prepare("SELECT name FROM medicines WHERE id=?");
            $mn->execute([$medId]);
            $mnStr = $mn->fetchColumn() ?: $req['custom_medicine_name'];

            logActivity('requisition_delivered',
                "{$admin['full_name']} confirmed delivery of {$qtyToAdd} units of {$mnStr} (Req #{$reqId}). Stock: {$qtyBefore}→".($qtyBefore+$qtyToAdd).".",
                $req['station_id'], $qtyBefore, $qtyBefore+$qtyToAdd, $reqId, 'requisitions');
            $msg = "Delivery confirmed! {$qtyToAdd} units of {$mnStr} added to your inventory.";
        }
    }

    header('Location: requisitions.php?msg='.urlencode($msg));
    exit;
}

$msg = $_GET['msg'] ?? $msg;

// ── Fetch medicines for form ──────────────────────────────────────
$medicines = $pdo->query("SELECT id, name FROM medicines ORDER BY name")->fetchAll();

// ── Fetch low/out-of-stock for the brgy admin warning ────────────
if (isBarangayAdmin()) {
    $s = $pdo->prepare("
        SELECT m.name, i.status, i.quantity
        FROM inventory i JOIN medicines m ON m.id=i.medicine_id
        WHERE i.status IN ('low_stock','out_of_stock') AND i.station_id=?
        ORDER BY FIELD(i.status,'out_of_stock','low_stock')
    ");
    $s->execute([$sid]);
    $stockWarnings = $s->fetchAll();

    $sn = $pdo->prepare("SELECT barangay_name FROM health_stations WHERE id=?");
    $sn->execute([$sid]);
    $stationName = $sn->fetchColumn() ?: 'Your Barangay';
}

// ── Fetch requisitions list ───────────────────────────────────────
if (isSuperAdmin()) {
    $requisitions = $pdo->query("
        SELECT r.*, COALESCE(m.name, r.custom_medicine_name) AS medicine_name,
               h.barangay_name, a1.full_name AS submitted_by_name, a2.full_name AS decided_by_name
        FROM requisitions r
        LEFT JOIN medicines m  ON m.id = r.medicine_id
        JOIN health_stations h ON h.id = r.station_id
        JOIN admins a1         ON a1.id = r.submitted_by
        LEFT JOIN admins a2    ON a2.id = r.decided_by
        ORDER BY FIELD(r.status,'pending','approved','partial','delivered','rejected'), r.created_at DESC
    ")->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT r.*, COALESCE(m.name, r.custom_medicine_name) AS medicine_name,
               h.barangay_name, a1.full_name AS submitted_by_name, a2.full_name AS decided_by_name
        FROM requisitions r
        LEFT JOIN medicines m  ON m.id = r.medicine_id
        JOIN health_stations h ON h.id = r.station_id
        JOIN admins a1         ON a1.id = r.submitted_by
        LEFT JOIN admins a2    ON a2.id = r.decided_by
        WHERE r.station_id=?
        ORDER BY FIELD(r.status,'pending','approved','partial','delivered','rejected'), r.created_at DESC
    ");
    $stmt->execute([$sid]);
    $requisitions = $stmt->fetchAll();
}

$statusCfg = [
    'pending'   => ['ba','Pending'],
    'approved'  => ['bg','Approved'],
    'partial'   => ['bb','Partial'],
    'rejected'  => ['br','Rejected'],
    'delivered' => ['bg','Delivered'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Requisitions — BayaniServe</title>
<?php include __DIR__ . '/includes/layout_head.php'; ?>
<style>
.req-submit-card { max-width:620px; }
.flow-steps {
  display:flex; gap:0; margin-bottom:24px; border-radius:var(--radius);
  overflow:hidden; border:1px solid var(--border);
}
.flow-step {
  flex:1; padding:12px 16px; background:var(--surface); text-align:center;
  font-size:12px; color:var(--text3); border-right:1px solid var(--border);
  position:relative;
}
.flow-step:last-child { border-right:none; }
.flow-step.active { background:var(--blue-lt); color:var(--blue); font-weight:700; }
.flow-step.done   { background:var(--green-lt); color:var(--green); }
.flow-num { display:block; font-size:18px; font-weight:800; margin-bottom:2px; }

/* Decision form inline */
.decide-panel {
  background:var(--bg); border:1px solid var(--border);
  border-radius:8px; padding:14px; margin-top:8px;
}
.decide-panel select, .decide-panel input, .decide-panel textarea {
  font-size:12px; padding:6px 10px; border:1px solid var(--border);
  border-radius:6px; background:var(--surface); margin-bottom:8px;
  width:100%; font-family:inherit;
}
.decide-panel label { font-size:11px; font-weight:700; color:var(--text2);
                      display:block; margin-bottom:4px; text-transform:uppercase; }

/* Status timeline dots */
.tl-dot { width:10px; height:10px; border-radius:50%; display:inline-block; margin-right:4px; }
.tl-pending  { background:var(--amber); }
.tl-approved { background:var(--green); }
.tl-partial  { background:var(--blue);  }
.tl-rejected { background:var(--red);   }
.tl-delivered{ background:var(--green); }

/* Reason box */
.reason-box {
  background:var(--amber-lt); border:1px solid #f0d09a; border-radius:8px;
  padding:12px 14px; font-size:12px; color:var(--amber); margin-bottom:16px;
}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
  <div class="topbar">
    <div class="tb-title">
      <?= isSuperAdmin() ? '<i class="bi bi-clipboard-pulse"></i> Requisitions — City Health Review' : '<i class="bi bi-clipboard"></i> Supply Requisitions' ?>
    </div>
    <div class="tb-sub">
      <?= isSuperAdmin()
        ? 'Review and decide on supply requests from all barangay health stations'
        : 'Request medicines from City Health when stock is low or depleted' ?>
    </div>
  </div>
  <div class="content">

    <?php if ($msg): ?>
    <div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($errs): ?>
    <div class="alert alert-danger">
      <ul style="margin:0;padding-left:18px;">
        <?php foreach ($errs as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <?php if (isBarangayAdmin()): ?>
    <!-- ─── FLOW STEPS ──────────────────────────────────────── -->
    <div class="flow-steps">
      <div class="flow-step done">
        <span class="flow-num">①</span>Check inventory
      </div>
      <div class="flow-step active">
        <span class="flow-num">②</span>Submit requisition
      </div>
      <div class="flow-step">
        <span class="flow-num">③</span>City Health decides
      </div>
      <div class="flow-step">
        <span class="flow-num">④</span>Confirm delivery
      </div>
    </div>

    <!-- ─── STOCK WARNING ────────────────────────────────────── -->
    <?php if (!empty($stockWarnings)): ?>
    <div class="alert alert-warn" style="margin-bottom:20px;">
      <i class="bi bi-exclamation-triangle-fill"></i> <strong>Stock issues detected in Brgy. <?= htmlspecialchars($stationName) ?>:</strong>
      <?php foreach ($stockWarnings as $sw): ?>
        <span class="badge <?= $sw['status']==='out_of_stock'?'br':'ba' ?>" style="margin-left:6px;">
          <?= htmlspecialchars($sw['name']) ?> — <?= $sw['status']==='out_of_stock'?'Out of Stock':'Low ('.$sw['quantity'].' left)' ?>
        </span>
      <?php endforeach; ?>
      <br><span style="font-size:12px;margin-top:6px;display:block;">Submit a requisition below to request replenishment from City Health.</span>
    </div>
    <?php endif; ?>

    <!-- ─── SUBMIT FORM ──────────────────────────────────────── -->
    <div class="card req-submit-card" style="margin-bottom:24px;">
      <div class="card-h">Submit New Requisition to City Health</div>
      <form method="POST" id="reqForm">
        <input type="hidden" name="action" value="submit">

        <div class="form-group">
          <label class="form-lbl">Medicine *</label>
          <select name="medicine_id" class="form-sel" id="med_sel" onchange="toggleCustom()">
            <option value="">— Select from catalogue —</option>
            <?php foreach ($medicines as $m): ?>
              <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
            <?php endforeach; ?>
            <option value="">— Other (enter name below) —</option>
          </select>
        </div>

        <div class="form-group" id="custom_group" style="display:none;">
          <label class="form-lbl">Custom Medicine Name</label>
          <input type="text" name="custom_medicine_name" class="form-inp"
                 placeholder="e.g. Losartan 50mg, Metformin 500mg">
        </div>

        <div class="form-group">
          <label class="form-lbl">Quantity Requested *</label>
          <input type="number" name="requested_qty" class="form-inp" min="1" placeholder="e.g. 100">
        </div>

        <div class="form-group">
          <label class="form-lbl">Reason / Justification *</label>
          <textarea name="reason_description" class="form-ta" rows="3"
                    placeholder="Explain why this is needed — e.g. current stock is depleted, expected patient load, seasonal demand..."></textarea>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-send-fill"></i> Submit to City Health</button>
      </form>
    </div>
    <script>
    function toggleCustom() {
      var sel = document.getElementById('med_sel');
      var grp = document.getElementById('custom_group');
      grp.style.display = sel.value === '' ? '' : 'none';
    }
    toggleCustom();
    </script>
    <?php endif; ?>

    <!-- ─── REQUISITIONS TABLE ───────────────────────────────── -->
    <div class="card">
      <div class="card-h">
        <?= isSuperAdmin() ? 'All Barangay Requisitions' : 'Your Requisition History' ?>
        <?php
        $pendCount = array_reduce($requisitions, fn($c,$r) => $c + ($r['status']==='pending'?1:0), 0);
        if ($pendCount > 0): ?>
        <span class="badge br"><?= $pendCount ?> pending</span>
        <?php endif; ?>
      </div>

      <?php if (empty($requisitions)): ?>
      <p style="font-size:13px;color:var(--text3);padding:20px 0;text-align:center;">
        No requisitions yet.
        <?php if (isBarangayAdmin()): ?>
        <a href="#reqForm" style="color:var(--blue);">Submit your first requisition →</a>
        <?php endif; ?>
      </p>
      <?php else: ?>
      <div style="overflow-x:auto;">
      <table class="tbl">
        <tr>
          <th>#</th>
          <th>Medicine</th>
          <th>Reason</th>
          <?php if (isSuperAdmin()): ?><th>Barangay</th><?php endif; ?>
          <th>Req. Qty</th>
          <th>Appr. Qty</th>
          <th>Status</th>
          <th>Submitted</th>
          <?php if (isSuperAdmin()): ?><th>Decision</th><?php endif; ?>
          <?php if (isBarangayAdmin()): ?><th>Action</th><?php endif; ?>
        </tr>
        <?php foreach ($requisitions as $r):
          [$badgeClass, $statusLabel] = $statusCfg[$r['status']] ?? ['ba', ucfirst($r['status'])];
        ?>
        <tr>
          <td style="font-size:12px;color:var(--text3);">#<?= $r['id'] ?></td>
          <td>
            <strong><?= htmlspecialchars($r['medicine_name'] ?? '—') ?></strong>
            <?php if (!$r['medicine_id'] && !empty($r['custom_medicine_name'])): ?>
            <span class="badge ba" style="margin-left:4px;font-size:10px;">Custom</span>
            <?php endif; ?>
          </td>
          <td style="max-width:200px;">
            <span style="font-size:12px;color:var(--text2);">
              <?= $r['reason_description'] ? htmlspecialchars(mb_strimwidth($r['reason_description'],0,80,'…')) : '<span style="color:var(--text3);">—</span>' ?>
            </span>
          </td>
          <?php if (isSuperAdmin()): ?>
          <td><span style="font-size:12px;"><i class="bi bi-geo-alt-fill text-muted"></i> <?= htmlspecialchars($r['barangay_name']) ?></span></td>
          <?php endif; ?>
          <td><strong><?= $r['requested_qty'] ?></strong></td>
          <td><?= $r['approved_qty'] ?? '<span style="color:var(--text3);">—</span>' ?></td>
          <td>
            <span class="badge <?= $badgeClass ?>">
              <span class="tl-dot tl-<?= $r['status'] ?>"></span><?= $statusLabel ?>
            </span>
            <?php if ($r['rejection_reason']): ?>
            <div style="font-size:11px;color:var(--text3);margin-top:4px;max-width:180px;">
              "<?= htmlspecialchars(mb_strimwidth($r['rejection_reason'],0,60,'…')) ?>"
            </div>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:var(--text3);">
            <?= htmlspecialchars($r['submitted_by_name']) ?><br>
            <?= date('M j, Y', strtotime($r['created_at'])) ?>
          </td>

          <?php if (isSuperAdmin()): ?>
          <td style="min-width:220px;">
            <?php if ($r['status'] === 'pending'): ?>
            <div class="decide-panel">
              <form method="POST">
                <input type="hidden" name="action"  value="decide">
                <input type="hidden" name="req_id"  value="<?= $r['id'] ?>">
                <label>Decision</label>
                <select name="decision" onchange="toggleDecide(this,'<?= $r['id'] ?>')">
                  <option value="">— Choose —</option>
                  <option value="approve_full">Approve Full (<?= $r['requested_qty'] ?> units)</option>
                  <option value="approve_partial">Approve Partial</option>
                  <option value="reject">Reject</option>
                </select>
                <div id="aqty_wrap_<?= $r['id'] ?>" style="display:none;">
                  <label>Approved Qty</label>
                  <input type="number" name="approved_qty" id="aqty_<?= $r['id'] ?>" min="1" max="<?= $r['requested_qty'] ?>" placeholder="Units to approve">
                </div>
                <div id="reason_wrap_<?= $r['id'] ?>" style="display:none;">
                  <label>Reason</label>
                  <textarea name="reason" id="reason_<?= $r['id'] ?>" rows="2" placeholder="Explain your decision..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">Submit Decision</button>
              </form>
            </div>
            <?php else: ?>
            <span style="font-size:12px;color:var(--text3);">
              <?= $r['decided_by_name'] ? htmlspecialchars($r['decided_by_name']) : '—' ?>
              <?= $r['decided_at'] ? '<br>'.date('M j, Y',strtotime($r['decided_at'])) : '' ?>
            </span>
            <?php endif; ?>
          </td>
          <?php endif; ?>

          <?php if (isBarangayAdmin()): ?>
          <td>
            <?php if (in_array($r['status'], ['approved','partial'])): ?>
            <form method="POST"
                  onsubmit="return confirm('Confirm delivery of <?= $r['approved_qty'] ?> units?')">
              <input type="hidden" name="action"  value="confirm_delivery">
              <input type="hidden" name="req_id"  value="<?= $r['id'] ?>">
              <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-truck"></i> Confirm Delivery</button>
            </form>
            <?php else: ?>
            <span style="font-size:12px;color:var(--text3);">—</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </table>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /content -->
</main>

<script>
function toggleDecide(sel, id) {
  var v = sel.value;
  document.getElementById('aqty_wrap_'+id).style.display   = v==='approve_partial' ? '' : 'none';
  document.getElementById('reason_wrap_'+id).style.display = (v==='approve_partial'||v==='reject') ? '' : 'none';
}
</script>
</body>
</html>
