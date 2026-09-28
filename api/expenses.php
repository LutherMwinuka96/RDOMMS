<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/notification-preferences.php';
require_once __DIR__ . '/../config/finance-budget.php';
require_once __DIR__ . '/../config/finance-links.php';
require_once __DIR__ . '/../config/project-budget.php';

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
        if (($_GET['action'] ?? '') === 'budget-overview') {
            try {
                $overview = rdomms_finance_build_budget_overview(
                    $conn,
                    $scopeUserId,
                    $currentUser,
                    [
                        'view' => $_GET['view'] ?? 'department',
                        'department' => $_GET['department'] ?? '',
                        'organization' => $_GET['organization'] ?? $_GET['station'] ?? '',
                        'quarter' => $_GET['quarter'] ?? '',
                        'year' => $_GET['year'] ?? '',
                    ]
                );
                sendJSONResponse(['status' => 'success', 'data' => $overview]);
            } catch (Throwable $e) {
                error_log('budget-overview: ' . $e->getMessage());
                sendJSONResponse(['status' => 'error', 'message' => 'Could not load budget overview'], 500);
            }
        }

        $query = 'SELECT * FROM expenses WHERE 1=1';
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

        if (!empty($_GET['category'])) {
            $query .= ' AND category = ?';
            $params[] = $_GET['category'];
        }

        $query .= ' ORDER BY expense_date DESC, created_at DESC';
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        sendJSONResponse(['status' => 'success', 'data' => $stmt->fetchAll()]);

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        $required = ['expense_date', 'category', 'department', 'quarter', 'fiscal_year', 'amount'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                sendJSONResponse(['status' => 'error', 'message' => "Field '{$field}' is required"], 400);
            }
        }

        if ((float) $data['amount'] <= 0) {
            sendJSONResponse(['status' => 'error', 'message' => 'Amount must be greater than zero'], 400);
        }

        $link = rdomms_resolve_finance_link(
            $conn,
            $data['linked_type'] ?? null,
            $data['linked_id'] ?? null,
            $data['linked_label'] ?? null
        );
        rdomms_guard_project_linked_expense($conn, $link['linked_type'], $link['linked_id']);

        $expenseId = 'EXP' . time() . rand(100, 999);
        $stmt = $conn->prepare(
            'INSERT INTO expenses (expense_id, expense_date, category, department, quarter, fiscal_year, amount, station, description, reference, status, created_by, linked_type, linked_id, linked_label)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $result = $stmt->execute([
            $expenseId,
            $data['expense_date'],
            $data['category'],
            $data['department'],
            $data['quarter'],
            $data['fiscal_year'],
            $data['amount'],
            $data['station'] ?? null,
            $data['description'] ?? null,
            $data['reference'] ?? null,
            'pending',
            $session['user_id'],
            $link['linked_type'],
            $link['linked_id'],
            $link['linked_label'],
        ]);

        if ($result) {
            logActivity($session['user_id'], 'Created expense', 'Finance', 'success', 'Created expense for ' . $data['department'] . ': ' . $data['amount']);
            sendJSONResponse(['status' => 'success', 'message' => 'Expense created', 'data' => ['expense_id' => $expenseId]], 201);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Failed to create expense'], 500);

    case 'PUT':
        if (!empty($_GET['action']) && $_GET['action'] === 'approve') {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                $data = $_POST;
            }

            if (empty($data['id'])) {
                sendJSONResponse(['status' => 'error', 'message' => 'Expense ID required'], 400);
            }

            denyUnlessRecordOwned($conn, 'expenses', 'expense_id = ? OR id = ?', [$data['id'], $data['id']], 'created_by', $scopeUserId);

            $expenseStmt = $conn->prepare(
                'SELECT department, quarter, fiscal_year FROM expenses WHERE expense_id = ? OR id = ? LIMIT 1'
            );
            $expenseStmt->execute([$data['id'], $data['id']]);
            $expenseRow = $expenseStmt->fetch() ?: [];

            $stmt = $conn->prepare('UPDATE expenses SET status = \'approved\', approved_by = ?, approved_at = NOW() WHERE expense_id = ? OR id = ?');
            $result = $stmt->execute([$session['user_id'], $data['id'], $data['id']]);

            if ($result) {
                logActivity($session['user_id'], 'Approved expense', 'Finance', 'success', 'Approved expense ID: ' . $data['id']);
                if (!empty($expenseRow['department']) && !empty($expenseRow['quarter']) && !empty($expenseRow['fiscal_year'])) {
                    rdomms_check_budget_threshold_and_notify(
                        $conn,
                        (string) $expenseRow['department'],
                        (string) $expenseRow['quarter'],
                        (string) $expenseRow['fiscal_year']
                    );
                }
                sendJSONResponse(['status' => 'success', 'message' => 'Expense approved']);
            }

            sendJSONResponse(['status' => 'error', 'message' => 'Failed to approve expense'], 500);
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (empty($data['id']) && empty($data['expense_id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Expense ID required'], 400);
        }

        $expenseId = $data['id'] ?? $data['expense_id'];
        denyUnlessRecordOwned($conn, 'expenses', 'expense_id = ? OR id = ?', [$expenseId, $expenseId], 'created_by', $scopeUserId);

        $required = ['expense_date', 'category', 'department', 'quarter', 'fiscal_year', 'amount'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                sendJSONResponse(['status' => 'error', 'message' => "Field '{$field}' is required"], 400);
            }
        }

        $link = rdomms_resolve_finance_link(
            $conn,
            $data['linked_type'] ?? null,
            $data['linked_id'] ?? null,
            $data['linked_label'] ?? null
        );
        rdomms_guard_project_linked_expense($conn, $link['linked_type'], $link['linked_id']);

        $stmt = $conn->prepare(
            'UPDATE expenses
             SET expense_date = ?, category = ?, department = ?, quarter = ?, fiscal_year = ?, amount = ?, station = ?, description = ?, reference = ?, linked_type = ?, linked_id = ?, linked_label = ?
             WHERE expense_id = ? OR id = ?'
        );
        $result = $stmt->execute([
            $data['expense_date'],
            $data['category'],
            $data['department'],
            $data['quarter'],
            $data['fiscal_year'],
            $data['amount'],
            $data['station'] ?? null,
            $data['description'] ?? null,
            $data['reference'] ?? null,
            $link['linked_type'],
            $link['linked_id'],
            $link['linked_label'],
            $expenseId,
            $expenseId,
        ]);

        if ($result && $stmt->rowCount() > 0) {
            logActivity($session['user_id'], 'Updated expense', 'Finance', 'success', 'Updated expense ID: ' . $expenseId);
            sendJSONResponse(['status' => 'success', 'message' => 'Expense updated']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Expense not found or no changes made'], 404);

    case 'DELETE':
        if (empty($_GET['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Expense ID required'], 400);
        }

        denyUnlessRecordOwned($conn, 'expenses', 'expense_id = ? OR id = ?', [$_GET['id'], $_GET['id']], 'created_by', $scopeUserId);

        $stmt = $conn->prepare('DELETE FROM expenses WHERE expense_id = ? OR id = ?');
        $result = $stmt->execute([$_GET['id'], $_GET['id']]);

        if ($result && $stmt->rowCount() > 0) {
            logActivity($session['user_id'], 'Deleted expense', 'Finance', 'warning', 'Deleted expense ID: ' . $_GET['id']);
            sendJSONResponse(['status' => 'success', 'message' => 'Expense deleted']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Expense not found'], 404);

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
