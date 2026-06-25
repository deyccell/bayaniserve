<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
$admin = currentAdmin();
$pdo   = getDB();

function ensureEmergencyTables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `emergency_mode` (
          `id`              int(11)      NOT NULL AUTO_INCREMENT,
          `is_active`       tinyint(1)   NOT NULL DEFAULT 0,
          `label`           varchar(150) NOT NULL DEFAULT 'Emergency Mode',
          `description`     text         DEFAULT NULL,
          `activated_by`    int(11)      DEFAULT NULL,
          `activated_at`    datetime     DEFAULT NULL,
          `deactivated_by`  int(11)      DEFAULT NULL,
          `deactivated_at`  datetime     DEFAULT NULL,
          `per_hh_limit`    int(11)      NOT NULL DEFAULT 5,
          `bypass_approval` tinyint(1)   NOT NULL DEFAULT 1,
          PRIMARY KEY (`id`),
          KEY `activated_by` (`activated_by`),
          KEY `deactivated_by` (`deactivated_by`),
          CONSTRAINT `em_activated_by` FOREIGN KEY (`activated_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
          CONSTRAINT `em_deactivated_by` FOREIGN KEY (`deactivated_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `emergency_distributions` (
          `id`              int(11)      NOT NULL AUTO_INCREMENT,
          `station_id`      int(11)      NOT NULL,
          `medicine_id`     int(11)      NOT NULL,
          `household_rep`   varchar(150) NOT NULL,
          `mobile_number`   varchar(20)  DEFAULT NULL,
          `address`         varchar(255) DEFAULT NULL,
          `quantity`        int(11)      NOT NULL DEFAULT 1,
          `distributed_by`  int(11)      NOT NULL,
          `notes`           text         DEFAULT NULL,
          `created_at`      timestamp    NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `station_id` (`station_id`),
          KEY `medicine_id` (`medicine_id`),
          KEY `distributed_by` (`distributed_by`),
          CONSTRAINT `ed_station` FOREIGN KEY (`station_id`) REFERENCES `health_stations` (`id`) ON DELETE CASCADE,
          CONSTRAINT `ed_medicine` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE,
          CONSTRAINT `ed_by` FOREIGN KEY (`distributed_by`) REFERENCES `admins` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        INSERT IGNORE INTO `emergency_mode` (`id`, `is_active`, `label`)
        VALUES (1, 0, 'Emergency Mode')
    ");
}

ensureEmergencyTables($pdo);

// ── Load emergency state (single row, id=1) ───────────────────────
$em = $pdo->query("SELECT * FROM emergency_mode WHERE id=1")->fetch();

if (!$em) {
    $em = [
        'is_active' => 0,
        'label' => 'Emergency Mode',
        'description' => '',
        'activated_at' => null,
        'deactivated_at' => null,
        'per_hh_limit' => 5,
        'bypass_approval' => 1
    ];
}

$msg = ''; $err = '';

// ── POST handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ACTIVATE / DEACTIVATE — super_admin only
    if (in_array($action, ['activate','deactivate']) && isSuperAdmin()) {
        $isActivating = ($action === 'activate');
        $label  = trim($_POST['label'] ?? 'Emergency Mode');
        $desc   = trim($_POST['description'] ?? '');
        $limit  = max(1, (int)($_POST['per_hh_limit'] ?? 5));
        $bypass = (int)(isset($_POST['bypass_approval']) && $_POST['bypass_approval'] === '1');

        if ($isActivating) {
            $pdo->prepare("
                UPDATE emergency_mode SET
                  is_active=1, label=?, description=?, per_hh_limit=?,
                  bypass_approval=?, activated_by=?, activated_at=NOW(),
                  deactivated_by=NULL, deactivated_at=NULL
                WHERE id=1
            ")->execute([$label, $desc, $limit, $bypass, $admin['id']]);
            logActivity('emergency_activated', "Emergency mode activated: {$label}", null);
            $msg = "Emergency mode is now ACTIVE. Normal approval rules " . ($bypass ? "bypassed." : "still apply.");
        } else {
            $pdo->prepare("
                UPDATE emergency_mode SET
                  is_active=0, deactivated_by=?, deactivated_at=NOW()
                WHERE id=1
            ")->execute([$admin['id']]);
            logActivity('emergency_deactivated', "Emergency mode deactivated.", null);
            $msg = "✓ Emergency mode has been deactivated. Normal operations resumed.";
        }
        // Reload
        $em = $pdo->query("SELECT * FROM emergency_mode WHERE id=1")->fetch() ?: $em;
    }

    // LOG DISTRIBUTION — ONLY for barangay admin (Super Admin block removed)
    if ($action === 'log_dist' && !isSuperAdmin()) {
        if (!$em['is_active']) {
            $err = 'Emergency mode is not active. Cannot log distribution.';
        } else {
            $stationId   = (int)$admin['station_id'];
            $medicineId  = (int)($_POST['medicine_id'] ?? 0);
            $householdRep= trim($_POST['household_rep'] ?? '');
            $mobile      = trim($_POST['mobile_number'] ?? '');
            $address     = trim($_POST['address'] ?? '');
            $qty         = max(1, (int)($_POST['quantity'] ?? 1));
            $notes       = trim($_POST['notes'] ?? '');

            if (!$stationId || !$medicineId || !$householdRep) {
                $err = 'Medicine and household representative name are required.';
            } elseif ($qty > (int)$em['per_hh_limit']) {
                $err = "Quantity exceeds the per-household limit of {$em['per_hh_limit']} units set for this emergency.";
            } else {
                // Check current stock
                $invStmt = $pdo->prepare("SELECT quantity FROM inventory WHERE station_id=? AND medicine_id=?");
                $invStmt->execute([$stationId, $medicineId]);
                $inv = $invStmt->fetch();

                if (!$inv) {
                    $err = 'This medicine is not in stock at your station.';
                } elseif ($inv['quantity'] < $qty) {
                    $err = "Insufficient stock. Only {$inv['quantity']} units available.";
                } else {
                    // Deduct from inventory
                    $oldQty = (int)$inv['quantity'];
                    $newQty = $oldQty - $qty;
                    $pdo->prepare("UPDATE inventory SET quantity=? WHERE station_id=? AND medicine_id=?"
                    )->execute([$newQty, $stationId, $medicineId]);

                    // Log distribution
                    $pdo->prepare("
                        INSERT INTO emergency_distributions
                          (station_id, medicine_id, household_rep, mobile_number, address, quantity, distributed_by, notes)
                        VALUES (?,?,?,?,?,?,?,?)
                    ")->execute([$stationId, $medicineId, $householdRep, $mobile, $address, $qty, $admin['id'], $notes]);

                    // Get medicine name for log
                    $medName = $pdo->prepare("SELECT name FROM medicines WHERE id=?");
                    $medName->execute([$medicineId]);
                    $mn = $medName->fetchColumn();

                    logActivity(
                        'emergency_distribution',
                        "Emergency: distributed {$qty}x {$mn} to household '{$householdRep}'",
                        $stationId, $oldQty, $newQty
                    );
                    $msg = "✓ Distribution logged: {$qty} unit(s) of {$mn} to {$householdRep}.";
                }
            }
        }
    }
}

// ── Load distribution log ─────────────────────────────────────────
$distLog = [];
if ($em['is_active']) {
    $sid = isSuperAdmin() ? null : (int)$admin['station_id'];
    if ($sid) {
        $stmt = $pdo->prepare("
            SELECT ed.*, m.name AS med_name, h.barangay_name, a.full_name AS by_name
            FROM   emergency_distributions ed
            JOIN   medicines m       ON m.id = ed.medicine_id
            JOIN   health_stations h ON h.id = ed.station_id
            JOIN   admins a          ON a.id = ed.distributed_by
            WHERE  ed.station_id = ?
            ORDER  BY ed.created_at DESC
            LIMIT  50
        ");
        $stmt->execute([$sid]);
    } else {
        $stmt = $pdo->query("
            SELECT ed.*, m.name AS med_name, h.barangay_name, a.full_name AS by_name
            FROM   emergency_distributions ed
            JOIN   medicines m       ON m.id = ed.medicine_id
            JOIN   health_stations h ON h.id = ed.station_id
            JOIN   admins a          ON a.id = ed.distributed_by
            ORDER  BY ed.created_at DESC
            LIMIT  100
        ");
    }
    $distLog = $stmt->fetchAll();
}

// Summary stats during active emergency
$distSummary = [];
if ($em['is_active']) {
    $distSummary = $pdo->query("
        SELECT m.name AS med_name, h.barangay_name,
               COUNT(ed.id) AS households_served,
               SUM(ed.quantity) AS total_units
        FROM   emergency_distributions ed
        JOIN   medicines m       ON m.id = ed.medicine_id
        JOIN   health_stations h ON h.id = ed.station_id
        GROUP  BY ed.medicine_id, ed.station_id
        ORDER  BY total_units DESC
    ")->fetchAll();
}

// Lists for form dropdowns
$medicines = $pdo->query("SELECT id, name FROM medicines ORDER BY name")->fetchAll();
$stations  = $pdo->query("SELECT id, barangay_name FROM health_stations ORDER BY barangay_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Emergency Mode — BayaniServe</title>
<?php require_once __DIR__ . '/includes/layout_head.php'; ?>
<style>

.em-banner {

  border-radius:10px; padding:16px 20px; margin-bottom:18px;

  display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;

}

.em-active   { background:#FCEAEA; border:1.5px solid #A32D2D; }

.em-inactive { background:#F3F4F6; border:1.5px solid #D1D5DB; }

.em-title { font-size:16px; font-weight:700; }

.em-active .em-title   { color:#A32D2D; }

.em-inactive .em-title { color:#6B7280; }

.em-sub { font-size:12px; margin-top:2px; }

.em-active .em-sub   { color:#B91C1C; }

.em-inactive .em-sub { color:#9CA3AF; }

.em-badge { font-size:11px; padding:3px 12px; border-radius:20px; font-weight:700; letter-spacing:.03em; }

.em-badge-on  { background:#FEE2E2; color:#991B1B; border:1px solid #FCA5A5; }

.em-badge-off { background:#E5E7EB; color:#6B7280; border:1px solid #D1D5DB; }

.btn-danger   { background:#A32D2D; color:#fff; border:none; padding:8px 20px; border-radius:8px; font-size:13px; cursor:pointer; }

.btn-success  { background:#3B6D11; color:#fff; border:none; padding:8px 20px; border-radius:8px; font-size:13px; cursor:pointer; }

.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }

.form-group { display:flex; flex-direction:column; gap:4px; }

.form-group label { font-size:12px; color:#5f5e5a; font-weight:500; }

.form-group input, .form-group select, .form-group textarea {

  padding:7px 10px; border:1px solid #ddd; border-radius:8px; font-size:13px; color:#2c2c2a; background:#fff;

}

.form-group textarea { resize:vertical; min-height:60px; }

.full-col { grid-column:1/-1; }

.msg-ok  { background:#EAF3DE; border:1px solid #3B6D11; color:#3B6D11; padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:14px; }

.msg-err { background:#FCEBEB; border:1px solid #A32D2D; color:#A32D2D; padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:14px; }

.hh-limit-note { font-size:12px; color:#854F0B; background:#FEF3C7; border:1px solid #FDE68A; padding:6px 12px; border-radius:6px; display:inline-block; }

.summary-row td { padding:8px; font-size:13px; border-bottom:1px solid #f1efe8; }

.summary-row th { font-size:12px; color:#5f5e5a; font-weight:500; padding:6px 8px; border-bottom:1px solid #eee; text-align:left; }

</style>
<body>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div class="tb-title">Emergency Mode</div>
    <div class="tb-sub">Activate disaster response · Bypass normal approval queues · Log household distributions</div>
  </div>
  <div class="content">

    <?php if ($msg): ?><div class="msg-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="em-banner <?= ($em['is_active'] ?? 0) ? 'em-active' : 'em-inactive' ?>">
      <div>
        <div style="display:flex;align-items:center;gap:10px;">
          <span class="em-badge <?= ($em['is_active'] ?? 0) ? 'em-badge-on' : 'em-badge-off' ?>">
            <?= ($em['is_active'] ?? 0) ? '<i class="bi bi-exclamation-triangle-fill"></i> ACTIVE' : '<i class="bi bi-shield-check"></i> STANDBY' ?>
          </span>
          <span class="em-title"><?= htmlspecialchars($em['label'] ?? 'Emergency Mode') ?></span>
        </div>
        <div class="em-sub" style="margin-top:6px;">
          <?php if ($em['is_active'] ?? 0): ?>
            Activated <?= ($em['activated_at'] ?? null) ? date('M d, Y g:i A', strtotime($em['activated_at'])) : '—' ?>
            · Per-household limit: <strong><?= (int)($em['per_hh_limit'] ?? 5) ?> units</strong>
            · Approval bypass: <strong><?= ($em['bypass_approval'] ?? 0) ? 'Yes' : 'No' ?></strong>
            <?php if ($em['description'] ?? null): ?><br><?= htmlspecialchars($em['description']) ?><?php endif; ?>
          <?php else: ?>
            System is in normal operating mode. Activate to enable emergency distribution protocols.
          <?php endif; ?>
        </div>
      </div>
      <?php if (isSuperAdmin()): ?>
        <?php if ($em['is_active'] ?? 0): ?>
          <form method="POST" onsubmit="return confirm('Deactivate emergency mode and return to normal operations?');">
            <input type="hidden" name="action" value="deactivate">
            <button class="btn-success" type="submit">✓ Deactivate Emergency</button>
          </form>
        <?php else: ?>
          <button class="btn-danger" onclick="document.getElementById('activate-form').classList.toggle('hidden')" type="button">
            <i class="bi bi-exclamation-triangle"></i> Activate Emergency
          </button>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if (isSuperAdmin() && !($em['is_active'] ?? 0)): ?>
    <div id="activate-form" class="card hidden" style="margin-bottom:18px;">
      <div class="card-h">Emergency Mode Settings</div>
      <form method="POST">
        <input type="hidden" name="action" value="activate">
        <div class="form-grid">
          <div class="form-group">
            <label>Emergency Label</label>
            <input type="text" name="label" value="Emergency Mode" placeholder="e.g. Typhoon Response — Kabankalan" required>
          </div>
          <div class="form-group">
            <label>Per-Household Limit (units per medicine)</label>
            <input type="number" name="per_hh_limit" value="5" min="1" max="100" required>
          </div>
          <div class="form-group full-col">
            <label>Description / Situation Notes</label>
            <textarea name="description" placeholder="Brief description of the emergency situation..."></textarea>
          </div>
          <div class="form-group full-col" style="flex-direction:row;align-items:center;gap:10px;">
            <input type="checkbox" name="bypass_approval" value="1" id="bypassChk" checked>
            <label for="bypassChk" style="margin:0;font-size:13px;color:#2c2c2a;">
              Bypass normal requisition approval queue (emergency requisitions auto-approved)
            </label>
          </div>
        </div>
        <div style="margin-top:14px;display:flex;gap:10px;">
          <button class="btn-danger" type="submit"><i class="bi bi-exclamation-triangle"></i> Activate Emergency Mode</button>
          <button type="button" class="btn" onclick="document.getElementById('activate-form').classList.add('hidden')">Cancel</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($em['is_active'] ?? 0): ?>

    <?php if (!isSuperAdmin()): ?>
    <div class="card" style="margin-bottom:18px;">
      <div class="card-h">
        Log Household Distribution
        <span class="hh-limit-note">Limit: <?= (int)($em['per_hh_limit'] ?? 5) ?> units/household</span>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="log_dist">
        <div class="form-grid">
          <div class="form-group">
            <label>Medicine</label>
            <select name="medicine_id" required>
              <option value="">— select medicine —</option>
              <?php foreach ($medicines as $m): ?>
                <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Household Representative Name</label>
            <input type="text" name="household_rep" placeholder="Full name" required>
          </div>
          <div class="form-group">
            <label>Mobile Number (optional)</label>
            <input type="text" name="mobile_number" placeholder="09XXXXXXXXX">
          </div>
          <div class="form-group">
            <label>Address / Purok</label>
            <input type="text" name="address" placeholder="Purok, street, landmark...">
          </div>
          <div class="form-group">
            <label>Quantity (max <?= (int)($em['per_hh_limit'] ?? 5) ?>)</label>
            <input type="number" name="quantity" value="1" min="1" max="<?= (int)($em['per_hh_limit'] ?? 5) ?>" required>
          </div>
          <div class="form-group full-col">
            <label>Notes</label>
            <textarea name="notes" placeholder="Condition, situation notes, etc." style="min-height:48px;"></textarea>
          </div>
        </div>
        <div style="margin-top:12px;">
          <button class="btn btn-primary" type="submit">Log Distribution</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($distSummary): ?>
    <div class="card" style="margin-bottom:18px;">
      <div class="card-h">Distribution Summary (Current Emergency)</div>
      <table class="tbl">
        <thead>
          <tr>
            <th>Medicine</th>
            <th>Station</th>
            <th>Households Served</th>
            <th>Total Units Given</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($distSummary as $row): ?>
          <tr class="summary-row">
            <td><?= htmlspecialchars($row['med_name']) ?></td>
            <td><?= htmlspecialchars($row['barangay_name']) ?></td>
            <td style="font-weight:600;"><?= (int)$row['households_served'] ?></td>
            <td style="font-weight:600;"><?= (int)$row['total_units'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if ($distLog): ?>
    <div class="card">
      <div class="card-h">Distribution Log</div>
      <table class="tbl">
        <thead>
          <tr>
            <th>Time</th>
            <th>Station</th>
            <th>Medicine</th>
            <th>Household Rep</th>
            <th>Address</th>
            <th>Qty</th>
            <th>Logged By</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($distLog as $d): ?>
          <tr>
            <td style="white-space:nowrap;font-size:12px;color:#888780;"><?= date('M d, g:i A', strtotime($d['created_at'])) ?></td>
            <td><?= htmlspecialchars($d['barangay_name']) ?></td>
            <td><?= htmlspecialchars($d['med_name']) ?></td>
            <td><?= htmlspecialchars($d['household_rep']) ?></td>
            <td style="font-size:12px;color:#5f5e5a;"><?= htmlspecialchars($d['address'] ?: '—') ?></td>
            <td style="font-weight:600;"><?= (int)$d['quantity'] ?></td>
            <td style="font-size:12px;color:#5f5e5a;"><?= htmlspecialchars($d['by_name']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="card" style="text-align:center;color:#888780;font-size:13px;padding:30px;">
      No distributions logged yet.
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="card" style="text-align:center;padding:40px 20px;">
      <div style="font-size:32px;margin-bottom:10px;">🏥</div>
      <div style="font-size:15px;font-weight:600;color:#2c2c2a;margin-bottom:6px;">Normal Operations Active</div>
      <div style="font-size:13px;color:#5f5e5a;max-width:480px;margin:0 auto;line-height:1.6;">
        When a disaster or public health emergency occurs,
        <?= isSuperAdmin() ? 'activate Emergency Mode above' : 'your City Health Officer will activate Emergency Mode' ?>
        to enable per-household distribution limits, bypass normal approval queues, and
        log bulk distributions to affected families.
      </div>
      <?php if ($em['deactivated_at'] ?? null): ?>
      <div style="margin-top:16px;font-size:12px;color:#888780;">
        Last emergency ended: <?= date('M d, Y g:i A', strtotime($em['deactivated_at'])) ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div></div><script>
// Toggle activate form visibility
const form = document.getElementById('activate-form');
if (form) form.classList.add('hidden');
document.head.insertAdjacentHTML('beforeend', '<style>.hidden{display:none!important;}</style>');
</script>
</body>
</html>