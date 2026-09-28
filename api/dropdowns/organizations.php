<?php
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['success' => false, 'message' => 'Database connection failed'], 500);
}

$stmt = $conn->query('SELECT org_id AS id, name FROM organizations ORDER BY name ASC');
$organizations = [];
foreach ($stmt->fetchAll() as $row) {
    $organizations[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'value' => $row['name'],
        'label' => $row['name'],
    ];
}

sendJSONResponse(['success' => true, 'data' => $organizations]);
