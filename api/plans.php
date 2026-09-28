<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/planning-helpers.php';
require_once __DIR__ . '/../config/planning-access.php';

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

$method = $_SERVER['REQUEST_METHOD'];
$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$type = strtolower(trim((string) ($_GET['type'] ?? '')));

$planningRoles = rdomms_planning_module_roles();
$planningApprovalRoles = rdomms_planning_approval_roles();

if ($method === 'GET') {
    requireRole($planningRoles, $session);
} elseif ($action === 'approve' || $action === 'reject') {
    requireRole($planningApprovalRoles, $session);
} elseif (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
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

function readJsonBody() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function fetchPlanById($conn, $table, $planId, $scopeUserId) {
    $stmt = $conn->prepare("SELECT * FROM {$table} WHERE plan_id = ? LIMIT 1");
    $stmt->execute([$planId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    denyUnlessOwnsRecord($row, 'created_by', $scopeUserId);
    return $row;
}

try {

if ($action === 'migrate' && $method === 'POST') {
    $table = getPlanTableName($type);
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
        $payload = extractPlanPayload($item, $type);
        if ($type === 'monthly' && $payload['month'] === '') {
            continue;
        }
        if ($type === 'quarterly' && $payload['quarter'] === '') {
            continue;
        }

        $existing = fetchPlanById($conn, $table, $payload['plan_id'], null);
        if ($existing) {
            continue;
        }

        if ($type === 'monthly') {
            $stmt = $conn->prepare(
                'INSERT INTO monthly_plans (plan_id, month, year, staff_name, department, station, objective, notes, activities, total_budget, approval_status, reject_reason, approved_by, rejected_by, created_by, submitted_at, saved_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $payload['plan_id'],
                $payload['month'],
                $payload['year'],
                $payload['staff_name'],
                $payload['department'],
                $payload['station'],
                $payload['objective'],
                $payload['notes'],
                $payload['activities'],
                $payload['total_budget'],
                $payload['approval_status'],
                $item['reject_reason'] ?? $item['rejectReason'] ?? null,
                $item['approved_by'] ?? $item['approvedBy'] ?? null,
                $item['rejected_by'] ?? $item['rejectedBy'] ?? null,
                $userId ?: null,
                $payload['submitted_at'],
                $payload['saved_at'],
            ]);
        } elseif ($type === 'quarterly') {
            $stmt = $conn->prepare(
                'INSERT INTO quarterly_plans (plan_id, quarter, year, staff_name, department, station, objective, notes, activities, total_budget, approval_status, reject_reason, approved_by, rejected_by, created_by, submitted_at, saved_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $payload['plan_id'],
                $payload['quarter'],
                $payload['year'],
                $payload['staff_name'],
                $payload['department'],
                $payload['station'],
                $payload['objective'],
                $payload['notes'],
                $payload['activities'],
                $payload['total_budget'],
                $payload['approval_status'],
                $item['reject_reason'] ?? $item['rejectReason'] ?? null,
                $item['approved_by'] ?? $item['approvedBy'] ?? null,
                $item['rejected_by'] ?? $item['rejectedBy'] ?? null,
                $userId ?: null,
                $payload['submitted_at'],
                $payload['saved_at'],
            ]);
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO annual_plans (plan_id, year, staff_name, department, station, objective, notes, activities, total_budget, approval_status, reject_reason, approved_by, rejected_by, created_by, submitted_at, saved_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $payload['plan_id'],
                $payload['year'],
                $payload['staff_name'],
                $payload['department'],
                $payload['station'],
                $payload['objective'],
                $payload['notes'],
                $payload['activities'],
                $payload['total_budget'],
                $payload['approval_status'],
                $item['reject_reason'] ?? $item['rejectReason'] ?? null,
                $item['approved_by'] ?? $item['approvedBy'] ?? null,
                $item['rejected_by'] ?? $item['rejectedBy'] ?? null,
                $userId ?: null,
                $payload['submitted_at'],
                $payload['saved_at'],
            ]);
        }
        $imported++;
    }

    sendJSONResponse(['status' => 'success', 'message' => 'Migration complete', 'imported' => $imported]);
}

