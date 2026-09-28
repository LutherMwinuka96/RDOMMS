<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/organization-profile.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$session = verifySession();
if (!$session) {
    sendJSONResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        requireRole(['admin', 'head_of_organization_center', 'human_resource_manager', 'accountant'], $session);
        $row = rdomms_load_primary_organization($conn);
        sendJSONResponse([
            'status' => 'success',
            'data' => rdomms_organization_row_to_client($row),
        ]);

    case 'PUT':
        requireRole(['admin', 'head_of_organization_center'], $session);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        $saved = rdomms_save_primary_organization($conn, $data);
        logActivity(
            $session['user_id'],
            'Updated organization profile',
            'System',
            'info',
            'Organization profile updated: ' . ($saved['name'] ?? 'Primary organization')
        );

        sendJSONResponse([
            'status' => 'success',
            'message' => 'Organization profile saved successfully',
            'data' => rdomms_organization_row_to_client($saved),
        ]);

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
