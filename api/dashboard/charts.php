<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/dashboard-scope.php';
require_once __DIR__ . '/../../config/role-kpis.php';
require_once __DIR__ . '/../../config/funding-sources-catalog.php';

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
$chartYear = (int) ($_GET['chart_year'] ?? date('Y'));
if ($chartYear < 1980 || $chartYear > 2100) {
    $chartYear = (int) date('Y');
}

$quarterLabels = ['Q1', 'Q2', 'Q3', 'Q4'];
$budgetByQuarter = [0.0, 0.0, 0.0, 0.0];
$expenseByQuarter = [0.0, 0.0, 0.0, 0.0];

if (dashboardCanViewFinanceKpis($currentUser)) {
    $budgetSql = "SELECT quarter,
            COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) AS approved_total
     FROM budget_allocations
     WHERE fiscal_year = ?";
    $budgetParams = [$chartYear];
    appendDashboardFinanceScope($budgetSql, $budgetParams, $currentUser);
    $budgetSql .= ' GROUP BY quarter';
    $stmt = $conn->prepare($budgetSql);
    $stmt->execute($budgetParams);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $index = ['q1' => 0, 'q2' => 1, 'q3' => 2, 'q4' => 3][strtolower((string) ($row['quarter'] ?? ''))] ?? -1;
        if ($index >= 0) {
            $budgetByQuarter[$index] = (float) ($row['approved_total'] ?? 0);
        }
    }

    $expenseSql = "SELECT
        CASE
            WHEN LOWER(COALESCE(quarter, '')) = 'q1' THEN 0
            WHEN LOWER(COALESCE(quarter, '')) = 'q2' THEN 1
            WHEN LOWER(COALESCE(quarter, '')) = 'q3' THEN 2
            WHEN LOWER(COALESCE(quarter, '')) = 'q4' THEN 3
            WHEN MONTH(expense_date) BETWEEN 1 AND 3 THEN 0
            WHEN MONTH(expense_date) BETWEEN 4 AND 6 THEN 1
            WHEN MONTH(expense_date) BETWEEN 7 AND 9 THEN 2
            ELSE 3
        END AS quarter_index,
        COALESCE(SUM(amount), 0) AS expense_total
     FROM expenses
     WHERE status <> 'rejected'
       AND (
            fiscal_year = ?
            OR (fiscal_year IS NULL AND YEAR(expense_date) = ?)
            OR YEAR(expense_date) = ?
       )";
    $expenseParams = [$chartYear, $chartYear, $chartYear];
    appendDashboardFinanceScope($expenseSql, $expenseParams, $currentUser);
    $expenseSql .= ' GROUP BY quarter_index';
    $stmt = $conn->prepare($expenseSql);
    $stmt->execute($expenseParams);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $index = (int) ($row['quarter_index'] ?? -1);
        if ($index >= 0 && $index <= 3) {
            $expenseByQuarter[$index] = (float) ($row['expense_total'] ?? 0);
        }
    }
}

$projectStatusSql = 'SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = \'ongoing\' THEN 1 ELSE 0 END) AS ongoing,
            SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status = \'delayed\' THEN 1 ELSE 0 END) AS delayed,
            SUM(CASE WHEN status = \'not-started\' THEN 1 ELSE 0 END) AS not_started
     FROM projects WHERE 1=1';
$projectStatusParams = [];
appendDashboardProjectScope($projectStatusSql, $projectStatusParams, $currentUser, $scopeMode);
$stmt = $conn->prepare($projectStatusSql);
$stmt->execute($projectStatusParams);
$projectStatus = $stmt->fetch() ?: [];

$fundingSql = 'SELECT funding_source, donor_name, COALESCE(SUM(budget), 0) AS total_budget
     FROM projects
     WHERE 1=1';
$fundingParams = [];
appendDashboardProjectScope($fundingSql, $fundingParams, $currentUser, $scopeMode);
$fundingSql .= ' GROUP BY funding_source';
$stmt = $conn->prepare($fundingSql);
$stmt->execute($fundingParams);
$fundingSources = rdomms_build_funding_sources_chart_data($stmt->fetchAll() ?: []);

$monthLabels = [];
$monthlyProjects = array_fill(0, 12, 0);
$monthlyCompleted = array_fill(0, 12, 0);
$monthlyProgressTotals = array_fill(0, 12, 0.0);

for ($monthIndex = 0; $monthIndex < 12; $monthIndex++) {
    $monthLabels[] = date('M', mktime(0, 0, 0, $monthIndex + 1, 1, $chartYear));
}

$monthlySql = 'SELECT status, progress, start_date, created_at, end_date
     FROM projects
     WHERE DATE(COALESCE(start_date, created_at)) BETWEEN ? AND ?';
$rangeStart = sprintf('%04d-01-01', $chartYear);
$rangeEnd = sprintf('%04d-12-31', $chartYear);
$monthlyParams = [$rangeStart, $rangeEnd];
appendDashboardProjectScope($monthlySql, $monthlyParams, $currentUser, $scopeMode);
$stmt = $conn->prepare($monthlySql);
$stmt->execute($monthlyParams);

foreach ($stmt->fetchAll() ?: [] as $project) {
    $dateValue = $project['start_date'] ?: $project['created_at'] ?: $project['end_date'];
    $timestamp = strtotime((string) $dateValue);
    if ($timestamp === false || (int) date('Y', $timestamp) !== $chartYear) {
        continue;
    }

    $monthIndex = (int) date('n', $timestamp) - 1;
    if ($monthIndex < 0 || $monthIndex > 11) {
        continue;
    }

    $monthlyProjects[$monthIndex]++;
    $monthlyProgressTotals[$monthIndex] += (float) ($project['progress'] ?? 0);
    if (strtolower((string) ($project['status'] ?? '')) === 'completed') {
        $monthlyCompleted[$monthIndex]++;
    }
}

$monthlyAvgProgress = array_map(
    static fn(int $count, float $total): float => $count > 0 ? round($total / $count, 1) : 0.0,
    $monthlyProjects,
    $monthlyProgressTotals
);

sendJSONResponse([
    'status' => 'success',
    'data' => [
        'scope' => $scope,
        'visibility' => buildRoleKpiVisibility($currentUser),
        'scope_mode' => $scopeMode,
        'year' => $chartYear,
        'date_range' => ['start' => $startDate, 'end' => $endDate],
        'quarterly_finance' => [
            'labels' => $quarterLabels,
            'budget' => array_map('round', $budgetByQuarter),
            'expense' => array_map('round', $expenseByQuarter),
            'total_budget' => (int) round(array_sum($budgetByQuarter)),
            'total_expense' => (int) round(array_sum($expenseByQuarter)),
        ],
        'project_status' => [
            'total' => (int) ($projectStatus['total'] ?? 0),
            'ongoing' => (int) ($projectStatus['ongoing'] ?? 0),
            'completed' => (int) ($projectStatus['completed'] ?? 0),
            'delayed' => (int) ($projectStatus['delayed'] ?? 0),
            'not_started' => (int) ($projectStatus['not_started'] ?? 0),
        ],
        'funding_sources' => $fundingSources,
        'overview_monthly' => [
            'labels' => $monthLabels,
            'projects' => $monthlyProjects,
            'completed' => $monthlyCompleted,
            'avg_progress' => $monthlyAvgProgress,
        ],
    ],
]);
