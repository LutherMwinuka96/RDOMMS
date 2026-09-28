<?php
require_once __DIR__ . '/../config/db.php';

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

requireRole(['admin'], $session);

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

$method = $_SERVER['REQUEST_METHOD'];

function roleRowToResponse(array $row): array
{
    $permissions = $row['permissions'] ?? null;
    if (is_string($permissions) && $permissions !== '') {
        $decoded = json_decode($permissions, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $permissions = $decoded;
        }
    }

    return [
        '_id' => $row['role_id'] ?? $row['id'],
        'id' => $row['role_id'] ?? $row['id'],
        'name' => $row['name'] ?? '',
        'type' => $row['type'] ?? 'custom',
        'desc' => $row['description'] ?? '',
        'description' => $row['description'] ?? '',
        'permissions' => $permissions ?: (object) [],
        'createdAt' => $row['created_at'] ?? null,
    ];
}

switch ($method) {
    case 'GET':
        if (!empty($_GET['id'])) {
            $stmt = $conn->prepare('SELECT * FROM roles WHERE role_id = ? OR id = ? LIMIT 1');
            $stmt->execute([$_GET['id'], $_GET['id']]);
            $row = $stmt->fetch();
            if ($row) {
                sendJSONResponse(['status' => 'success', 'data' => roleRowToResponse($row)]);
            }
            sendJSONResponse(['status' => 'error', 'message' => 'Role not found'], 404);
        }

        $stmt = $conn->query('SELECT * FROM roles ORDER BY name ASC');
        $roles = array_map('roleRowToResponse', $stmt->fetchAll());
        sendJSONResponse(['status' => 'success', 'data' => $roles]);

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (empty($data['name'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Role name is required'], 400);
        }

        $roleId = 'ROL' . time() . rand(100, 999);
        $permissions = isset($data['permissions']) ? json_encode($data['permissions']) : json_encode([
            'projects' => !empty($data['projects']),
            'planning' => !empty($data['planning']),
            'finance' => !empty($data['finance']),
            'reports' => !empty($data['reports']),
            'users' => !empty($data['users']),
        ]);

        $stmt = $conn->prepare('INSERT INTO roles (role_id, name, type, description, permissions) VALUES (?, ?, ?, ?, ?)');
        $result = $stmt->execute([
            $roleId,
            $data['name'],
            $data['type'] ?? 'custom',
            $data['desc'] ?? ($data['description'] ?? null),
            $permissions
        ]);

        if ($result) {
            logActivity($session['user_id'], 'Created role', 'Roles', 'success', 'Created role: ' . $data['name']);
            sendJSONResponse(['status' => 'success', 'message' => 'Role created successfully', 'data' => ['role_id' => $roleId]], 201);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Failed to create role'], 500);

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (empty($data['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Role ID required'], 400);
        }

        $updates = [];
        $params = [];
        foreach (['name', 'type', 'desc'] as $field) {
            if (array_key_exists($field, $data)) {
                $column = $field === 'desc' ? 'description' : $field;
                $updates[] = "{$column} = ?";
                $params[] = $data[$field];
            }
        }

        if (array_key_exists('permissions', $data)) {
            $updates[] = 'permissions = ?';
            $params[] = is_string($data['permissions']) ? $data['permissions'] : json_encode($data['permissions']);
        }

        if (!$updates) {
            sendJSONResponse(['status' => 'error', 'message' => 'No fields to update'], 400);
        }

        $params[] = $data['id'];
        $params[] = $data['id'];
        $stmt = $conn->prepare('UPDATE roles SET ' . implode(', ', $updates) . ' WHERE role_id = ? OR id = ?');
        $result = $stmt->execute($params);

        if ($result) {
            logActivity($session['user_id'], 'Updated role', 'Roles', 'info', 'Updated role ID: ' . $data['id']);
            sendJSONResponse(['status' => 'success', 'message' => 'Role updated successfully']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Failed to update role'], 500);

    case 'DELETE':
        if (empty($_GET['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Role ID required'], 400);
        }

        $stmt = $conn->prepare('DELETE FROM roles WHERE role_id = ? OR id = ?');
        $result = $stmt->execute([$_GET['id'], $_GET['id']]);

        if ($result && $stmt->rowCount() > 0) {
            logActivity($session['user_id'], 'Deleted role', 'Roles', 'warning', 'Deleted role ID: ' . $_GET['id']);
            sendJSONResponse(['status' => 'success', 'message' => 'Role deleted successfully']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Role not found'], 404);

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
