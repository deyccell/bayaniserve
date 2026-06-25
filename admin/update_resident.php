<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
$pdo = getDB();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['full_name'] ?? '');
    $mobile = trim($_POST['mobile_number'] ?? '');

    // Basic Input Validation Guard
    if ($id <= 0 || $name === '' || $mobile === '') {
        echo json_encode(['success' => false, 'message' => 'Please fill up all required fields.']);
        exit;
    }

    try {
        // Scoping Check: Barangay Admins can only edit residents inside their assigned station node
        if (!isSuperAdmin()) {
            $admin = currentAdmin();
            $assignedStationId = (int)$admin['station_id'];
            if (!$assignedStationId) {
                $stFetch = $pdo->prepare("SELECT station_id FROM admins WHERE id = ?");
                $stFetch->execute([$admin['id']]);
                $assignedStationId = (int)$stFetch->fetchColumn();
            }

            $check = $pdo->prepare("SELECT station_id FROM residents WHERE id = ?");
            $check->execute([$id]);
            $residentStationId = $check->fetchColumn();

            if ((int)$residentStationId !== $assignedStationId) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized data boundary breach.']);
                exit;
            }
        }

        // Execute Profile Row Database Update
        $stmt = $pdo->prepare("UPDATE residents SET full_name = ?, mobile_number = ? WHERE id = ?");
        $stmt->execute([$name, $mobile, $id]);

        echo json_encode(['success' => true, 'message' => 'Resident data updated successfully.']);
    } catch (PDOException $e) {
        if (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062) {
            echo json_encode(['success' => false, 'message' => 'This mobile number is already registered to another resident.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error encountered: ' . $e->getMessage()]);
        }
    }
    exit;
}
