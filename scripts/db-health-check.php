<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed'], JSON_PRETTY_PRINT);
    exit(1);
}

$tables = [
    'users',
    'user_sessions',
    'budget_allocations',
    'expenses',
    'projects',
    'beneficiaries',
    'activity_logs',
];

$counts = [];
foreach ($tables as $table) {
    try {
        $stmt = $conn->query("SELECT COUNT(*) AS c FROM {$table}");
        $counts[$table] = (int) $stmt->fetch()['c'];
    } catch (Throwable $e) {
        $counts[$table] = 'missing: ' . $e->getMessage();
    }
}

$admin = $conn->prepare("SELECT id, user_id, email, role, status FROM users WHERE email = ? LIMIT 1");
$admin->execute(['ffilipatali@yahoo.com']);
$adminUser = $admin->fetch() ?: null;

$latestExpense = $conn->query('SELECT expense_id, expense_date, department, amount, status, created_at FROM expenses ORDER BY created_at DESC LIMIT 3')->fetchAll();
$latestBudget = $conn->query('SELECT allocation_id, department, quarter, fiscal_year, amount, status, created_at FROM budget_allocations ORDER BY created_at DESC LIMIT 3')->fetchAll();

echo json_encode([
    'ok' => true,
    'database' => 'rdomms_db',
    'table_counts' => $counts,
    'admin_user' => $adminUser,
    'latest_expenses' => $latestExpense,
    'latest_budget_allocations' => $latestBudget,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
