<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/dashboard-scope.php';
require_once __DIR__ . '/../../config/role-kpis.php';
require_once __DIR__ . '/../../config/data-backup.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$session = verifySession();
if (!$session) {
    sendJSONResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

$currentUser = getAuthenticatedUser($session);
if (!$currentUser) {
    sendJSONResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$scopeMode = getScopeModeFromRequest($currentUser);
$scope = resolveDashboardScopeMetadata($currentUser, $scopeMode);
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$projectSql = 'SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = \'ongoing\' THEN 1 ELSE 0 END) AS ongoing_count,
            SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN status = \'delayed\' THEN 1 ELSE 0 END) AS delayed_count,
            SUM(CASE WHEN status = \'not-started\' THEN 1 ELSE 0 END) AS not_started_count,
            COALESCE(SUM(budget), 0) AS total_budget,
            COALESCE(SUM(beneficiaries), 0) AS total_beneficiaries,
            COALESCE(ROUND(AVG(progress)), 0) AS avg_progress
     FROM projects WHERE 1=1';
$projectParams = [];
appendDashboardProjectScope($projectSql, $projectParams, $currentUser, $scopeMode);
$stmt = $conn->prepare($projectSql);
$stmt->execute($projectParams);
$projectsStats = $stmt->fetch() ?: [];
$projectsStats['ongoing'] = $projectsStats['ongoing_count'] ?? 0;
$projectsStats['completed'] = $projectsStats['completed_count'] ?? 0;
$projectsStats['delayed'] = $projectsStats['delayed_count'] ?? 0;
$projectsStats['not_started'] = $projectsStats['not_started_count'] ?? 0;

$budgetStats = ['total_budget' => 0, 'total_allocated' => 0, 'allocation_count' => 0];
$expenseStats = ['total_expenses' => 0, 'approved_expenses' => 0, 'expense_count' => 0];
$utilizationRate = 0.0;

if (dashboardCanViewFinanceKpis($currentUser)) {
    $budgetSql = "SELECT COALESCE(SUM(amount), 0) AS total_budget,
            COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) AS total_allocated,
            COUNT(*) AS allocation_count
     FROM budget_allocations WHERE 1=1";
    $budgetParams = [];
    appendDashboardFinanceScope($budgetSql, $budgetParams, $currentUser);
    $stmt = $conn->prepare($budgetSql);
    $stmt->execute($budgetParams);
    $budgetStats = $stmt->fetch() ?: $budgetStats;

    $expenseSql = 'SELECT COALESCE(SUM(amount), 0) AS total_expenses,
            COALESCE(SUM(CASE WHEN status = \'approved\' THEN amount ELSE 0 END), 0) AS approved_expenses,
            COUNT(*) AS expense_count
     FROM expenses
     WHERE expense_date BETWEEN ? AND ?';
    $expenseParams = [$startDate, $endDate];
    appendDashboardFinanceScope($expenseSql, $expenseParams, $currentUser);
    $stmt = $conn->prepare($expenseSql);
    $stmt->execute($expenseParams);
    $expenseStats = $stmt->fetch() ?: $expenseStats;

    $approvedBudget = (float) ($budgetStats['total_allocated'] ?? 0);
    $totalExpenses = (float) ($expenseStats['total_expenses'] ?? 0);
    if ($approvedBudget > 0) {
        $utilizationRate = round(($totalExpenses / $approvedBudget) * 100, 1);
    }
}

$beneficiarySql = 'SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = \'active\' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN registered_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS new_this_month
     FROM beneficiaries WHERE 1=1';
$beneficiaryParams = [$startDate, $endDate];
appendDashboardBeneficiaryScope($beneficiarySql, $beneficiaryParams, $currentUser, $scopeMode);
$stmt = $conn->prepare($beneficiarySql);
$stmt->execute($beneficiaryParams);
$beneficiaryStats = $stmt->fetch() ?: ['total' => 0, 'active' => 0, 'new_this_month' => 0];

$userStats = ['total' => 0, 'active' => 0, 'admins' => 0];
if (dashboardCanViewUserKpis($currentUser)) {
    $userSql = 'SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = \'active\' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN role = \'admin\' THEN 1 ELSE 0 END) AS admins
     FROM users WHERE 1=1';
    $userParams = [];
    appendDashboardUserScope($userSql, $userParams, $currentUser);
    $stmt = $conn->prepare($userSql);
    $stmt->execute($userParams);
    $userStats = $stmt->fetch() ?: $userStats;
}

$autoBackup = null;
if (normalizeRoleName($currentUser['role'] ?? '') === 'admin') {
    try {
        $autoBackup = rdomms_maybe_run_scheduled_backup($conn, (int) ($session['user_id'] ?? 0));
    } catch (Throwable $e) {
        error_log('Scheduled backup skipped: ' . $e->getMessage());
    }
}

sendJSONResponse([
    'status' => 'success',
    'data' => [
        'projects' => $projectsStats,
        'budget' => $budgetStats,
        'expenses' => $expenseStats,
        'beneficiaries' => $beneficiaryStats,
        'users' => $userStats,
        'utilization_rate' => $utilizationRate,
        'date_range' => ['start' => $startDate, 'end' => $endDate],
        'scope' => $scope,
        'visibility' => buildRoleKpiVisibility($currentUser),
        'scope_mode' => $scopeMode,
        'auto_backup' => $autoBackup,
    ],
]);
