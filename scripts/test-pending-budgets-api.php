<?php
/**
 * Simulate pending-budgets API for an accountant user (CLI smoke test).
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/project-budget.php';

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

ensureProjectBudgetSchema($conn);

$stmt = $conn->query("SELECT id, role, organization, department FROM users WHERE LOWER(role) = 'accountant' AND status = 'active' LIMIT 1");
$accountant = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$accountant) {
    fwrite(STDERR, "No active accountant found\n");
    exit(1);
}

$currentUser = [
    'id' => (int) $accountant['id'],
    'role' => $accountant['role'],
    'organization' => $accountant['organization'] ?? '',
    'department' => $accountant['department'] ?? '',
];

$scopeMode = null;
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

echo json_encode([
    'status' => 'success',
    'accountant_id' => $currentUser['id'],
    'count' => count($rows),
    'data' => array_map(static fn(array $r) => [
        'project_id' => $r['project_id'] ?? $r['id'] ?? null,
        'name' => $r['name'] ?? null,
        'budget' => $r['budget'] ?? null,
        'budget_approval_status' => $r['budget_approval_status'] ?? $r['budgetApprovalStatus'] ?? null,
    ], $rows),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
