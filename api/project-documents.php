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

$readRoles = ['admin', 'head_of_organization_center', 'head_of_department', 'human_resource_manager', 'field_officer', 'staff', 'accountant'];
$writeRoles = ['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireRole($readRoles, $session);
} else {
    requireRole($writeRoles, $session);
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
$scopeUserId = getDataScopeUserId($currentUser);
$userId = (int) ($currentUser['id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'];
$action = strtolower(trim((string) ($_GET['action'] ?? '')));

function readJsonBody() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function fetchDocumentById($conn, $documentId, $scopeUserId) {
    $stmt = $conn->prepare('SELECT * FROM project_documents WHERE document_id = ? LIMIT 1');
    $stmt->execute([(string) $documentId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    denyUnlessOwnsRecord($row, 'created_by', $scopeUserId);
    return $row;
}

function buildDocumentId(array $data) {
    if (!empty($data['document_id'])) {
        return (string) $data['document_id'];
    }
    if (!empty($data['id'])) {
        return (string) $data['id'];
    }
    return 'pdoc-' . uniqid('', true);
}

function normalizeDocumentStatus($value) {
    $status = strtolower(trim((string) $value));
    $allowed = ['draft', 'review', 'ready', 'archived'];
    return in_array($status, $allowed, true) ? $status : 'draft';
}

function extractDocumentPayload(array $data) {
    $files = $data['files'] ?? [];
    if (!is_array($files)) {
        $files = [];
    }

    return [
        'document_id' => buildDocumentId($data),
        'title' => trim((string) ($data['title'] ?? '')),
        'category' => trim((string) ($data['category'] ?? 'project-evidence')),
        'project_name' => trim((string) ($data['project'] ?? $data['project_name'] ?? '')),
        'department' => trim((string) ($data['department'] ?? '')),
        'owner' => trim((string) ($data['owner'] ?? '')),
        'document_date' => $data['updated'] ?? $data['document_date'] ?? $data['date'] ?? null,
        'status' => normalizeDocumentStatus($data['status'] ?? 'draft'),
        'notes' => trim((string) ($data['notes'] ?? '')),
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
        $payload = extractDocumentPayload($item);
        if ($payload['title'] === '') {
            continue;
        }
        if (fetchDocumentById($conn, $payload['document_id'], null)) {
            continue;
        }

        $stmt = $conn->prepare(
            'INSERT INTO project_documents (document_id, title, category, project_name, department, owner, document_date, status, notes, files, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $payload['document_id'],
            $payload['title'],
            $payload['category'],
            $payload['project_name'] ?: null,
            $payload['department'] ?: null,
            $payload['owner'] ?: null,
            $payload['document_date'] ?: null,
            $payload['status'],
            $payload['notes'],
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
            $row = fetchDocumentById($conn, $_GET['id'], $scopeUserId);
            if (!$row) {
                sendJSONResponse(['status' => 'error', 'message' => 'Document not found'], 404);
            }
            sendJSONResponse(['status' => 'success', 'data' => mapProjectDocumentRowToClient($row)]);
        }

        $sql = 'SELECT * FROM project_documents WHERE 1=1';
        $params = [];
        appendDataScopeFilter($sql, $params, 'created_by', $scopeUserId);

        if (!empty($_GET['category']) && $_GET['category'] !== 'all') {
            $sql .= ' AND category = ?';
            $params[] = $_GET['category'];
        }
        if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
            $sql .= ' AND status = ?';
            $params[] = $_GET['status'];
        }

        $sql .= ' ORDER BY updated_at DESC LIMIT 500';
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = array_map('mapProjectDocumentRowToClient', $stmt->fetchAll());
        sendJSONResponse(['status' => 'success', 'data' => $rows]);

    case 'POST':
        $body = readJsonBody();
        $payload = extractDocumentPayload($body);

        if ($payload['title'] === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Title is required'], 400);
        }

        $existing = fetchDocumentById($conn, $payload['document_id'], $scopeUserId);
        if ($existing) {
            $stmt = $conn->prepare(
                'UPDATE project_documents SET title = ?, category = ?, project_name = ?, department = ?, owner = ?, document_date = ?, status = ?, notes = ?, files = ?, updated_at = NOW() WHERE document_id = ?'
            );
            $stmt->execute([
                $payload['title'],
                $payload['category'],
                $payload['project_name'] ?: null,
                $payload['department'] ?: null,
                $payload['owner'] ?: null,
                $payload['document_date'] ?: null,
                $payload['status'],
                $payload['notes'],
                $payload['files'],
                $payload['document_id'],
            ]);
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO project_documents (document_id, title, category, project_name, department, owner, document_date, status, notes, files, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $payload['document_id'],
                $payload['title'],
                $payload['category'],
                $payload['project_name'] ?: null,
                $payload['department'] ?: null,
                $payload['owner'] ?: null,
                $payload['document_date'] ?: null,
                $payload['status'],
                $payload['notes'],
                $payload['files'],
                $userId ?: null,
            ]);
        }

        $row = fetchDocumentById($conn, $payload['document_id'], $scopeUserId);
        logActivity($userId, 'save_project_document', 'documents', 'info', "Saved document {$payload['document_id']}");
        sendJSONResponse(['status' => 'success', 'data' => mapProjectDocumentRowToClient($row)]);

    case 'DELETE':
        $documentId = (string) ($_GET['id'] ?? '');
        if ($documentId === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Document id is required'], 400);
        }

        $row = fetchDocumentById($conn, $documentId, $scopeUserId);
        if (!$row) {
            sendJSONResponse(['status' => 'error', 'message' => 'Document not found'], 404);
        }

        $stmt = $conn->prepare('DELETE FROM project_documents WHERE document_id = ?');
        $stmt->execute([$documentId]);
        logActivity($userId, 'delete_project_document', 'documents', 'warning', "Deleted document {$documentId}");
        sendJSONResponse(['status' => 'success', 'message' => 'Document deleted']);

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
} catch (Throwable $e) {
    handlePlanningApiError($e);
}
