<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/notification-preferences.php';

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

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        requireRole(['admin', 'head_of_organization_center'], $session);
        $preferences = rdomms_load_system_notification_preferences($conn);
        sendJSONResponse([
            'status' => 'success',
            'data' => rdomms_notification_preferences_for_client($preferences),
        ]);
        break;

    case 'PUT':
        requireRole(['admin', 'head_of_organization_center'], $session);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        $saved = rdomms_save_system_notification_preferences($conn, $data);
        logActivity(
            $session['user_id'],
            'Updated notification settings',
            'Settings',
            'info',
            'System notification preferences were updated'
        );

        sendJSONResponse([
            'status' => 'success',
            'message' => 'Notification settings saved successfully',
            'data' => rdomms_notification_preferences_for_client($saved),
        ]);
        break;

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
