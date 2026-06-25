<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();

// Resident records are a barangay-only function
if (isSuperAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$admin = currentAdmin();
$pdo = getDB();

// Harden station_id: if session is stale or null, re-fetch from DB
$assignedStationId = null;
if (!isSuperAdmin()) {
    $assignedStationId = (int)$admin['station_id'];
    if (!$assignedStationId) {
        $stFetch = $pdo->prepare("SELECT station_id FROM admins WHERE id = ?");
        $stFetch->execute([$admin['id']]);
        $assignedStationId = (int)$stFetch->fetchColumn();
        $_SESSION['admin_station_id'] = $assignedStationId;
    }
}

try {
    $pdo->exec("ALTER TABLE residents ADD UNIQUE KEY uniq_mobile_number (mobile_number)");
} catch (Exception $e) {
    // ignore
}

// Clean up any previously corrupted records where the columns were swapped (i.e. name is digits only)
try {
    $pdo->exec("DELETE FROM residents WHERE full_name REGEXP '^[0-9]+$'");
} catch (Exception $e) {
    // ignore
}

$importMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['resident_file'])) {
    $file = $_FILES['resident_file'];
    $inserted = 0;
    $updated = 0;

    if ($file['error'] === UPLOAD_ERR_OK) {
        $handle = fopen($file['tmp_name'], 'r');
        $headers = fgetcsv($handle);

        // Dynamic header mapping
        $nameIdx = 0;
        $mobileIdx = 1;
        $barangayIdx = 2;

        if ($headers) {
            foreach ($headers as $idx => $h) {
                $hClean = strtolower(trim($h));
                if (strpos($hClean, 'full name') !== false || $hClean === 'name') {
                    $nameIdx = $idx;
                } elseif (strpos($hClean, 'mobile') !== false || strpos($hClean, 'phone') !== false || strpos($hClean, 'contact') !== false) {
                    $mobileIdx = $idx;
                } elseif (strpos($hClean, 'barangay') !== false || strpos($hClean, 'purok') !== false || strpos($hClean, 'address') !== false) {
                    $barangayIdx = $idx;
                }
            }
        }

        $stations = $pdo->query("SELECT id, barangay_name FROM health_stations")->fetchAll();
        $stationMap = [];
        foreach ($stations as $s) $stationMap[strtolower(trim($s['barangay_name']))] = $s['id'];

        $pdo->beginTransaction();
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) continue;
            
            $name = isset($row[$nameIdx]) ? trim($row[$nameIdx]) : '';
            $mobile = isset($row[$mobileIdx]) ? trim($row[$mobileIdx]) : '';
            $barangay = isset($row[$barangayIdx]) ? trim($row[$barangayIdx]) : '';
            
            if (!$name || !$mobile) continue;

            // Smart Extraction: find registered barangay inside the provided string
            $stationId = null;
            $cleanBarangayInput = strtolower($barangay);
            foreach ($stationMap as $registeredName => $id) {
                if ($registeredName !== '' && strpos($cleanBarangayInput, $registeredName) !== false) {
                    $stationId = $id;
                    break;
                }
            }

            if (!isSuperAdmin()) {
                $stationId = $assignedStationId;
            }

            // Use INSERT ... ON DUPLICATE KEY UPDATE to prevent duplicate mobile numbers
            $stmt = $pdo->prepare("INSERT INTO residents (full_name, mobile_number, station_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), station_id = VALUES(station_id)");
            $stmt->execute([$name, $mobile, $stationId]);

            if ($stmt->rowCount() === 1) {
                $inserted++;
            } else {
                $updated++;
            }
        }
        $pdo->commit();
        fclose($handle);
        $importMsg = "Import complete: $inserted new, $updated updated.";
    }
}