if ($action === 'all' && $method === 'GET') {
    $approval = $_GET['approval'] ?? '';
    $submittedOnly = !empty($_GET['submitted']);

    $all = [];
    foreach (['monthly', 'quarterly', 'annual'] as $planType) {
        $table = getPlanTableName($planType);
        $sql = "SELECT * FROM {$table} WHERE 1=1";
        $params = [];

        if ($submittedOnly) {
            $sql .= " AND approval_status IN ('pending', 'approved', 'rejected')";
        }
        if ($approval !== '') {
            $sql .= ' AND approval_status = ?';
            $params[] = normalizeApprovalStatus($approval, $approval);
        }

        appendPlanningListScopeFilter($sql, $params, $currentUser);
        $sql .= ' ORDER BY COALESCE(submitted_at, saved_at) DESC LIMIT 200';

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $all[] = mapPlanRowToClient($row, $planType);
        }
    }

    usort($all, function ($a, $b) {
        $aTime = strtotime((string) ($a['submitted_at'] ?? $a['saved_at'] ?? '')) ?: 0;
        $bTime = strtotime((string) ($b['submitted_at'] ?? $b['saved_at'] ?? '')) ?: 0;
        return $bTime <=> $aTime;
    });

    sendJSONResponse(['status' => 'success', 'data' => $all]);
}

$table = getPlanTableName($type);

