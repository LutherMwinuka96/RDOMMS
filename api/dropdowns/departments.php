<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/departments.php';

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
$syncedCount = null;

if ($conn) {
    try {
        $syncedCount = syncRdommsDepartmentsToDatabase($conn);
    } catch (Throwable $e) {
        error_log('Department sync failed: ' . $e->getMessage());
    }
}

$departments = [];
foreach (getRdommsDepartments() as $department) {
    $departments[] = [
        'id' => $department['value'],
        'name' => $department['label'],
        'value' => $department['value'],
        'label' => $department['label'],
    ];
}

sendJSONResponse([
    'success' => true,
    'data' => $departments,
    'synced' => $syncedCount,
]);
