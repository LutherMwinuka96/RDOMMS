<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/notification-preferences.php';
require_once __DIR__ . '/../config/funding-sources-catalog.php';
require_once __DIR__ . '/../config/project-budget.php';
require_once __DIR__ . '/../config/project-deregistration.php';

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

$requestAction = strtolower(trim((string) ($_GET['action'] ?? '')));
$accountantApprovalActions = ['approve-budget', 'reject-budget', 'approve-deregistration', 'reject-deregistration'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireRole(['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff', 'accountant'], $session);
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' && in_array($requestAction, $accountantApprovalActions, true)) {
    requireRole(['admin', 'head_of_organization_center', 'head_of_department', 'accountant'], $session);
} elseif (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'], true)) {
    requireRole(['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff'], $session);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

ensureProjectBudgetSchema($conn);
ensureProjectDeregistrationSchema($conn);

$currentUser = getAuthenticatedUser($session);
$scopeUserId = getDataScopeUserId($currentUser);
$scopeMode = getScopeModeFromRequest($currentUser);
$method = $_SERVER['REQUEST_METHOD'];
$action = strtolower(trim((string) ($_GET['action'] ?? '')));

switch ($method) {
    case 'GET':
        if ($action === 'pending-budgets') {
            try {
                $query = 'SELECT * FROM projects WHERE ' . rdomms_sql_projects_awaiting_budget_approval();
                $params = [];
                appendProjectListScopeFilter($query, $params, $currentUser, $scopeMode);
                $query .= ' ORDER BY budget_submitted_at DESC, created_at DESC LIMIT 200';

                $stmt = $conn->prepare($query);
                $stmt->execute($params);
                $rows = array_map('rdomms_map_project_to_client', $stmt->fetchAll() ?: []);
                $rows = array_values(array_filter(
                    $rows,
                    static fn(array $row): bool => rdomms_user_can_approve_project_budget($currentUser, $row, $conn)
                ));

                sendJSONResponse(['status' => 'success', 'data' => $rows]);
            } catch (Throwable $e) {
                error_log('pending-budgets failed: ' . $e->getMessage());
                // Fallback without allocation subquery if collation mismatch persists.
                $query = "SELECT * FROM projects WHERE budget > 0 AND (
                    budget_approval_status = 'pending'
                    OR (budget_approval_status = 'draft' AND budget_submitted_at IS NOT NULL)
                )";
                $params = [];
                appendProjectListScopeFilter($query, $params, $currentUser, $scopeMode);
                $query .= ' ORDER BY budget_submitted_at DESC, created_at DESC LIMIT 200';
                $stmt = $conn->prepare($query);
                $stmt->execute($params);
                $rows = array_map('rdomms_map_project_to_client', $stmt->fetchAll() ?: []);
                $rows = array_values(array_filter(
                    $rows,
                    static fn(array $row): bool => rdomms_user_can_approve_project_budget($currentUser, $row, $conn)
                ));
                sendJSONResponse(['status' => 'success', 'data' => $rows]);
            }
        }

        if ($action === 'pending-deregistrations') {
            $query = "SELECT * FROM projects WHERE deregistration_status = 'pending'";
            $params = [];
            appendProjectListScopeFilter($query, $params, $currentUser, $scopeMode);
            $query .= ' ORDER BY deregistration_requested_at DESC, created_at DESC LIMIT 200';

            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $rows = array_map('rdomms_map_project_to_client', $stmt->fetchAll() ?: []);
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => rdomms_user_can_approve_project_deregistration($currentUser, $row, $conn)
            ));

            sendJSONResponse(['status' => 'success', 'data' => $rows]);
        }

        if ($action === 'deregistration-preview' && isset($_GET['id'])) {
            $project = rdomms_fetch_project_row($conn, (string) $_GET['id']);
            if (!$project) {
                sendJSONResponse(['status' => 'error', 'message' => 'Project not found'], 404);
            }
            denyUnlessCanManageProject($conn, $currentUser, (string) $_GET['id'], $scopeUserId);

            sendJSONResponse([
                'status' => 'success',
                'data' => [
                    'project' => rdomms_map_project_to_client($project),
                    'linked_records' => rdomms_count_project_linked_records($conn, $project),
                ],
            ]);
        }

        if (isset($_GET['id'])) {
            $stmt = $conn->prepare('SELECT * FROM projects WHERE project_id = ? OR id = ? LIMIT 1');
            $stmt->execute([$_GET['id'], $_GET['id']]);
            $project = $stmt->fetch();

            if ($project) {
                if (normalizeRoleName($currentUser['role'] ?? '') !== 'accountant') {
                    denyUnlessOwnsRecord($project, 'created_by', $scopeUserId);
                }
                sendJSONResponse(['status' => 'success', 'data' => rdomms_map_project_to_client($project)]);
            }

            sendJSONResponse(['status' => 'error', 'message' => 'Project not found'], 404);
        }

        $query = 'SELECT * FROM projects WHERE 1=1';
        $params = [];
        appendProjectListScopeFilter($query, $params, $currentUser, $scopeMode);

        if (!empty($_GET['status'])) {
            $query .= ' AND status = ?';
            $params[] = $_GET['status'];
        }

        if (!empty($_GET['sector'])) {
            $query .= ' AND sector = ?';
            $params[] = $_GET['sector'];
        }

        if (!empty($_GET['budget_approval_status'])) {
            $query .= ' AND budget_approval_status = ?';
            $params[] = $_GET['budget_approval_status'];
        }

        $query .= ' ORDER BY created_at DESC';
        $projectLimit = userCanViewAllData($currentUser) ? 10000 : 500;
        $query .= ' LIMIT ' . (int) $projectLimit;

        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $projects = array_map(static function (array $project): array {
            $project['funding_source'] = normalizeRdommsFundingSourceLabel(
                (string) ($project['funding_source'] ?? ''),
                (string) ($project['donor_name'] ?? '')
            );
            return rdomms_map_project_to_client($project);
        }, $stmt->fetchAll() ?: []);

        sendJSONResponse(['status' => 'success', 'data' => $projects]);

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (empty($data['name'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Project name is required'], 400);
        }

        if (isset($data['team']) && is_array($data['team'])) {
            validateProjectTeamMembers($conn, $currentUser, $data['team']);
        }

        $projectId = 'PRJ' . time() . rand(100, 999);
        $teamJson = isset($data['team']) ? json_encode($data['team']) : null;
        $ownerId = (int) ($currentUser['id'] ?? 0);
        $budget = (float) ($data['budget'] ?? 0);

        $fundingSource = normalizeRdommsFundingSourceLabel(
            (string) ($data['fundingSource'] ?? $data['funding_source'] ?? ''),
            (string) ($data['donorName'] ?? $data['donor_name'] ?? '')
        );

        $budgetApprovalStatus = $budget > 0 ? 'pending' : 'draft';
        $budgetSubmittedAt = $budget > 0 ? date('Y-m-d H:i:s') : null;

        $stmt = $conn->prepare(
            'INSERT INTO projects (
                project_id, name, description, organization, district, location, sector,
                start_date, end_date, budget, funding_source, donor_name, beneficiaries,
                progress, status, team, created_by,
                budget_approval_status, budget_submitted_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $result = $stmt->execute([
            $projectId,
            $data['name'],
            $data['description'] ?? null,
            $data['organization'] ?? null,
            $data['district'] ?? null,
            $data['location'] ?? null,
            $data['sector'] ?? null,
            $data['startDate'] ?? $data['start_date'] ?? null,
            $data['dueDate'] ?? $data['end_date'] ?? null,
            $budget,
            $fundingSource,
            $data['donorName'] ?? null,
            $data['beneficiaries'] ?? 0,
            0,
            'not-started',
            $teamJson,
            $ownerId > 0 ? $ownerId : null,
            $budgetApprovalStatus,
            $budgetSubmittedAt,
        ]);

        if ($result) {
            $projectRow = rdomms_fetch_project_row($conn, $projectId);
            if ($projectRow && $budget > 0) {
                rdomms_create_project_budget_allocation($conn, $projectRow, $ownerId);
                rdomms_notify_project_budget_event($conn, 'submitted', $projectRow);
            }

            logActivity($session['user_id'] ?? null, 'Created project', 'Projects', 'success', 'Created project: ' . $data['name']);
            sendJSONResponse([
                'status' => 'success',
                'message' => $budget > 0
                    ? 'Project created. Budget request submitted for approval.'
                    : 'Project created successfully',
                'data' => $projectRow
                    ? rdomms_map_project_to_client($projectRow)
                    : [
                        'project_id' => $projectId,
                        'budget_approval_status' => $budgetApprovalStatus,
                    ],
            ], 201);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Failed to create project'], 500);

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (empty($data['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Project ID required'], 400);
        }

        $projectId = (string) $data['id'];
        $currentProject = rdomms_fetch_project_row($conn, $projectId);
        if (!$currentProject) {
            sendJSONResponse(['status' => 'error', 'message' => 'Project not found'], 404);
        }

        if ($action === 'approve-budget') {
            rdomms_guard_project_deregistration_pending($currentProject);
            $updated = rdomms_approve_project_budget($conn, $currentUser, $projectId);
            sendJSONResponse([
                'status' => 'success',
                'message' => 'Project budget approved.',
                'data' => rdomms_map_project_to_client($updated),
            ]);
        }

        if ($action === 'reject-budget') {
            rdomms_guard_project_deregistration_pending($currentProject);
            $reason = trim((string) ($data['reject_reason'] ?? $data['rejectReason'] ?? ''));
            $updated = rdomms_reject_project_budget($conn, $currentUser, $projectId, $reason);
            sendJSONResponse([
                'status' => 'success',
                'message' => 'Project budget rejected.',
                'data' => rdomms_map_project_to_client($updated),
            ]);
        }

        if ($action === 'resubmit-budget') {
            denyUnlessCanManageProject($conn, $currentUser, $projectId, $scopeUserId);
            rdomms_guard_project_deregistration_pending($currentProject);
            $budget = array_key_exists('budget', $data) ? (float) $data['budget'] : (float) ($currentProject['budget'] ?? 0);
            if ($budget <= 0) {
                sendJSONResponse(['status' => 'error', 'message' => 'Enter a valid budget amount before resubmitting.'], 400);
            }

            $stmt = $conn->prepare('UPDATE projects SET budget = ? WHERE project_id = ? OR id = ?');
            $stmt->execute([$budget, $projectId, $projectId]);
            $currentProject = rdomms_fetch_project_row($conn, $projectId) ?: $currentProject;
            $updated = rdomms_submit_project_budget_request($conn, $currentProject, (int) ($currentUser['id'] ?? 0));

            sendJSONResponse([
                'status' => 'success',
                'message' => 'Budget request resubmitted for approval.',
                'data' => rdomms_map_project_to_client($updated),
            ]);
        }

        if ($action === 'request-deregistration') {
            denyUnlessCanManageProject($conn, $currentUser, $projectId, $scopeUserId);
            $reason = trim((string) ($data['reason'] ?? $data['deregistration_reason'] ?? ''));
            $updated = rdomms_request_project_deregistration($conn, $currentUser, $projectId, $reason);
            sendJSONResponse([
                'status' => 'success',
                'message' => 'Project removal request submitted for approval.',
                'data' => rdomms_map_project_to_client($updated),
            ]);
        }

        if ($action === 'cancel-deregistration') {
            denyUnlessCanManageProject($conn, $currentUser, $projectId, $scopeUserId);
            $updated = rdomms_cancel_project_deregistration($conn, $currentUser, $projectId);
            sendJSONResponse([
                'status' => 'success',
                'message' => 'Project removal request cancelled.',
                'data' => rdomms_map_project_to_client($updated),
            ]);
        }

        if ($action === 'approve-deregistration') {
            $result = rdomms_approve_project_deregistration($conn, $currentUser, $projectId);
            sendJSONResponse([
                'status' => 'success',
                'message' => 'Project and linked budget/expenses were removed.',
                'data' => $result,
            ]);
        }

        if ($action === 'reject-deregistration') {
            $reason = trim((string) ($data['reject_reason'] ?? $data['rejectReason'] ?? ''));
            $updated = rdomms_reject_project_deregistration($conn, $currentUser, $projectId, $reason);
            sendJSONResponse([
                'status' => 'success',
                'message' => 'Project removal request rejected.',
                'data' => rdomms_map_project_to_client($updated),
            ]);
        }

        if ($action === 'deregister') {
            $reason = trim((string) ($data['reason'] ?? $data['deregistration_reason'] ?? ''));
            $result = rdomms_admin_deregister_project($conn, $currentUser, $projectId, $reason);
            sendJSONResponse([
                'status' => 'success',
                'message' => 'Project and linked records were removed immediately.',
                'data' => $result,
            ]);
        }

        denyUnlessCanUpdateProject($conn, $currentUser, $projectId, $scopeUserId);
        rdomms_guard_project_deregistration_pending($currentProject);
        rdomms_guard_project_operational_update($currentProject, $data);

        $budgetStatus = rdomms_project_budget_status($currentProject);
        if (array_key_exists('budget', $data) && in_array($budgetStatus, ['pending', 'approved'], true)) {
            sendJSONResponse([
                'status' => 'error',
                'message' => 'Budget amount cannot be changed while approval is pending or after approval. Resubmit a new request if rejected.',
            ], 400);
        }

        $previousStmt = $conn->prepare('SELECT name, status, created_by FROM projects WHERE project_id = ? OR id = ? LIMIT 1');
        $previousStmt->execute([$projectId, $projectId]);
        $previousProject = $previousStmt->fetch() ?: [];

        $updates = [];
        $params = [];
        $allowedFields = ['name', 'description', 'location', 'sector', 'start_date', 'end_date', 'budget', 'progress', 'status', 'beneficiaries'];

        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if ($field === 'budget' && $budgetStatus === 'rejected') {
                $updates[] = "{$field} = ?";
                $params[] = $data[$field];
                continue;
            }
            if ($field === 'budget') {
                continue;
            }
            $updates[] = "{$field} = ?";
            $params[] = $data[$field];
        }

        if (isset($data['team'])) {
            if (is_array($data['team'])) {
                validateProjectTeamMembers($conn, $currentUser, $data['team']);
            }
            $updates[] = 'team = ?';
            $params[] = json_encode($data['team']);
        }

        if (!$updates) {
            sendJSONResponse(['status' => 'error', 'message' => 'No fields to update'], 400);
        }

        $params[] = $projectId;
        $params[] = $projectId;
        $query = 'UPDATE projects SET ' . implode(', ', $updates) . ' WHERE project_id = ? OR id = ?';
        $stmt = $conn->prepare($query);
        $result = $stmt->execute($params);

        if ($result) {
            logActivity($session['user_id'] ?? null, 'Updated project', 'Projects', 'info', 'Updated project ID: ' . $projectId);

            if (array_key_exists('status', $data)) {
                $oldStatus = (string) ($previousProject['status'] ?? '');
                $newStatus = (string) $data['status'];
                if ($oldStatus !== '' && $oldStatus !== $newStatus) {
                    $projectName = (string) ($previousProject['name'] ?? $data['name'] ?? $projectId);
                    rdomms_dispatch_notification(
                        $conn,
                        'project_status',
                        'Project status changed',
                        sprintf('Project "%s" status changed from %s to %s.', $projectName, $oldStatus, $newStatus),
                        'Projects',
                        'info'
                    );

                    $creatorId = (int) ($previousProject['created_by'] ?? 0);
                    if ($creatorId > 0) {
                        rdomms_notify_user_account(
                            $conn,
                            'project_status',
                            'Project status updated',
                            sprintf('Your project "%s" status changed from %s to %s.', $projectName, $oldStatus, $newStatus),
                            'Projects',
                            'info',
                            $creatorId
                        );
                    }
                }
            }

            sendJSONResponse(['status' => 'success', 'message' => 'Project updated successfully']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Failed to update project'], 500);

    case 'DELETE':
        if (empty($_GET['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Project ID required'], 400);
        }

        $projectId = (string) $_GET['id'];
        $project = rdomms_fetch_project_row($conn, $projectId);
        if (!$project) {
            sendJSONResponse(['status' => 'error', 'message' => 'Project not found'], 404);
        }

        $role = normalizeRoleName($currentUser['role'] ?? '');
        if ($role === 'admin') {
            $reason = trim((string) ($_GET['reason'] ?? 'Deleted via API by administrator'));
            $result = rdomms_admin_deregister_project($conn, $currentUser, $projectId, $reason);
            sendJSONResponse([
                'status' => 'success',
                'message' => 'Project and linked records removed.',
                'data' => $result,
            ]);
        }

        denyUnlessCanManageProject($conn, $currentUser, $projectId, $scopeUserId);
        if (rdomms_project_deregistration_status($project) !== 'pending') {
            sendJSONResponse([
                'status' => 'error',
                'message' => 'Submit a removal request first. Permanent delete requires approval.',
                'code' => 'deregistration_required',
            ], 400);
        }

        if (!rdomms_user_can_approve_project_deregistration($currentUser, $project, $conn)) {
            sendJSONResponse(['status' => 'error', 'message' => 'Only approvers can complete project removal.'], 403);
        }

        $result = rdomms_approve_project_deregistration($conn, $currentUser, $projectId);
        sendJSONResponse([
            'status' => 'success',
            'message' => 'Project and linked records removed.',
            'data' => $result,
        ]);

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
