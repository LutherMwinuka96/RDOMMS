<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/planning-helpers.php';

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

require_once __DIR__ . '/../config/planning-access.php';

$planningRoles = rdomms_planning_module_roles();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireRole($planningRoles, $session);
} else {
    requireRole($planningRoles, $session);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

try {
    ensurePlanningSchema($conn);
} catch (Throwable $e) {
    handlePlanningApiError($e);
}

$currentUser = getAuthenticatedUser($session);
$scopeUserId = getPlanningDataScopeUserId($currentUser);
$userId = (int) ($currentUser['id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'];
$action = strtolower(trim((string) ($_GET['action'] ?? '')));

function readJsonBody() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function fetchTargetById($conn, $targetId, $scopeUserId) {
    $stmt = $conn->prepare('SELECT * FROM targets WHERE target_id = ? LIMIT 1');
    $stmt->execute([(string) $targetId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    denyUnlessOwnsRecord($row, 'created_by', $scopeUserId);
    return $row;
}

function buildTargetId(array $data) {
    if (!empty($data['target_id'])) {
        return (string) $data['target_id'];
    }
    if (!empty($data['id'])) {
        return 'TGT-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $data['id']);
    }
    return 'TGT-' . time() . rand(100, 999);
}

if ($action === 'migrate' && $method === 'POST') {
    $body = readJsonBody();
    $items = $body['targets'] ?? $body['items'] ?? [];
    $history = $body['history'] ?? [];
    if (!is_array($items)) {
        sendJSONResponse(['status' => 'error', 'message' => 'targets must be an array'], 400);
    }

    $imported = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $targetId = buildTargetId($item);
        if (fetchTargetById($conn, $targetId, null)) {
            continue;
        }

        $stmt = $conn->prepare(
            'INSERT INTO targets (target_id, name, category, period, target_value, unit, current_value, description, status, progress_history, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $targetId,
            trim((string) ($item['name'] ?? '')),
            trim((string) ($item['category'] ?? '')),
            trim((string) ($item['period'] ?? '')),
            (float) ($item['targetValue'] ?? $item['target_value'] ?? 0),
            trim((string) ($item['unit'] ?? '')),
            (float) ($item['currentValue'] ?? $item['current_value'] ?? 0),
            trim((string) ($item['description'] ?? '')),
            trim((string) ($item['status'] ?? 'not_started')),
            json_encode(is_array($history) ? $history : [], JSON_UNESCAPED_UNICODE),
            $userId ?: null,
        ]);
        $imported++;
    }

    sendJSONResponse(['status' => 'success', 'imported' => $imported]);
}

try {
switch ($method) {
    case 'GET':
        if (!empty($_GET['id'])) {
            $row = fetchTargetById($conn, $_GET['id'], $scopeUserId);
            if (!$row) {
                sendJSONResponse(['status' => 'error', 'message' => 'Target not found'], 404);
            }
            sendJSONResponse(['status' => 'success', 'data' => mapTargetRowToClient($row)]);
        }

        $sql = 'SELECT * FROM targets WHERE 1=1';
        $params = [];
        appendPlanningListScopeFilter($sql, $params, $currentUser, '', [
            'has_department' => false,
            'has_station' => false,
        ]);

        if (!empty($_GET['category'])) {
            $sql .= ' AND category = ?';
            $params[] = $_GET['category'];
        }

        $sql .= ' ORDER BY updated_at DESC LIMIT 200';
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = array_map('mapTargetRowToClient', $stmt->fetchAll());
        sendJSONResponse(['status' => 'success', 'data' => $rows]);

    case 'POST':
        $body = readJsonBody();
        if ($action === 'progress') {
            $targetId = (string) ($body['targetId'] ?? $body['target_id'] ?? $body['id'] ?? '');
            $currentValue = (float) ($body['currentValue'] ?? $body['current_value'] ?? 0);
            $notes = trim((string) ($body['notes'] ?? ''));

            if ($targetId === '') {
                sendJSONResponse(['status' => 'error', 'message' => 'Target id is required'], 400);
            }

            $row = fetchTargetById($conn, $targetId, $scopeUserId);
            if (!$row) {
                sendJSONResponse(['status' => 'error', 'message' => 'Target not found'], 404);
            }

            $history = decodeJsonColumn($row['progress_history'] ?? null);
            $history[] = [
                'targetId' => $targetId,
                'currentValue' => $currentValue,
                'notes' => $notes,
                'updatedAt' => date('c'),
            ];

            $targetValue = (float) ($row['target_value'] ?? 0);
            $progress = $targetValue > 0 ? ($currentValue / $targetValue) * 100 : 0;
            $status = 'not_started';
            if ($progress >= 100) {
                $status = 'achieved';
            } elseif ($progress >= 75) {
                $status = 'on_track';
            } elseif ($progress >= 50) {
                $status = 'in_progress';
            } elseif ($progress >= 25) {
                $status = 'behind';
            }

            $stmt = $conn->prepare(
                'UPDATE targets SET current_value = ?, status = ?, progress_history = ?, updated_at = NOW() WHERE target_id = ?'
            );
            $stmt->execute([$currentValue, $status, json_encode($history, JSON_UNESCAPED_UNICODE), $targetId]);

            $row = fetchTargetById($conn, $targetId, $scopeUserId);
            sendJSONResponse(['status' => 'success', 'data' => mapTargetRowToClient($row)]);
        }

        $targetId = buildTargetId($body);
        if (fetchTargetById($conn, $targetId, null)) {
            sendJSONResponse(['status' => 'error', 'message' => 'Target already exists'], 409);
        }

        $stmt = $conn->prepare(
            'INSERT INTO targets (target_id, name, category, period, target_value, unit, current_value, description, status, progress_history, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $targetId,
            trim((string) ($body['name'] ?? '')),
            trim((string) ($body['category'] ?? '')),
            trim((string) ($body['period'] ?? '')),
            (float) ($body['targetValue'] ?? $body['target_value'] ?? 0),
            trim((string) ($body['unit'] ?? '')),
            (float) ($body['currentValue'] ?? $body['current_value'] ?? 0),
            trim((string) ($body['description'] ?? '')),
            trim((string) ($body['status'] ?? 'not_started')),
            json_encode([], JSON_UNESCAPED_UNICODE),
            $userId ?: null,
        ]);

        $row = fetchTargetById($conn, $targetId, $scopeUserId);
        logActivity($userId, 'create_target', 'planning', 'info', "Created target {$targetId}");
        sendJSONResponse(['status' => 'success', 'data' => mapTargetRowToClient($row)]);

    case 'PUT':
        $body = readJsonBody();
        $targetId = (string) ($body['id'] ?? $body['target_id'] ?? $_GET['id'] ?? '');
        if ($targetId === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Target id is required'], 400);
        }

        $row = fetchTargetById($conn, $targetId, $scopeUserId);
        if (!$row) {
            sendJSONResponse(['status' => 'error', 'message' => 'Target not found'], 404);
        }

        $stmt = $conn->prepare(
            'UPDATE targets SET name = ?, category = ?, period = ?, target_value = ?, unit = ?, description = ?, status = ?, updated_at = NOW() WHERE target_id = ?'
        );
        $stmt->execute([
            trim((string) ($body['name'] ?? $row['name'])),
            trim((string) ($body['category'] ?? $row['category'])),
            trim((string) ($body['period'] ?? $row['period'])),
            (float) ($body['targetValue'] ?? $body['target_value'] ?? $row['target_value']),
            trim((string) ($body['unit'] ?? $row['unit'])),
            trim((string) ($body['description'] ?? $row['description'])),
            trim((string) ($body['status'] ?? $row['status'])),
            $targetId,
        ]);

        $row = fetchTargetById($conn, $targetId, $scopeUserId);
        logActivity($userId, 'update_target', 'planning', 'info', "Updated target {$targetId}");
        sendJSONResponse(['status' => 'success', 'data' => mapTargetRowToClient($row)]);

    case 'DELETE':
        $targetId = (string) ($_GET['id'] ?? '');
        if ($targetId === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Target id is required'], 400);
        }

        $row = fetchTargetById($conn, $targetId, $scopeUserId);
        if (!$row) {
            sendJSONResponse(['status' => 'error', 'message' => 'Target not found'], 404);
        }

        $stmt = $conn->prepare('DELETE FROM targets WHERE target_id = ?');
        $stmt->execute([$targetId]);
        logActivity($userId, 'delete_target', 'planning', 'warning', "Deleted target {$targetId}");
        sendJSONResponse(['status' => 'success', 'message' => 'Target deleted']);

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
} catch (Throwable $e) {
    handlePlanningApiError($e);
}
