<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/notification-tables.php';

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

$currentUser = getAuthenticatedUser($session);
$scopeUserId = getDataScopeUserId($currentUser);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = normalizeRoleName($currentUser['role'] ?? '');
$canApprove = in_array($role, ['admin', 'head_of_organization_center', 'head_of_department'], true);
$canApproveUsers = in_array($role, ['admin', 'head_of_organization_center', 'human_resource_manager'], true);

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

rdomms_ensure_notification_tables($conn);
$method = $_SERVER['REQUEST_METHOD'];

function approvalRowToResponse(array $row): array
{
    return [
        'id' => $row['request_id'] ?? $row['id'],
        'title' => $row['title'] ?? '',
        'type' => $row['request_type'] ?? '',
        'module' => $row['module'] ?? '',
        'priority' => $row['priority'] ?? 'medium',
        'requester' => $row['requester'] ?? '',
        'desc' => $row['description'] ?? '',
        'status' => $row['status'] ?? 'pending',
        'rejectReason' => $row['reject_reason'] ?? '',
        'createdAt' => $row['created_at'] ?? '',
        'resolvedAt' => $row['resolved_at'] ?? '',
        'source' => $row['source'] ?? 'manual',
    ];
}

function fetchPendingUserRequests(PDO $conn): array
{
    $stmt = $conn->query(
        "SELECT user_id, fullname, email, role, created_at
         FROM users
         WHERE status = 'pending'
         ORDER BY created_at DESC
         LIMIT 50"
    );
    $rows = $stmt ? $stmt->fetchAll() : [];
    $items = [];

    foreach ($rows as $row) {
        $items[] = [
            'id' => 'user_' . ($row['user_id'] ?? ''),
            'title' => 'New user registration approval',
            'type' => 'User Registration',
            'module' => 'Users',
            'priority' => 'high',
            'requester' => $row['fullname'] ?? 'Unknown user',
            'desc' => 'Pending account: ' . ($row['email'] ?? '') . ' · Role: ' . ($row['role'] ?? ''),
            'status' => 'pending',
            'rejectReason' => '',
            'createdAt' => $row['created_at'] ?? '',
            'resolvedAt' => '',
            'source' => 'users',
            'reference_id' => $row['user_id'] ?? '',
        ];
    }

    return $items;
}

