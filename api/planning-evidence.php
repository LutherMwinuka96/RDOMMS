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

function fetchEvidenceById($conn, $evidenceId, $scopeUserId) {
    $stmt = $conn->prepare('SELECT * FROM planning_evidence WHERE evidence_id = ? LIMIT 1');
    $stmt->execute([(string) $evidenceId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    denyUnlessOwnsRecord($row, 'created_by', $scopeUserId);
    return $row;
}

function buildEvidenceId(array $data) {
    if (!empty($data['evidence_id'])) {
        return (string) $data['evidence_id'];
    }
    if (!empty($data['id'])) {
        return (string) $data['id'];
    }
    return 'evidence-' . uniqid('', true);
}

function extractEvidencePayload(array $data) {
    $files = $data['files'] ?? [];
    if (!is_array($files)) {
        $files = [];
    }

    return [
        'evidence_id' => buildEvidenceId($data),
        'title' => trim((string) ($data['title'] ?? '')),
        'evidence_type' => trim((string) ($data['type'] ?? $data['evidence_type'] ?? 'other')),
        'related_activity' => trim((string) ($data['relatedActivity'] ?? $data['related_activity'] ?? '')),
        'evidence_date' => $data['date'] ?? $data['evidence_date'] ?? null,
        'location' => trim((string) ($data['location'] ?? '')),
        'station' => trim((string) ($data['station'] ?? '')),
        'department' => trim((string) ($data['department'] ?? '')),
        'uploaded_by' => trim((string) ($data['uploadedBy'] ?? $data['uploaded_by'] ?? '')),
        'description' => trim((string) ($data['description'] ?? '')),
        'files' => json_encode($files, JSON_UNESCAPED_UNICODE),
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
        $payload = extractEvidencePayload($item);
        if ($payload['title'] === '') {
            continue;
        }
        if (fetchEvidenceById($conn, $payload['evidence_id'], null)) {
            continue;
        }

        $stmt = $conn->prepare(
            'INSERT INTO planning_evidence (evidence_id, title, evidence_type, related_activity, evidence_date, location, station, department, uploaded_by, description, files, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $payload['evidence_id'],
            $payload['title'],
            $payload['evidence_type'],
            $payload['related_activity'],
            $payload['evidence_date'] ?: null,
            $payload['location'],
            $payload['station'],
            $payload['department'],
            $payload['uploaded_by'],
            $payload['description'],
            $payload['files'],
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
            $row = fetchEvidenceById($conn, $_GET['id'], $scopeUserId);
            if (!$row) {
                sendJSONResponse(['status' => 'error', 'message' => 'Evidence not found'], 404);
            }
            sendJSONResponse(['status' => 'success', 'data' => mapEvidenceRowToClient($row)]);
        }

        $sql = 'SELECT * FROM planning_evidence WHERE 1=1';
        $params = [];
        appendPlanningListScopeFilter($sql, $params, $currentUser);

        if (!empty($_GET['type'])) {
            $sql .= ' AND evidence_type = ?';
            $params[] = $_GET['type'];
        }

        $sql .= ' ORDER BY evidence_date DESC, created_at DESC LIMIT 300';
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = array_map('mapEvidenceRowToClient', $stmt->fetchAll());
        sendJSONResponse(['status' => 'success', 'data' => $rows]);

    case 'POST':
        $body = readJsonBody();
        $payload = extractEvidencePayload($body);

        if ($payload['title'] === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Title is required'], 400);
        }

        $existing = fetchEvidenceById($conn, $payload['evidence_id'], $scopeUserId);
        if ($existing) {
            sendJSONResponse(['status' => 'error', 'message' => 'Evidence already exists'], 409);
        }

        $stmt = $conn->prepare(
            'INSERT INTO planning_evidence (evidence_id, title, evidence_type, related_activity, evidence_date, location, station, department, uploaded_by, description, files, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $payload['evidence_id'],
            $payload['title'],
            $payload['evidence_type'],
            $payload['related_activity'],
            $payload['evidence_date'] ?: null,
            $payload['location'],
            $payload['station'],
            $payload['department'],
            $payload['uploaded_by'],
            $payload['description'],
            $payload['files'],
            $userId ?: null,
        ]);

        $row = fetchEvidenceById($conn, $payload['evidence_id'], $scopeUserId);
        logActivity($userId, 'upload_evidence', 'planning', 'info', "Uploaded evidence {$payload['evidence_id']}");
        sendJSONResponse(['status' => 'success', 'data' => mapEvidenceRowToClient($row)]);

    case 'DELETE':
        $evidenceId = (string) ($_GET['id'] ?? '');
        if ($evidenceId === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Evidence id is required'], 400);
        }

        $row = fetchEvidenceById($conn, $evidenceId, $scopeUserId);
        if (!$row) {
            sendJSONResponse(['status' => 'error', 'message' => 'Evidence not found'], 404);
        }

        $stmt = $conn->prepare('DELETE FROM planning_evidence WHERE evidence_id = ?');
        $stmt->execute([$evidenceId]);
        logActivity($userId, 'delete_evidence', 'planning', 'warning', "Deleted evidence {$evidenceId}");
        sendJSONResponse(['status' => 'success', 'message' => 'Evidence deleted']);

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
} catch (Throwable $e) {
    handlePlanningApiError($e);
}
