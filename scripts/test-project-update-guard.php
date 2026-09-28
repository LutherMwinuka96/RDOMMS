<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/project-budget.php';
require_once __DIR__ . '/../config/project-deregistration.php';

$c = (new Database())->getConnection();
ensureProjectBudgetSchema($c);
ensureProjectDeregistrationSchema($c);

$projectId = 'PRJ1783512934998';
$stmt = $c->prepare('SELECT id, user_id, fullname, email, role, department, organization FROM users WHERE id = ?');
$stmt->execute([68]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$project = rdomms_fetch_project_row($c, $projectId);
$scopeUserId = getDataScopeUserId($user);

echo "User: " . json_encode($user) . PHP_EOL;
echo "Scope user id: {$scopeUserId}" . PHP_EOL;
echo "Project created_by: {$project['created_by']}" . PHP_EOL;
echo "Budget status: " . rdomms_project_budget_status($project) . PHP_EOL;
echo "Can operate: " . (rdomms_project_can_operate($project) ? 'yes' : 'no') . PHP_EOL;

// Simulate denyUnlessCanManageProject
$creatorMatch = (int)($project['created_by'] ?? 0) === (int)$scopeUserId;
echo "Creator match: " . ($creatorMatch ? 'yes' : 'no') . PHP_EOL;

$incoming = ['status' => 'ongoing', 'progress' => 10];
try {
    rdomms_guard_project_operational_update($project, $incoming);
    echo "Operational guard: PASS" . PHP_EOL;
} catch (Throwable $e) {
    echo "Operational guard: " . $e->getMessage() . PHP_EOL;
}

// Test head user 86 trying to update
$stmt->execute([86]);
$head = $stmt->fetch(PDO::FETCH_ASSOC);
$scopeHead = getDataScopeUserId($head);
echo "\nHead user 86 scope: {$scopeHead}" . PHP_EOL;
echo "Head org: {$head['organization']} vs project org: {$project['organization']}" . PHP_EOL;
require_once __DIR__ . '/../config/db.php';
echo "Org match: " . (organizationsMatch($head['organization'] ?? '', $project['organization'] ?? '') ? 'yes' : 'no') . PHP_EOL;
