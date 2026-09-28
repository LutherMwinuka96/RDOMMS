<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/data-backup.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$session = verifySession();
if (!$session) {
    sendJSONResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

requireRole(['admin'], $session);

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

$userId = (int) ($session['user_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            if (!empty($_GET['download']) && !empty($_GET['id'])) {
                $path = rdomms_get_backup_file_path($conn, (string) $_GET['id']);
                $filename = basename($path);
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . filesize($path));
                readfile($path);
                exit;
            }

            sendJSONResponse([
                'status' => 'success',
                'data' => [
                    'backups' => rdomms_list_backups($conn),
                    'settings' => rdomms_backup_settings_for_client(rdomms_load_backup_settings($conn)),
                    'summary' => rdomms_backup_summary($conn),
                ],
            ]);
            break;

        case 'POST':
            if (!empty($_FILES['backup_file'])) {
                $upload = $_FILES['backup_file'];
                if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    sendJSONResponse(['status' => 'error', 'message' => 'Backup file upload failed'], 400);
                }

                $confirm = strtoupper(trim((string) ($_POST['confirm'] ?? '')));
                if ($confirm !== 'RESTORE') {
                    sendJSONResponse(['status' => 'error', 'message' => 'Type RESTORE to confirm database restore'], 400);
                }

                $payload = rdomms_parse_backup_file((string) $upload['tmp_name']);
                $result = rdomms_restore_database_backup($conn, $payload);
                logActivity($userId, 'Restored database from uploaded backup', 'Settings', 'warning', 'Imported backup file: ' . ($upload['name'] ?? 'upload.json'));

                sendJSONResponse([
                    'status' => 'success',
                    'message' => 'Database restored successfully from uploaded file',
                    'data' => $result,
                ]);
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                $data = $_POST;
            }

            $action = strtolower(trim((string) ($data['action'] ?? 'create')));

            if ($action === 'restore') {
                $backupId = trim((string) ($data['backup_id'] ?? $data['id'] ?? ''));
                $confirm = strtoupper(trim((string) ($data['confirm'] ?? '')));
                if ($backupId === '') {
                    sendJSONResponse(['status' => 'error', 'message' => 'Backup ID is required'], 400);
                }
                if ($confirm !== 'RESTORE') {
                    sendJSONResponse(['status' => 'error', 'message' => 'Type RESTORE to confirm database restore'], 400);
                }

                $path = rdomms_get_backup_file_path($conn, $backupId);
                $payload = rdomms_parse_backup_file($path);
                $result = rdomms_restore_database_backup($conn, $payload);
                logActivity($userId, 'Restored database backup', 'Settings', 'warning', 'Restored backup ID: ' . $backupId);

                sendJSONResponse([
                    'status' => 'success',
                    'message' => 'Database restored successfully',
                    'data' => $result,
                ]);
            }

            $backup = rdomms_export_database_backup($conn, $userId, 'manual');
            logActivity($userId, 'Created database backup', 'Settings', 'success', 'Backup ID: ' . $backup['backup_id']);

            sendJSONResponse([
                'status' => 'success',
                'message' => 'Database backup created successfully',
                'data' => [
                    'backup' => $backup,
                    'backups' => rdomms_list_backups($conn),
                    'summary' => rdomms_backup_summary($conn),
                ],
            ], 201);
            break;

        case 'PUT':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                $data = $_POST;
            }

            $saved = rdomms_save_backup_settings($conn, $data);
            logActivity($userId, 'Updated backup settings', 'Settings', 'info', 'Backup preferences were updated');

            sendJSONResponse([
                'status' => 'success',
                'message' => 'Backup settings saved successfully',
                'data' => [
                    'settings' => rdomms_backup_settings_for_client($saved),
                ],
            ]);
            break;

        case 'DELETE':
            $backupId = trim((string) ($_GET['id'] ?? ''));
            if ($backupId === '') {
                sendJSONResponse(['status' => 'error', 'message' => 'Backup ID is required'], 400);
            }

            rdomms_delete_backup($conn, $backupId);
            logActivity($userId, 'Deleted database backup', 'Settings', 'warning', 'Deleted backup ID: ' . $backupId);

            sendJSONResponse([
                'status' => 'success',
                'message' => 'Backup deleted successfully',
                'data' => [
                    'backups' => rdomms_list_backups($conn),
                    'summary' => rdomms_backup_summary($conn),
                ],
            ]);
            break;

        default:
            sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
    }
} catch (Throwable $e) {
    error_log('Data backup API error: ' . $e->getMessage());
    sendJSONResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
}
