<?php
// admin/dispense.php - Dedicated Walk-In Dispensing Panel
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();

$admin = currentAdmin();
$pdo = getDB();
require_once __DIR__ . '/../includes/smart_inventory.php';

$success_msg = "";
$error_msg = "";
$assignedStationId = isBarangayAdmin() ? (int)($admin['station_id'] ?? 0) : 0;

// 1. Handle Asynchronous "Add Resident" Modal Form Submission (AJAX)
if (isset($_POST['ajax_add_resident'])) {
    header('Content-Type: application/json');
    try {
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $purok = trim($_POST['purok']);
        $phone = trim($_POST['phone']);

        if (empty($first_name) || empty($last_name)) {
            echo json_encode(['success' => false, 'message' => 'First and last names are required.']);
            exit;
        }

        // Combine fields to populate the full_name row structure
        $full_name = trim("$first_name $last_name");

        $ins = $pdo->prepare("INSERT INTO residents (full_name, purok, phone_number) VALUES (?, ?, ?)");
        $ins->execute([$full_name, $purok, $phone]);

        echo json_encode([
            'success' => true, 
            'full_name' => $full_name,
            'purok' => $purok
        ]);
    } catch (\PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// 2. Handle standard Dispensing Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dispense_item'])) {
    $resident_input = trim($_POST['resident_search']);
    $medicine_input = trim($_POST['medicine_search']);
    $qty = intval($_POST['quantity']);

    $resident_id = null;
    $inventory_id = null;

    // A. Parse Resident without ID (Lookup via name string and optional Purok parsing)
    // Matches patterns like "Juan Cruz (Purok 2)" or just "Juan Cruz"
    $res_name = $resident_input;
    $res_purok = null;
    if (preg_match('/^(.*?)\s*\(Purok\s*([^)]+)\)/i', $resident_input, $res_matches)) {
        $res_name = trim($res_matches[1]);
        $res_purok = trim($res_matches[2]);
    }

    if (!empty($res_name)) {
        if ($res_purok !== null) {
            $res_stmt = $pdo->prepare("SELECT id FROM residents WHERE full_name = ? AND purok = ? LIMIT 1");
            $res_stmt->execute([$res_name, $res_purok]);
        } else {
            $res_stmt = $pdo->prepare("SELECT id FROM residents WHERE full_name = ? LIMIT 1");
            $res_stmt->execute([$res_name]);
        }
        $resident_id = $res_stmt->fetchColumn() ?: null;
    }

    // B. Parse Medicine without ID (Lookup via Medicine Name and Health Station Barangay Name)
    // Matches: "Amoxicillin - Barangay Central"
    if (preg_match('/^(.*?)\s*-\s*([^\s\[]+)/', $medicine_input, $med_matches)) {
        $med_name = trim($med_matches[1]);
        $bgy_name = trim($med_matches[2]);

        $med_stmt = $pdo->prepare("
            SELECT i.id 
            FROM inventory i
            JOIN medicines m ON m.id = i.medicine_id
            JOIN health_stations h ON h.id = i.station_id
            WHERE m.name = ? AND h.barangay_name LIKE ?
            LIMIT 1
        ");
        $med_stmt->execute([$med_name, "%$bgy_name%"]);
        $inventory_id = $med_stmt->fetchColumn() ?: null;
    }

    if (!$resident_id || !$inventory_id || $qty <= 0) {
        $error_msg = "Please select a valid registered resident and medicine from the dropdown options.";
    } else {
        try {
            $pdo->beginTransaction();

            // Check live stock levels
            $check = $pdo->prepare("
                SELECT i.id, i.quantity, i.station_id, i.medicine_id, m.name AS item_name
                FROM inventory i
                JOIN medicines m ON m.id = i.medicine_id
                WHERE i.id = ?
                FOR UPDATE
            ");
            $check->execute([$inventory_id]);
            $item = $check->fetch();

            if (!$item) {
                $error_msg = "Selected item does not exist.";
                $pdo->rollBack();
            } elseif ($assignedStationId && (int)$item['station_id'] !== $assignedStationId) {
                $error_msg = "This medicine belongs to another health station.";
                $pdo->rollBack();
            } elseif ((int)$item['quantity'] < $qty) {
                $error_msg = "Insufficient stock! Only " . $item['quantity'] . " units of " . htmlspecialchars($item['item_name']) . " remain.";
                $pdo->rollBack();
            } else {
                $fifoRoutes = [];
                $batchCount = 0;
                if (smartInventoryTableExists($pdo, 'inventory_batches')) {
                    $batchStmt = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM inventory_batches
                        WHERE medicine_id = ? AND station_id = ? AND quantity > 0
                    ");
                    $batchStmt->execute([(int)$item['medicine_id'], (int)$item['station_id']]);
                    $batchCount = (int)$batchStmt->fetchColumn();
                }

                if ($batchCount > 0) {
                    $fifoRoutes = smartInventoryApplyFifoDeduction(
                        $pdo,
                        (int)$item['medicine_id'],
                        (int)$item['station_id'],
                        $qty
                    );
                } else {
                    $newQty = max(((int)$item['quantity']) - $qty, 0);
                    $newStatus = $newQty <= 0 ? 'out_of_stock' : ($newQty <= 10 ? 'low_stock' : 'in_stock');
                    $deduct = $pdo->prepare("UPDATE inventory SET quantity = ?, status = ? WHERE id = ?");
                    $deduct->execute([$newQty, $newStatus, $inventory_id]);
                }

                // Map logs using medicine_id and admin session tracking
                $adminId = (int)($admin['id'] ?? 0);
                $log = $pdo->prepare("INSERT INTO dispensing_logs (resident_id, medicine_id, quantity_dispensed, dispensed_by) VALUES (?, ?, ?, ?)");
                $log->execute([$resident_id, (int)$item['medicine_id'], $qty, $adminId]);

                smartInventoryNotifyLowForecast($pdo, (int)$item['medicine_id'], (int)$item['station_id'], 7);

                $pdo->commit();
                $success_msg = "Dispensed $qty unit(s) of " . htmlspecialchars($item['item_name']) . " successfully!";
                if (!empty($fifoRoutes)) {
                    $batchLabels = array_map(function ($route) {
                        $label = $route['batch_no'] ?: ('Batch #' . $route['batch_id']);
                        return $label . ' (' . $route['quantity'] . ' units)';
                    }, $fifoRoutes);
                    $success_msg .= " FIFO batches used: " . htmlspecialchars(implode(', ', $batchLabels)) . ".";
                }
            }
        } catch (\Exception $e) {
            $pdo->rollBack();
            $error_msg = "Transaction processing failed: " . $e->getMessage();
        }
    }
}

// 3. Gather collections for Search Dropdowns
$all_residents = $pdo->query("SELECT id, full_name, purok FROM residents ORDER BY full_name ASC")->fetchAll();
if ($assignedStationId) {
    $stmt = $pdo->prepare("
        SELECT i.id, m.name AS item_name, i.quantity AS stock, h.barangay_name
        FROM inventory i
        JOIN medicines m ON m.id = i.medicine_id
        JOIN health_stations h ON h.id = i.station_id
        WHERE i.quantity > 0 AND i.station_id = ?
        ORDER BY m.name ASC
    ");
    $stmt->execute([$assignedStationId]);
    $all_medicines = $stmt->fetchAll();
} else {
    $all_medicines = $pdo->query("
        SELECT i.id, m.name AS item_name, i.quantity AS stock, h.barangay_name
        FROM inventory i
        JOIN medicines m ON m.id = i.medicine_id
        JOIN health_stations h ON h.id = i.station_id
        WHERE i.quantity > 0
        ORDER BY h.barangay_name ASC, m.name ASC
    ")->fetchAll();
}

// 4. Fetch logs for history panel view 
$history = $pdo->query("SELECT l.quantity_dispensed, l.dispensed_at, r.full_name, m.name AS item_name 
                        FROM dispensing_logs l 
                        JOIN residents r ON l.resident_id = r.id 
                        JOIN medicines m ON l.medicine_id = m.id
                        ORDER BY l.dispensed_at DESC LIMIT 10")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Walk-In Dispensing — BayaniServe</title>
    <?php include __DIR__ . '/includes/layout_head.php'; ?>
    <style>
        .flex-container { display: flex; gap: 24px; align-items: flex-start; }
        .sidebar-card { width: 340px; flex-shrink: 0; }
        .input-inline-btn { display: flex; gap: 8px; }
        .modal-overlay { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); justify-content:center; align-items:center; z-index:1000; }
        .modal-box { background:var(--surface); padding:25px; border-radius:var(--radius); border: 1px solid var(--border); width:100%; max-width:400px; box-shadow:0 4px 12px rgba(0,0,0,0.1); }
        .modal-header { font-size:16px; font-weight:700; margin-bottom:15px; display:flex; justify-content:space-between; color: var(--text1); }
        .close-btn { cursor:pointer; font-weight:bold; color:var(--text3); }
        
        .log-item { padding: 10px 0; border-bottom: 1px solid var(--border2); font-size: 13px; }
        .log-item:last-child { border-bottom: none; }
        .timestamp { font-size: 11px; color: var(--text3); }
        .fifo-note { background:var(--amber-lt); border:1px solid #f0d09a; color:var(--amber); border-radius:var(--radius); padding:10px 12px; font-size:13px; margin-bottom:15px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
    <div class="topbar">
        <div>
            <div class="tb-title"><i class="bi bi-prescription2"></i> Walk-In Dispensing Log</div>
            <div class="tb-sub">Directly record medicine dispensing for residents walking straight into the Health Station.</div>
        </div>
    </div>
    <div class="content">
        <div class="fifo-note">
            <i class="bi bi-info-circle-fill"></i> FIFO enabled: when batch records exist, BayaniServe deducts from the earliest expiration date first.
        </div>

        <?php if ($success_msg): ?><div class="alert alert-success">✓ <?= $success_msg ?></div><?php endif; ?>
        <?php if ($error_msg): ?><div class="alert alert-danger"><?= $error_msg ?></div><?php endif; ?>

        <div class="flex-container">
            <!-- Main Form Panel -->
            <div class="card">
                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-lbl">Select Resident</label>
                        <div class="input-inline-btn">
                            <input type="text" class="form-inp" name="resident_search" id="resident_search" list="resident_list" placeholder="Type name to search..." required autocomplete="off">
                            <datalist id="resident_list">
                                <?php foreach ($all_residents as $r): ?>
                                    <option value="<?= htmlspecialchars($r['full_name'] . (!empty($r['purok']) ? ' (Purok ' . $r['purok'] . ')' : '')) ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <button type="button" class="btn btn-success" style="white-space:nowrap;" onclick="openResidentModal()"><i class="bi bi-plus-lg"></i> Add New</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-lbl">Select Medicine</label>
                        <input type="text" class="form-inp" name="medicine_search" id="medicine_search" list="medicine_list" placeholder="Type medicine name..." required autocomplete="off">
                        <datalist id="medicine_list">
                            <?php foreach ($all_medicines as $m): ?>
                                <option value="<?= htmlspecialchars($m['item_name'] . ' - ' . $m['barangay_name']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="form-group">
                        <label class="form-lbl">Quantity to Dispense</label>
                        <input type="number" class="form-inp" name="quantity" min="1" value="1" required>
                    </div>

                    <button type="submit" name="dispense_item" class="btn btn-primary" style="width: 100%; margin-top: 10px;"><i class="bi bi-check-lg"></i> Dispense and Deduct Stock</button>
                </form>
            </div>

            <!-- History Feed Section -->
            <div class="card sidebar-card">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid var(--border2); padding-bottom:8px; text-transform:uppercase; letter-spacing:.04em; color:var(--text1);">Recent Walk-In Transactions</h3>
                <?php if (empty($history)): ?>
                    <p style="color:var(--text3); font-size:13px; padding-top:10px;">No walk-in distributions logged yet.</p>
                <?php else: ?>
                    <?php foreach ($history as $h): ?>
                        <div class="log-item">
                            <strong><?= htmlspecialchars($h['full_name']) ?></strong> received 
                            <span style="color:var(--blue); font-weight:600;"><?= $h['quantity_dispensed'] ?>x</span> <?= htmlspecialchars($h['item_name']) ?>
                            <div class="timestamp"><?= date('M d, Y h:i A', strtotime($h['dispensed_at'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Add Resident Modal Modal Pop-up Layout -->
<div class="modal-overlay" id="residentModal">
    <div class="modal-box">
        <div class="modal-header">
            <span>Register New Resident</span>
            <span class="close-btn" onclick="closeResidentModal()">&times;</span>
        </div>
        <div id="modal_error" class="alert alert-danger" style="display:none; padding:8px; font-size:12px;"></div>
        <form id="modalResidentForm">
            <div class="form-group">
                <label class="form-lbl">First Name</label>
                <input type="text" class="form-inp" id="m_first_name" required>
            </div>
            <div class="form-group">
                <label class="form-lbl">Last Name</label>
                <input type="text" class="form-inp" id="m_last_name" required>
            </div>
            <div class="form-group">
                <label class="form-lbl">Purok</label>
                <input type="text" class="form-inp" id="m_purok" placeholder="e.g. 1, 2">
            </div>
            <div class="form-group">
                <label class="form-lbl">Phone Number</label>
                <input type="text" class="form-inp" id="m_phone" placeholder="e.g. 09123456789">
            </div>
            <button type="button" class="btn btn-success" style="width:100%; margin-top: 10px;" onclick="submitResidentAjax()"><i class="bi bi-floppy"></i> Save and Select Resident</button>
        </form>
    </div>
</div>

<script>
function openResidentModal() {
    document.getElementById('residentModal').style.display = 'flex';
    document.getElementById('modal_error').style.display = 'none';
}

function closeResidentModal() {
    document.getElementById('residentModal').style.display = 'none';
    document.getElementById('modalResidentForm').reset();
}

function submitResidentAjax() {
    const firstName = document.getElementById('m_first_name').value.trim();
    const lastName = document.getElementById('m_last_name').value.trim();
    const purok = document.getElementById('m_purok').value.trim();
    const phone = document.getElementById('m_phone').value.trim();

    if(!firstName || !lastName) {
        const errDiv = document.getElementById('modal_error');
        errDiv.innerText = "First and Last names are required.";
        errDiv.style.display = 'block';
        return;
    }

    const formData = new FormData();
    formData.append('ajax_add_resident', '1');
    formData.append('first_name', firstName);
    formData.append('last_name', lastName);
    formData.append('purok', purok);
    formData.append('phone', phone);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            const datalist = document.getElementById('resident_list');
            const option = document.createElement('option');
            
            const labelPurok = data.purok ? ` (Purok ${data.purok})` : '';
            option.value = `${data.full_name}${labelPurok}`;
            datalist.appendChild(option);

            document.getElementById('resident_search').value = `${data.full_name}${labelPurok}`;
            closeResidentModal();
        } else {
            const errDiv = document.getElementById('modal_error');
            errDiv.innerText = data.message || "An error occurred.";
            errDiv.style.display = 'block';
        }
    })
    .catch(error => console.error('Error:', error));
}
</script>
</body>
</html>