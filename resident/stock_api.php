<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$input      = json_decode(file_get_contents('php://input'), true);
$stationId  = $input['station_id']  ?? null;
$medicineId = $input['medicine_id'] ?? null;

if (!$stationId || !$medicineId) {
    echo json_encode(['results' => []]);
    exit;
}

$pdo    = getDB();
$where  = [];
$params = [];

if ($stationId !== 'all') {
    $where[]        = 'i.station_id = :sid';
    $params[':sid'] = (int) $stationId;
}
if ($medicineId !== 'all') {
    $where[]        = 'i.medicine_id = :mid';
    $params[':mid'] = (int) $medicineId;
}

$whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT h.barangay_name, m.name AS medicine_name,
               i.quantity, i.status, i.last_updated
        FROM inventory i
        JOIN health_stations h ON h.id = i.station_id
        JOIN medicines m       ON m.id = i.medicine_id
        $whereClause
        ORDER BY h.barangay_name, m.name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode(['results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);