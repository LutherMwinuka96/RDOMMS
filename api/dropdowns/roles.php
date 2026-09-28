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

$stmt = $conn->query('SELECT role_id AS id, name, type FROM roles ORDER BY name ASC');
$roles = [];
foreach ($stmt->fetchAll() as $row) {
    $roles[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'value' => strtolower(str_replace(' ', '_', $row['name'])),
        'label' => $row['name'],
        'type' => $row['type'] ?? null,
    ];
}

sendJSONResponse(['success' => true, 'data' => $roles]);