if (isSuperAdmin()) {
    $residents = $pdo->query("SELECT r.*, h.barangay_name FROM residents r LEFT JOIN health_stations h ON h.id = r.station_id ORDER BY r.created_at DESC LIMIT 100")->fetchAll();
    $total = $pdo->query("SELECT COUNT(*) FROM residents")->fetchColumn();
} else {
    $residents = $pdo->prepare("SELECT r.*, h.barangay_name FROM residents r LEFT JOIN health_stations h ON h.id = r.station_id WHERE r.station_id = ? ORDER BY r.created_at DESC LIMIT 100");
    $residents->execute([$assignedStationId]);
    $residents = $residents->fetchAll();

    $total = $pdo->prepare("SELECT COUNT(*) FROM residents WHERE station_id = ?");
    $total->execute([$assignedStationId]);
    $total = $total->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Resident records — BayaniServe Admin</title>
<?php include __DIR__ . '/includes/layout_head.php'; ?>
<style>
.upload-area {
  border:2px dashed var(--border); border-radius:var(--radius);
  padding:24px; text-align:center; background:var(--bg);
  transition:border-color .15s;
}
.upload-area:hover { border-color:var(--blue); }
.upload-area input[type=file] { display:none; }
.upload-label {
  display:inline-flex; align-items:center; gap:8px;
  padding:10px 20px; background:var(--surface); border:1px solid var(--border);
  border-radius:8px; cursor:pointer; font-size:13px; font-weight:600; color:var(--text1);
  transition:all .12s;
}
.upload-label:hover { background:var(--blue-lt); border-color:var(--blue); }
#fname-display { font-size:12px; color:var(--text3); margin-top:8px; }

/* Modal */
.modal-backdrop {
  display:none; position:fixed; inset:0; z-index:9999;
  background:rgba(0,0,0,.45); backdrop-filter:blur(2px);
  justify-content:center; align-items:center;
}
.modal-box {
  background:var(--surface); border-radius:var(--radius); padding:28px;
  width:100%; max-width:440px; box-shadow:0 20px 60px rgba(0,0,0,.2);
  border:1px solid var(--border);
}
.modal-title {
  font-size:16px; font-weight:700; color:var(--text1); margin-bottom:20px;
  display:flex; justify-content:space-between; align-items:center;
}
.modal-close {
  background:none; border:none; font-size:20px; cursor:pointer;
  color:var(--text3); line-height:1;
}
.modal-close:hover { color:var(--text1); }

/* Search */
.search-wrap { position:relative; margin-bottom:14px; }
.search-wrap input {
  width:100%; padding:9px 12px 9px 36px; border:1px solid var(--border);
  border-radius:8px; font-size:13px; background:var(--surface); color:var(--text1);
}
.search-wrap input:focus { border-color:var(--blue); outline:none; }
.search-icon { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--text3); font-size:14px; pointer-events:none; }

.toast {
  position:fixed; bottom:24px; right:24px; z-index:99999;
  background:var(--text1); color:#fff; padding:12px 20px; border-radius:10px;
  font-size:13px; font-weight:500; opacity:0; transform:translateY(12px);
  transition:all .25s; pointer-events:none;
}
.toast.show { opacity:1; transform:translateY(0); }
.toast.success { background:var(--green); }
.toast.error   { background:var(--red); }
</style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
  <div class="topbar">
    <div class="tb-title"><i class="bi bi-person-vcard"></i> Resident Records</div>
    <div class="tb-sub">
      Manage resident contacts for Brgy. <?= htmlspecialchars(
        $pdo->prepare("SELECT barangay_name FROM health_stations WHERE id=?")->execute([$assignedStationId]) ? '' : ''
      ) ?><?php
        $snq = $pdo->prepare("SELECT barangay_name FROM health_stations WHERE id=?");
        $snq->execute([$assignedStationId]);
        echo htmlspecialchars($snq->fetchColumn() ?: 'Your Station');
      ?> — SMS subscription &amp; announcements
    </div>
  </div>
  <div class="content">

    <?php if ($importMsg): ?>
    <div class="alert alert-success">✓ <?= htmlspecialchars($importMsg) ?></div>
    <?php endif; ?>

    <div class="g2" style="margin-bottom:20px;">
      <!-- Stat -->
      <div class="stat">
        <div class="stat-label">Registered Residents</div>
        <div class="stat-value"><?= $total ?></div>
        <div class="stat-sub">With mobile number for SMS alerts</div>
      </div>
      <!-- Upload -->
      <div class="card">
        <div class="card-h">Import via CSV</div>
        <form method="POST" enctype="multipart/form-data">
          <div class="upload-area">
            <label class="upload-label" for="csv_file">
              <i class="bi bi-file-earmark-spreadsheet"></i> Choose CSV File
            </label>
            <input type="file" id="csv_file" name="resident_file" accept=".csv"
                   onchange="document.getElementById('fname-display').textContent=this.files[0]?.name||''">
            <div id="fname-display">No file chosen</div>
            <div style="font-size:11px;color:var(--text3);margin-top:8px;">
              Columns: <code>full_name, mobile_number, barangay</code>
            </div>
          </div>
          <button class="btn btn-primary" type="submit" style="margin-top:12px;"><i class="bi bi-upload"></i> Upload &amp; Import</button>
          <a href="../admin/template.csv" download class="btn btn-sm" style="margin-left:8px;"><i class="bi bi-download"></i> Download Template</a>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-h">
        Resident List
        <span class="badge bb"><?= $total ?> total</span>
      </div>

      <div class="search-wrap">
        <span class="search-icon"><i class="bi bi-search"></i></span>
        <input type="text" id="resSearch" placeholder="Search by name or mobile number…" oninput="filterResidents()">
      </div>

      <div style="overflow-x:auto;">
      <table class="tbl" id="resTable">
        <tr>
          <th>Full Name</th>
          <th>Mobile Number</th>
          <th>SMS Status</th>
          <th style="width:100px;text-align:right;">Actions</th>
        </tr>
        <?php if (empty($residents)): ?>
        <tr><td colspan="4" style="text-align:center;color:var(--text3);padding:28px;">
          No residents registered yet. Upload a CSV file to get started.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($residents as $r): ?>
        <tr class="res-row" data-name="<?= strtolower(htmlspecialchars($r['full_name'])) ?>" data-mobile="<?= htmlspecialchars($r['mobile_number']) ?>">
          <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
          <td>
            <span style="font-family:monospace;font-size:13px;"><?= htmlspecialchars($r['mobile_number']) ?></span>
          </td>
          <td>
            <span class="badge <?= !empty($r['mobile_number'])?'bg':'br' ?>">
              <?= !empty($r['mobile_number'])?'✓ Active':'No number' ?>
            </span>
          </td>
          <td style="text-align:right;">
            <button type="button" class="btn btn-sm btn-primary res-edit-btn"
                    data-id="<?= $r['id'] ?>"
                    data-name="<?= htmlspecialchars($r['full_name'], ENT_QUOTES) ?>"
                    data-mobile="<?= htmlspecialchars($r['mobile_number'], ENT_QUOTES) ?>">
              <i class="bi bi-pencil"></i> Edit
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      </div>
    </div>

  </div><!-- /content -->