switch ($method) {
    case 'GET':
        if (!empty($_GET['id'])) {
            $row = fetchPlanById($conn, $table, $_GET['id'], $scopeUserId);
            if (!$row) {
                sendJSONResponse(['status' => 'error', 'message' => 'Plan not found'], 404);
            }
            sendJSONResponse(['status' => 'success', 'data' => mapPlanRowToClient($row, $type)]);
        }

        $sql = "SELECT * FROM {$table} WHERE 1=1";
        $params = [];

        if (!empty($_GET['approval'])) {
            $sql .= ' AND approval_status = ?';
            $params[] = normalizeApprovalStatus($_GET['approval'], $_GET['approval']);
        }
        if (!empty($_GET['year'])) {
            $sql .= ' AND year = ?';
            $params[] = (int) $_GET['year'];
        }
        if ($type === 'monthly' && !empty($_GET['month'])) {
            $sql .= ' AND month = ?';
            $params[] = $_GET['month'];
        }
        if ($type === 'quarterly' && !empty($_GET['quarter'])) {
            $sql .= ' AND quarter = ?';
            $params[] = $_GET['quarter'];
        }
        if (!empty($_GET['submitted'])) {
            $sql .= " AND approval_status IN ('pending', 'approved', 'rejected')";
        }

        appendPlanningListScopeFilter($sql, $params, $currentUser);
        $sql .= ' ORDER BY COALESCE(submitted_at, saved_at) DESC LIMIT 200';

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = array_map(function ($row) use ($type) {
            return mapPlanRowToClient($row, $type);
        }, $stmt->fetchAll());

        sendJSONResponse(['status' => 'success', 'data' => $rows]);

    case 'POST':
        $body = readJsonBody();
        $payload = extractPlanPayload($body, $type);
        $submit = !empty($body['submit']) || $payload['approval_status'] === 'pending';

        if ($submit) {
            $payload['approval_status'] = 'pending';
            if (empty($payload['submitted_at'])) {
                $payload['submitted_at'] = date('Y-m-d H:i:s');
            }
        } else {
            $payload['approval_status'] = 'draft';
            $payload['submitted_at'] = null;
        }

        $existing = fetchPlanById($conn, $table, $payload['plan_id'], $scopeUserId);

        if ($existing) {
            denyUnlessOwnsRecord($existing, 'created_by', $scopeUserId);
            if (in_array($existing['approval_status'], ['approved'], true) && !$submit) {
                sendJSONResponse(['status' => 'error', 'message' => 'Approved plans cannot be edited as drafts'], 409);
            }

            if ($type === 'monthly') {
                $stmt = $conn->prepare(
                    'UPDATE monthly_plans SET month = ?, year = ?, staff_name = ?, department = ?, station = ?, objective = ?, notes = ?, activities = ?, total_budget = ?, approval_status = ?, submitted_at = ?, saved_at = ? WHERE plan_id = ?'
                );
                $stmt->execute([
                    $payload['month'],
                    $payload['year'],
                    $payload['staff_name'],
                    $payload['department'],
                    $payload['station'],
                    $payload['objective'],
                    $payload['notes'],
                    $payload['activities'],
                    $payload['total_budget'],
                    $payload['approval_status'],
                    $payload['submitted_at'],
                    $payload['saved_at'],
                    $payload['plan_id'],
                ]);
            } elseif ($type === 'quarterly') {
                $stmt = $conn->prepare(
                    'UPDATE quarterly_plans SET quarter = ?, year = ?, staff_name = ?, department = ?, station = ?, objective = ?, notes = ?, activities = ?, total_budget = ?, approval_status = ?, submitted_at = ?, saved_at = ? WHERE plan_id = ?'
                );
                $stmt->execute([
                    $payload['quarter'],
                    $payload['year'],
                    $payload['staff_name'],
                    $payload['department'],
                    $payload['station'],
                    $payload['objective'],
                    $payload['notes'],
                    $payload['activities'],
                    $payload['total_budget'],
                    $payload['approval_status'],
                    $payload['submitted_at'],
                    $payload['saved_at'],
                    $payload['plan_id'],
                ]);
            } else {
                $stmt = $conn->prepare(
                    'UPDATE annual_plans SET year = ?, staff_name = ?, department = ?, station = ?, objective = ?, notes = ?, activities = ?, total_budget = ?, approval_status = ?, submitted_at = ?, saved_at = ? WHERE plan_id = ?'
                );
                $stmt->execute([
                    $payload['year'],
                    $payload['staff_name'],
                    $payload['department'],
                    $payload['station'],
                    $payload['objective'],
                    $payload['notes'],
                    $payload['activities'],
                    $payload['total_budget'],
                    $payload['approval_status'],
                    $payload['submitted_at'],
                    $payload['saved_at'],
                    $payload['plan_id'],
                ]);
            }
        } else {
            if ($type === 'monthly') {
                $stmt = $conn->prepare(
                    'INSERT INTO monthly_plans (plan_id, month, year, staff_name, department, station, objective, notes, activities, total_budget, approval_status, created_by, submitted_at, saved_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $payload['plan_id'],
                    $payload['month'],
                    $payload['year'],
                    $payload['staff_name'],
                    $payload['department'],
                    $payload['station'],
                    $payload['objective'],
                    $payload['notes'],
                    $payload['activities'],
                    $payload['total_budget'],
                    $payload['approval_status'],
                    $userId ?: null,
                    $payload['submitted_at'],
                    $payload['saved_at'],
                ]);
            } elseif ($type === 'quarterly') {
                $stmt = $conn->prepare(
                    'INSERT INTO quarterly_plans (plan_id, quarter, year, staff_name, department, station, objective, notes, activities, total_budget, approval_status, created_by, submitted_at, saved_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $payload['plan_id'],
                    $payload['quarter'],
                    $payload['year'],
                    $payload['staff_name'],
                    $payload['department'],
                    $payload['station'],
                    $payload['objective'],
                    $payload['notes'],
                    $payload['activities'],
                    $payload['total_budget'],
                    $payload['approval_status'],
                    $userId ?: null,
                    $payload['submitted_at'],
                    $payload['saved_at'],
                ]);
            } else {
                $stmt = $conn->prepare(
                    'INSERT INTO annual_plans (plan_id, year, staff_name, department, station, objective, notes, activities, total_budget, approval_status, created_by, submitted_at, saved_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $payload['plan_id'],
                    $payload['year'],
                    $payload['staff_name'],
                    $payload['department'],
                    $payload['station'],
                    $payload['objective'],
                    $payload['notes'],
                    $payload['activities'],
                    $payload['total_budget'],
                    $payload['approval_status'],
                    $userId ?: null,
                    $payload['submitted_at'],
                    $payload['saved_at'],
                ]);
            }
        }

        $row = fetchPlanById($conn, $table, $payload['plan_id'], $scopeUserId);
        logActivity($userId, $submit ? 'submit_plan' : 'save_plan', 'planning', 'info', "{$type} plan {$payload['plan_id']}");
        sendJSONResponse(['status' => 'success', 'message' => $submit ? 'Plan submitted' : 'Plan saved', 'data' => mapPlanRowToClient($row, $type)]);

    case 'PUT':
        $body = readJsonBody();
        $planId = $body['id'] ?? $body['plan_id'] ?? $_GET['id'] ?? '';
        if ($planId === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Plan id is required'], 400);
        }

        $row = fetchPlanById($conn, $table, $planId, $scopeUserId);
        if (!$row) {
            sendJSONResponse(['status' => 'error', 'message' => 'Plan not found'], 404);
        }

        if ($action === 'approve') {
            $reviewer = trim((string) ($body['approved_by'] ?? $body['approvedBy'] ?? $currentUser['fullname'] ?? 'Reviewer'));
            $stmt = $conn->prepare("UPDATE {$table} SET approval_status = 'approved', approved_by = ?, reject_reason = NULL, rejected_by = NULL WHERE plan_id = ?");
            $stmt->execute([$reviewer, $planId]);
            logActivity($userId, 'approve_plan', 'planning', 'info', "Approved {$type} plan {$planId}");
        } elseif ($action === 'reject') {
            $reason = trim((string) ($body['reject_reason'] ?? $body['rejectReason'] ?? ''));
            if ($reason === '') {
                sendJSONResponse(['status' => 'error', 'message' => 'Reject reason is required'], 400);
            }
            $reviewer = trim((string) ($body['rejected_by'] ?? $body['rejectedBy'] ?? $currentUser['fullname'] ?? 'Reviewer'));
            $stmt = $conn->prepare("UPDATE {$table} SET approval_status = 'rejected', reject_reason = ?, rejected_by = ?, approved_by = NULL WHERE plan_id = ?");
            $stmt->execute([$reason, $reviewer, $planId]);
            logActivity($userId, 'reject_plan', 'planning', 'warning', "Rejected {$type} plan {$planId}");
        } else {
            $payload = extractPlanPayload(array_merge(mapPlanRowToClient($row, $type), $body), $type);
            if ($type === 'monthly') {
                $stmt = $conn->prepare(
                    'UPDATE monthly_plans SET month = ?, year = ?, staff_name = ?, department = ?, station = ?, objective = ?, notes = ?, activities = ?, total_budget = ?, approval_status = ?, submitted_at = ?, saved_at = ? WHERE plan_id = ?'
                );
                $stmt->execute([
                    $payload['month'],
                    $payload['year'],
                    $payload['staff_name'],
                    $payload['department'],
                    $payload['station'],
                    $payload['objective'],
                    $payload['notes'],
                    $payload['activities'],
                    $payload['total_budget'],
                    $payload['approval_status'],
                    $payload['submitted_at'],
                    $payload['saved_at'],
                    $planId,
                ]);
            } elseif ($type === 'quarterly') {
                $stmt = $conn->prepare(
                    'UPDATE quarterly_plans SET quarter = ?, year = ?, staff_name = ?, department = ?, station = ?, objective = ?, notes = ?, activities = ?, total_budget = ?, approval_status = ?, submitted_at = ?, saved_at = ? WHERE plan_id = ?'
                );
                $stmt->execute([
                    $payload['quarter'],
                    $payload['year'],
                    $payload['staff_name'],
                    $payload['department'],
                    $payload['station'],
                    $payload['objective'],
                    $payload['notes'],
                    $payload['activities'],
                    $payload['total_budget'],
                    $payload['approval_status'],
                    $payload['submitted_at'],
                    $payload['saved_at'],
                    $planId,
                ]);
            } else {
                $stmt = $conn->prepare(
                    'UPDATE annual_plans SET year = ?, staff_name = ?, department = ?, station = ?, objective = ?, notes = ?, activities = ?, total_budget = ?, approval_status = ?, submitted_at = ?, saved_at = ? WHERE plan_id = ?'
                );
                $stmt->execute([
                    $payload['year'],
                    $payload['staff_name'],
                    $payload['department'],
                    $payload['station'],
                    $payload['objective'],
                    $payload['notes'],
                    $payload['activities'],
                    $payload['total_budget'],
                    $payload['approval_status'],
                    $payload['submitted_at'],
                    $payload['saved_at'],
                    $planId,
                ]);
            }
        }

        $row = fetchPlanById($conn, $table, $planId, $scopeUserId);
        sendJSONResponse(['status' => 'success', 'data' => mapPlanRowToClient($row, $type)]);

    case 'DELETE':
        $planId = $_GET['id'] ?? '';
        if ($planId === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Plan id is required'], 400);
        }

        $row = fetchPlanById($conn, $table, $planId, $scopeUserId);
        if (!$row) {
            sendJSONResponse(['status' => 'error', 'message' => 'Plan not found'], 404);
        }

        denyUnlessOwnsRecord($row, 'created_by', $scopeUserId);
        $stmt = $conn->prepare("DELETE FROM {$table} WHERE plan_id = ?");
        $stmt->execute([$planId]);
        logActivity($userId, 'delete_plan', 'planning', 'warning', "Deleted {$type} plan {$planId}");
        sendJSONResponse(['status' => 'success', 'message' => 'Plan deleted']);

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

} catch (Throwable $e) {
    handlePlanningApiError($e);
}
