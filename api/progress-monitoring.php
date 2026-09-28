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

function fetchProgressById($conn, $updateId, $scopeUserId) {
    $stmt = $conn->prepare('SELECT * FROM progress_updates WHERE update_id = ? LIMIT 1');
    $stmt->execute([(string) $updateId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    denyUnlessOwnsRecord($row, 'created_by', $scopeUserId);
    return $row;
}

function buildUpdateId(array $data) {
    if (!empty($data['update_id'])) {
        return (string) $data['update_id'];
    }
    if (!empty($data['id'])) {
        return (string) $data['id'];
    }
    return 'progress-' . uniqid('', true);
}

function extractProgressPayload(array $data) {
    $progress = (int) ($data['progress'] ?? 0);
    $progress = max(0, min(100, $progress));
    $status = trim((string) ($data['status'] ?? ''));
    if ($status === '') {
        if ($progress >= 100) {
            $status = 'completed';
        } elseif ($progress >= 75) {
            $status = 'on-track';
        } elseif ($progress >= 50) {
            $status = 'at-risk';
        } else {
            $status = 'behind';
        }
    }

    return [
        'update_id' => buildUpdateId($data),
        'project_name' => trim((string) ($data['projectName'] ?? $data['project_name'] ?? '')),
        'activity_name' => trim((string) ($data['activityName'] ?? $data['activity_name'] ?? '')),
        'station' => trim((string) ($data['station'] ?? '')),
        'department' => trim((string) ($data['department'] ?? '')),
        'start_date' => $data['startDate'] ?? $data['start_date'] ?? null,
        'end_date' => $data['endDate'] ?? $data['end_date'] ?? null,
        'progress' => $progress,
        'status' => $status,
        'description' => trim((string) ($data['description'] ?? '')),
        'challenges' => trim((string) ($data['challenges'] ?? '')),
    ];
}

if ($action === 'migrate' && $method === 'POST') {
    $body = readJsonBody();
    $items = $body['items'] ?? [];
    if (!is_array($items)) {
        sendJSONResponse(['status' => 'error', 'message' => 'items must be an array'], 400);
    }

    $imported = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $payload = extractProgressPayload($item);
        if ($payload['project_name'] === '' || $payload['activity_name'] === '') {
            continue;
        }
        if (fetchProgressById($conn, $payload['update_id'], null)) {
            continue;
        }

        $stmt = $conn->prepare(
            'INSERT INTO progress_updates (update_id, project_name, activity_name, station, department, start_date, end_date, progress, status, description, challenges, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $payload['update_id'],
            $payload['project_name'],
            $payload['activity_name'],
            $payload['station'],
            $payload['department'],
            $payload['start_date'] ?: null,
            $payload['end_date'] ?: null,
            $payload['progress'],
            $payload['status'],
            $payload['description'],
            $payload['challenges'],
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
            $row = fetchProgressById($conn, $_GET['id'], $scopeUserId);
            if (!$row) {
                sendJSONResponse(['status' => 'error', 'message' => 'Progress record not found'], 404);
            }
            sendJSONResponse(['status' => 'success', 'data' => mapProgressRowToClient($row)]);
        }

        $sql = 'SELECT * FROM progress_updates WHERE 1=1';
        $params = [];
        appendPlanningListScopeFilter($sql, $params, $currentUser);

        if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
            $sql .= ' AND status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['station']) && $_GET['station'] !== 'all') {
            $sql .= ' AND station = ?';
            $params[] = $_GET['station'];
        }

        $sql .= ' ORDER BY updated_at DESC LIMIT 300';
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = array_map('mapProgressRowToClient', $stmt->fetchAll());
        sendJSONResponse(['status' => 'success', 'data' => $rows]);

    case 'POST':
        $body = readJsonBody();
        $payload = extractProgressPayload($body);

        if ($payload['project_name'] === '' || $payload['activity_name'] === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Project and activity names are required'], 400);
        }

        $existing = fetchProgressById($conn, $payload['update_id'], $scopeUserId);
        if ($existing) {
            $stmt = $conn->prepare(
                'UPDATE progress_updates SET project_name = ?, activity_name = ?, station = ?, department = ?, start_date = ?, end_date = ?, progress = ?, status = ?, description = ?, challenges = ?, updated_at = NOW() WHERE update_id = ?'
            );
            $stmt->execute([
                $payload['project_name'],
                $payload['activity_name'],
                $payload['station'],
                $payload['department'],
                $payload['start_date'] ?: null,
                $payload['end_date'] ?: null,
                $payload['progress'],
                $payload['status'],
                $payload['description'],
                $payload['challenges'],
                $payload['update_id'],
            ]);
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO progress_updates (update_id, project_name, activity_name, station, department, start_date, end_date, progress, status, description, challenges, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $payload['update_id'],
                $payload['project_name'],
                $payload['activity_name'],
                $payload['station'],
                $payload['department'],
                $payload['start_date'] ?: null,
                $payload['end_date'] ?: null,
                $payload['progress'],
                $payload['status'],
                $payload['description'],
                $payload['challenges'],
                $userId ?: null,
            ]);
        }

        $row = fetchProgressById($conn, $payload['update_id'], $scopeUserId);
        logActivity($userId, 'save_progress', 'planning', 'info', "Saved progress {$payload['update_id']}");
        sendJSONResponse(['status' => 'success', 'data' => mapProgressRowToClient($row)]);

    case 'DELETE':
        $updateId = (string) ($_GET['id'] ?? '');
        if ($updateId === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Progress id is required'], 400);
        }

        $row = fetchProgressById($conn, $updateId, $scopeUserId);
        if (!$row) {
            sendJSONResponse(['status' => 'error', 'message' => 'Progress record not found'], 404);
        }

        $stmt = $conn->prepare('DELETE FROM progress_updates WHERE update_id = ?');
        $stmt->execute([$updateId]);
        logActivity($userId, 'delete_progress', 'planning', 'warning', "Deleted progress {$updateId}");
        sendJSONResponse(['status' => 'success', 'message' => 'Progress record deleted']);

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
} catch (Throwable $e) {
    handlePlanningApiError($e);
}
