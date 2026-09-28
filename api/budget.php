<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/finance-links.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireRole(['admin', 'accountant'], $session);
} elseif (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true)) {
    requireRole(['accountant'], $session);
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    requireRole(['accountant'], $session);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

$currentUser = getAuthenticatedUser($session);
$scopeUserId = getFinanceDataScopeUserId($currentUser);
$method = $_SERVER['REQUEST_METHOD'];

ensureFinanceLinkSchema($conn);

switch ($method) {
    case 'GET':
        $query = 'SELECT * FROM budget_allocations WHERE 1=1';
        $params = [];
        appendFinanceListScopeFilter($query, $params, $currentUser);

        if (!empty($_GET['department'])) {
            $query .= ' AND department = ?';
            $params[] = $_GET['department'];
        }

        if (!empty($_GET['station']) || !empty($_GET['organization'])) {
            $query .= ' AND station = ?';
            $params[] = $_GET['station'] ?? $_GET['organization'];
        }

        if (!empty($_GET['quarter'])) {
            $query .= ' AND quarter = ?';
            $params[] = $_GET['quarter'];
        }

        if (!empty($_GET['year'])) {
            $query .= ' AND fiscal_year = ?';
            $params[] = $_GET['year'];
        }

        if (!empty($_GET['status'])) {
            $query .= ' AND status = ?';
            $params[] = $_GET['status'];
        }

        $query .= ' ORDER BY created_at DESC';
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        sendJSONResponse(['status' => 'success', 'data' => $stmt->fetchAll()]);

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        $required = ['department', 'quarter', 'fiscal_year', 'amount'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                sendJSONResponse(['status' => 'error', 'message' => "Field '{$field}' is required"], 400);
            }
        }

        $link = rdomms_resolve_finance_link(
            $conn,
            $data['linked_type'] ?? null,
            $data['linked_id'] ?? null,
            $data['linked_label'] ?? null
        );

        $allocationId = 'ALLOC' . time() . rand(100, 999);
        $stmt = $conn->prepare(
            'INSERT INTO budget_allocations (allocation_id, department, quarter, fiscal_year, amount, station, description, status, created_by, linked_type, linked_id, linked_label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $result = $stmt->execute([
            $allocationId,
            $data['department'],
            $data['quarter'],
            $data['fiscal_year'],
            $data['amount'],
            $data['station'] ?? null,
            $data['description'] ?? null,
            'pending',
            $session['user_id'],
            $link['linked_type'],
            $link['linked_id'],
            $link['linked_label'],
        ]);

        if ($result) {
            logActivity($session['user_id'], 'Created budget allocation', 'Finance', 'success', 'Created allocation for ' . $data['department'] . ': ' . $data['amount']);
            sendJSONResponse(['status' => 'success', 'message' => 'Budget allocation created', 'data' => ['allocation_id' => $allocationId]], 201);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Failed to create allocation'], 500);

    case 'PUT':
        if (!empty($_GET['action']) && $_GET['action'] === 'approve') {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                $data = $_POST;
            }

            if (empty($data['id'])) {
                sendJSONResponse(['status' => 'error', 'message' => 'Allocation ID required'], 400);
            }

            denyUnlessRecordOwned($conn, 'budget_allocations', 'allocation_id = ? OR id = ?', [$data['id'], $data['id']], 'created_by', $scopeUserId);

            $stmt = $conn->prepare('UPDATE budget_allocations SET status = \'approved\', approved_by = ?, approved_at = NOW() WHERE allocation_id = ? OR id = ?');
            $result = $stmt->execute([$session['user_id'], $data['id'], $data['id']]);

            if ($result) {
                logActivity($session['user_id'], 'Approved budget allocation', 'Finance', 'success', 'Approved allocation ID: ' . $data['id']);
                sendJSONResponse(['status' => 'success', 'message' => 'Allocation approved']);
            }

            sendJSONResponse(['status' => 'error', 'message' => 'Failed to approve allocation'], 500);
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (empty($data['id']) && empty($data['allocation_id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Allocation ID required'], 400);
        }

        $allocationId = $data['id'] ?? $data['allocation_id'];
        denyUnlessRecordOwned($conn, 'budget_allocations', 'allocation_id = ? OR id = ?', [$allocationId, $allocationId], 'created_by', $scopeUserId);

        $required = ['department', 'quarter', 'fiscal_year', 'amount'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                sendJSONResponse(['status' => 'error', 'message' => "Field '{$field}' is required"], 400);
            }
        }

        $link = rdomms_resolve_finance_link(
            $conn,
            $data['linked_type'] ?? null,
            $data['linked_id'] ?? null,
            $data['linked_label'] ?? null
        );

        $stmt = $conn->prepare(
            'UPDATE budget_allocations
             SET department = ?, quarter = ?, fiscal_year = ?, amount = ?, station = ?, description = ?, linked_type = ?, linked_id = ?, linked_label = ?, updated_at = CURRENT_TIMESTAMP
             WHERE allocation_id = ? OR id = ?'
        );
        $result = $stmt->execute([
            $data['department'],
            $data['quarter'],
            $data['fiscal_year'],
            $data['amount'],
            $data['station'] ?? null,
            $data['description'] ?? null,
            $link['linked_type'],
            $link['linked_id'],
            $link['linked_label'],
            $allocationId,
            $allocationId
        ]);

        if ($result && $stmt->rowCount() > 0) {
            logActivity($session['user_id'], 'Updated budget allocation', 'Finance', 'success', 'Updated allocation ID: ' . $allocationId);
            sendJSONResponse(['status' => 'success', 'message' => 'Budget allocation updated']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Allocation not found or no changes made'], 404);

    case 'DELETE':
        if (empty($_GET['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Allocation ID required'], 400);
        }

        denyUnlessRecordOwned($conn, 'budget_allocations', 'allocation_id = ? OR id = ?', [$_GET['id'], $_GET['id']], 'created_by', $scopeUserId);

        $stmt = $conn->prepare('DELETE FROM budget_allocations WHERE allocation_id = ? OR id = ?');
        $result = $stmt->execute([$_GET['id'], $_GET['id']]);

        if ($result && $stmt->rowCount() > 0) {
            logActivity($session['user_id'], 'Deleted budget allocation', 'Finance', 'warning', 'Deleted allocation ID: ' . $_GET['id']);
            sendJSONResponse(['status' => 'success', 'message' => 'Allocation deleted']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Allocation not found'], 404);

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
