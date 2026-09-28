<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/system-settings.php';
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
        requireRole(['admin', 'head_of_organization_center', 'human_resource_manager', 'accountant', 'field_officer', 'staff'], $session);
        $settings = rdomms_load_general_settings($conn);
        sendJSONResponse([
            'status' => 'success',
            'data' => rdomms_general_settings_for_client($settings),
        ]);
        break;

    case 'PUT':
        requireRole(['admin', 'head_of_organization_center'], $session);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        $previous = rdomms_load_general_settings($conn);
        $saved = rdomms_save_general_settings($conn, $data);

        if (
            array_key_exists('maintenance_mode', $saved)
            && ($previous['maintenance_mode'] ?? '0') !== ($saved['maintenance_mode'] ?? '0')
        ) {
            $enabled = rdomms_is_truthy($saved['maintenance_mode'] ?? '0');
            rdomms_dispatch_notification(
                $conn,
                'system_maintenance',
                $enabled ? 'Maintenance mode enabled' : 'Maintenance mode disabled',
                $enabled
                    ? 'The system is now in maintenance mode. Only administrators can sign in.'
                    : 'Maintenance mode has been turned off. All users can sign in again.',
                'Settings',
                $enabled ? 'warning' : 'success',
                null
            );
        }

        logActivity(
            $session['user_id'],
            'Updated general settings',
            'System',
            'info',
            'General system preferences were updated'
        );

        sendJSONResponse([
            'status' => 'success',
            'message' => 'General settings saved successfully',
            'data' => rdomms_general_settings_for_client($saved),
        ]);
        break;

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