switch ($method) {
    case 'GET':
        requireRole(['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff', 'accountant', 'human_resource_manager'], $session);

        $query = 'SELECT * FROM approval_requests WHERE 1=1';
        $params = [];
        appendDataScopeFilter($query, $params, 'created_by', $scopeUserId);

        if (!empty($_GET['status'])) {
            $query .= ' AND status = ?';
            $params[] = $_GET['status'];
        }

        if (!empty($_GET['module'])) {
            $query .= ' AND module = ?';
            $params[] = $_GET['module'];
        }

        if (!empty($_GET['priority'])) {
            $query .= ' AND priority = ?';
            $params[] = $_GET['priority'];
        }

        if (!empty($_GET['search'])) {
            $query .= ' AND (title LIKE ? OR requester LIKE ? OR description LIKE ?)';
            $search = '%' . $_GET['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $query .= ' ORDER BY created_at DESC LIMIT 200';
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $stored = array_map(static function ($row) {
            $row['source'] = 'manual';
            return approvalRowToResponse($row);
        }, $stmt->fetchAll());

        $merged = $stored;
        if ($canApprove) {
            $merged = array_merge(fetchPendingUserRequests($conn), $stored);
        } elseif ($canApproveUsers) {
            $merged = fetchPendingUserRequests($conn);
        }

        sendJSONResponse(['status' => 'success', 'data' => $merged]);
        break;

    case 'POST':
        requireRole(['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff', 'accountant', 'human_resource_manager'], $session);

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        $title = trim((string) ($data['title'] ?? ''));
        $requester = trim((string) ($data['requester'] ?? ''));
        $type = trim((string) ($data['type'] ?? $data['request_type'] ?? ''));

        if ($title === '' || $requester === '' || $type === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Title, requester, and type are required'], 400);
        }

        $requestId = 'REQ' . time() . rand(100, 999);
        $stmt = $conn->prepare(
            'INSERT INTO approval_requests (request_id, title, request_type, module, priority, requester, description, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', ?)'
        );
        $result = $stmt->execute([
            $requestId,
            $title,
            $type,
            $data['module'] ?? 'General',
            $data['priority'] ?? 'medium',
            $requester,
            $data['desc'] ?? $data['description'] ?? null,
            $userId,
        ]);

        if ($result) {
            logActivity($userId, 'Submitted approval request', 'Approvals', 'info', 'Request: ' . $title);
            sendJSONResponse(['status' => 'success', 'message' => 'Request submitted', 'data' => ['request_id' => $requestId]], 201);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Failed to submit request'], 500);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (empty($data['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Request ID required'], 400);
        }

        $action = strtolower(trim((string) ($data['action'] ?? '')));
        $id = (string) $data['id'];

        if (strpos($id, 'user_') === 0) {
            if (!$canApproveUsers) {
                sendJSONResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
            }
        } elseif (!$canApprove) {
            sendJSONResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        if (strpos($id, 'user_') === 0) {
            $userRef = substr($id, 5);
            if ($action === 'approve') {
                $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE user_id = ? OR id = ?");
                $stmt->execute([$userRef, $userRef]);
                logActivity($userId, 'Approved user registration', 'Approvals', 'success', 'User: ' . $userRef);
                sendJSONResponse(['status' => 'success', 'message' => 'User approved']);
            }

            if ($action === 'reject') {
                $reason = trim((string) ($data['rejectReason'] ?? $data['reject_reason'] ?? ''));
                $stmt = $conn->prepare("UPDATE users SET status = 'suspended', notes = ? WHERE user_id = ? OR id = ?");
                $stmt->execute([$reason !== '' ? $reason : 'Rejected during approval review', $userRef, $userRef]);
                logActivity($userId, 'Rejected user registration', 'Approvals', 'warning', 'User: ' . $userRef);
                sendJSONResponse(['status' => 'success', 'message' => 'User registration rejected']);
            }

            sendJSONResponse(['status' => 'error', 'message' => 'Invalid action'], 400);
        }

        if ($action === 'approve') {
            $stmt = $conn->prepare(
                "UPDATE approval_requests
                 SET status = 'approved', reject_reason = NULL, resolved_by = ?, resolved_at = NOW()
                 WHERE request_id = ? OR id = ?"
            );
            $stmt->execute([$userId, $id, $id]);
            logActivity($userId, 'Approved request', 'Approvals', 'success', 'Request ID: ' . $id);
            sendJSONResponse(['status' => 'success', 'message' => 'Request approved']);
        }

        if ($action === 'reject') {
            $reason = trim((string) ($data['rejectReason'] ?? $data['reject_reason'] ?? ''));
            if ($reason === '') {
                sendJSONResponse(['status' => 'error', 'message' => 'Reject reason is required'], 400);
            }
            $stmt = $conn->prepare(
                "UPDATE approval_requests
                 SET status = 'rejected', reject_reason = ?, resolved_by = ?, resolved_at = NOW()
                 WHERE request_id = ? OR id = ?"
            );
            $stmt->execute([$reason, $userId, $id, $id]);
            logActivity($userId, 'Rejected request', 'Approvals', 'warning', 'Request ID: ' . $id);
            sendJSONResponse(['status' => 'success', 'message' => 'Request rejected']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Invalid action'], 400);
        break;

    case 'DELETE':
        if (empty($_GET['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Request ID required'], 400);
        }

        if (strpos((string) $_GET['id'], 'user_') === 0) {
            sendJSONResponse(['status' => 'error', 'message' => 'System user requests cannot be deleted from this queue'], 400);
        }

        denyUnlessRecordOwned($conn, 'approval_requests', 'request_id = ? OR id = ?', [$_GET['id'], $_GET['id']], 'created_by', $scopeUserId);

        $stmt = $conn->prepare('DELETE FROM approval_requests WHERE request_id = ? OR id = ?');
        $result = $stmt->execute([$_GET['id'], $_GET['id']]);

        if ($result && $stmt->rowCount() > 0) {
            sendJSONResponse(['status' => 'success', 'message' => 'Request deleted']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Request not found'], 404);
        break;

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
