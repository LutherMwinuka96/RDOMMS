<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/notification-tables.php';
require_once __DIR__ . '/../config/notification-preferences.php';

header('Content-Type: application/json; charset=utf-8');
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

requireRole(['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff', 'accountant', 'human_resource_manager'], $session);

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

rdomms_ensure_notification_tables($conn);

$currentUser = getAuthenticatedUser($session);
if (!$currentUser) {
    sendJSONResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$userId = (int) ($currentUser['id'] ?? 0);
$isAdmin = rdomms_can_view_all_system_alerts($currentUser);
$scope = rdomms_system_alert_scope_sql($currentUser);
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (!empty($_GET['id'])) {
            $stmt = $conn->prepare(
                "SELECT * FROM notifications
                 WHERE (notification_id = ? OR id = ?)
                   AND {$scope['where']}
                 LIMIT 1"
            );
            $stmt->execute(array_merge([$_GET['id'], $_GET['id']], $scope['params']));
            $row = $stmt->fetch();
            if (!$row) {
                sendJSONResponse(['status' => 'error', 'message' => 'Alert not found'], 404);
            }
            sendJSONResponse(['status' => 'success', 'data' => rdomms_format_system_alert_row($row)]);
        }

        $query = "SELECT * FROM notifications WHERE {$scope['where']}";
        $params = $scope['params'];

        if (!empty($_GET['severity'])) {
            $query .= ' AND severity = ?';
            $params[] = rdomms_map_alert_severity_to_db((string) $_GET['severity']);
        }

        if (!empty($_GET['module'])) {
            $query .= ' AND module = ?';
            $params[] = $_GET['module'];
        }

        if (isset($_GET['read']) && $_GET['read'] !== '') {
            $query .= ' AND is_read = ?';
            $params[] = $_GET['read'] === '1' || $_GET['read'] === 'true' ? 1 : 0;
        }

        if (!empty($_GET['search'])) {
            $query .= ' AND (title LIKE ? OR message LIKE ? OR module LIKE ?)';
            $search = '%' . $_GET['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $query .= ' ORDER BY created_at DESC LIMIT 200';
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $rows = array_map('rdomms_format_system_alert_row', $stmt->fetchAll() ?: []);

        sendJSONResponse([
            'status' => 'success',
            'data' => $rows,
            'meta' => [
                'scope' => $isAdmin ? 'system' : 'account',
                'is_admin' => $isAdmin,
                'count' => count($rows),
            ],
        ]);
        break;

    case 'POST':
        if (!$isAdmin) {
            sendJSONResponse(['status' => 'error', 'message' => 'Only administrators can publish system alerts'], 403);
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Alert title is required'], 400);
        }

        if (!rdomms_is_notification_alert_enabled($conn, 'system_alerts')) {
            sendJSONResponse(['status' => 'error', 'message' => 'System alerts are disabled in notification settings'], 403);
        }

        if (!rdomms_is_notification_channel_enabled($conn, 'in_app')) {
            sendJSONResponse(['status' => 'error', 'message' => 'In-app notifications are disabled in notification settings'], 403);
        }

        $notificationId = 'ALR' . time() . rand(100, 999);
        $severity = rdomms_map_alert_severity_to_db((string) ($data['severity'] ?? 'info'));
        $module = trim((string) ($data['module'] ?? 'System'));
        $message = trim((string) ($data['description'] ?? $data['message'] ?? $data['desc'] ?? ''));
        $targetUserId = null;
        if (array_key_exists('user_id', $data) && $data['user_id'] !== '' && $data['user_id'] !== null) {
            $targetUserId = (int) $data['user_id'];
        } elseif (!empty($data['target_user_id'])) {
            $targetUserId = (int) $data['target_user_id'];
        }

        $stmt = $conn->prepare(
            "INSERT INTO notifications (notification_id, title, message, severity, module, category, user_id, is_read)
             VALUES (?, ?, ?, ?, ?, 'system_alert', ?, 0)"
        );
        $result = $stmt->execute([$notificationId, $title, $message, $severity, $module, $targetUserId]);

        if ($result) {
            logActivity($userId, 'Created system alert', 'Notifications', 'warning', 'Alert: ' . $title);
            sendJSONResponse([
                'status' => 'success',
                'message' => 'Alert created',
                'data' => ['notification_id' => $notificationId, 'scope' => $targetUserId ? 'account' : 'system'],
            ], 201);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Failed to create alert'], 500);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (!empty($data['action']) && $data['action'] === 'mark_all_read') {
            $stmt = $conn->prepare(
                "UPDATE notifications SET is_read = 1
                 WHERE {$scope['where']} AND is_read = 0"
            );
            $stmt->execute($scope['params']);
            sendJSONResponse(['status' => 'success', 'message' => 'All alerts marked as read']);
        }

        if (empty($data['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Alert ID required'], 400);
        }

        $updates = [];
        $params = [];

        if (array_key_exists('read', $data) || array_key_exists('is_read', $data)) {
            $updates[] = 'is_read = ?';
            $params[] = !empty($data['read']) || !empty($data['is_read']) ? 1 : 0;
        }

        foreach (['title', 'message'] as $field) {
            if (array_key_exists($field, $data)) {
                if (!$isAdmin) {
                    sendJSONResponse(['status' => 'error', 'message' => 'Only administrators can edit alert content'], 403);
                }
                $updates[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (array_key_exists('severity', $data)) {
            if (!$isAdmin) {
                sendJSONResponse(['status' => 'error', 'message' => 'Only administrators can edit alert severity'], 403);
            }
            $updates[] = 'severity = ?';
            $params[] = rdomms_map_alert_severity_to_db((string) $data['severity']);
        }

        if (array_key_exists('module', $data)) {
            if (!$isAdmin) {
                sendJSONResponse(['status' => 'error', 'message' => 'Only administrators can edit alert module'], 403);
            }
            $updates[] = 'module = ?';
            $params[] = $data['module'];
        }

        if (!$updates) {
            sendJSONResponse(['status' => 'error', 'message' => 'No fields to update'], 400);
        }

        $params[] = $data['id'];
        $params[] = $data['id'];
        $params = array_merge($params, $scope['params']);
        $query = "UPDATE notifications SET " . implode(', ', $updates) . "
                  WHERE (notification_id = ? OR id = ?)
                    AND {$scope['where']}";
        $stmt = $conn->prepare($query);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            sendJSONResponse(['status' => 'error', 'message' => 'Alert not found'], 404);
        }

        sendJSONResponse(['status' => 'success', 'message' => 'Alert updated']);
        break;

    case 'DELETE':
        if (!empty($_GET['clear']) && $_GET['clear'] === 'all') {
            $stmt = $conn->prepare("DELETE FROM notifications WHERE {$scope['where']}");
            $stmt->execute($scope['params']);
            sendJSONResponse(['status' => 'success', 'message' => 'All alerts cleared']);
        }

        if (empty($_GET['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Alert ID required'], 400);
        }

        $params = array_merge([$_GET['id'], $_GET['id']], $scope['params']);
        $stmt = $conn->prepare(
            "DELETE FROM notifications
             WHERE (notification_id = ? OR id = ?)
               AND {$scope['where']}"
        );
        $result = $stmt->execute($params);

        if ($result && $stmt->rowCount() > 0) {
            sendJSONResponse(['status' => 'success', 'message' => 'Alert deleted']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Alert not found'], 404);
        break;

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
