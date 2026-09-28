<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/project-budget.php';

$c = (new Database())->getConnection();
ensureProjectBudgetSchema($c);

echo "=== Projects with budget status ===\n";
$rows = $c->query("SELECT project_id, name, budget, budget_approval_status, budget_submitted_at, status, progress, created_by FROM projects ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $mapped = rdomms_map_project_to_client($row);
    echo json_encode([
        'project_id' => $row['project_id'],
        'name' => $row['name'],
        'budget' => $row['budget'],
        'db_status' => $row['budget_approval_status'],
        'mapped_status' => $mapped['budget_approval_status'],
        'can_operate' => $mapped['budget_can_operate'],
        'project_status' => $row['status'],
        'created_by' => $row['created_by'],
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "\n=== Allocation linked status ===\n";
$alloc = $c->query("SELECT linked_id, amount, status FROM budget_allocations WHERE linked_type = 'project' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($alloc as $a) {
    echo json_encode($a) . PHP_EOL;
}