</main>

<!-- Edit Resident Modal -->
<div class="modal-backdrop" id="editModal">
  <div class="modal-box">
    <div class="modal-title">
      Edit Resident
      <button class="modal-close" id="closeModal">✕</button>
    </div>
    <form id="editForm">
      <input type="hidden" name="id" id="edit_id">

      <div class="form-group">
        <label class="form-lbl">Full Name *</label>
        <input type="text" name="full_name" id="edit_name" class="form-inp" required placeholder="e.g. Maria Santos">
      </div>

      <div class="form-group">
        <label class="form-lbl">Mobile Number *</label>
        <input type="text" name="mobile_number" id="edit_mobile" class="form-inp" required placeholder="e.g. 09171234567">
        <div style="font-size:11px;color:var(--text3);margin-top:4px;">Used for SMS announcements and medicine alerts.</div>
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
        <button type="button" id="cancelEdit" class="btn">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-floppy"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
// ── Modal ──────────────────────────────────────────────────────────
const modal    = document.getElementById('editModal');
const editForm = document.getElementById('editForm');

function openModal(id, name, mobile) {
  document.getElementById('edit_id').value     = id;
  document.getElementById('edit_name').value   = name;
  document.getElementById('edit_mobile').value = mobile;
  modal.style.display = 'flex';
  document.getElementById('edit_name').focus();
}
function closeModal() { modal.style.display = 'none'; }

document.getElementById('closeModal').addEventListener('click', closeModal);
document.getElementById('cancelEdit').addEventListener('click', closeModal);
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

document.querySelectorAll('.res-edit-btn').forEach(btn => {
  btn.addEventListener('click', () => openModal(btn.dataset.id, btn.dataset.name, btn.dataset.mobile));
});

// ── Toast ─────────────────────────────────────────────────────────
function showToast(msg, type='') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast show ' + type;
  setTimeout(() => t.className = 'toast', 3000);
}

// ── Submit ────────────────────────────────────────────────────────
editForm.addEventListener('submit', function(e) {
  e.preventDefault();
  const btn = this.querySelector('[type=submit]');
  btn.disabled = true;
  btn.textContent = 'Saving…';

  fetch('update_resident.php', { method:'POST', body: new FormData(this) })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        closeModal();
        showToast('✓ ' + data.message, 'success');
        setTimeout(() => location.reload(), 1200);
      } else {
        showToast('✗ ' + data.message, 'error');
      }
    })
    .catch(() => showToast('Network error. Please try again.', 'error'))
    .finally(() => { btn.disabled = false; btn.textContent = 'Save Changes'; });
});

// ── Search/Filter ─────────────────────────────────────────────────
function filterResidents() {
  const q = document.getElementById('resSearch').value.toLowerCase();
  document.querySelectorAll('.res-row').forEach(row => {
    const match = row.dataset.name.includes(q) || row.dataset.mobile.includes(q);
    row.style.display = match ? '' : 'none';
  });
}
</script>
</body>
</html>