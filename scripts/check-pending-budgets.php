<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/project-budget.php';

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

ensureProjectBudgetSchema($conn);

echo "=== Recent projects ===\n";
$stmt = $conn->query('SELECT project_id, name, budget, budget_approval_status, budget_submitted_at FROM projects ORDER BY id DESC LIMIT 15');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Awaiting approval (SQL) ===\n";
$sql = 'SELECT project_id, name, budget, budget_approval_status FROM projects WHERE ' . rdomms_sql_projects_awaiting_budget_approval() . ' ORDER BY id DESC LIMIT 15';
foreach ($conn->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Pending allocations linked to projects ===\n";
$alloc = $conn->query("SELECT allocation_id, linked_id, linked_label, amount, status FROM budget_allocations WHERE linked_type = 'project' ORDER BY id DESC LIMIT 15");
foreach ($alloc->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Accountant users ===\n";
$users = $conn->query("SELECT id, user_id, email, role, status FROM users WHERE LOWER(role) = 'accountant'");
foreach ($users->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}
