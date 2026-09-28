<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/project-budget.php';
require_once __DIR__ . '/../config/project-deregistration.php';

$c = (new Database())->getConnection();
ensureProjectBudgetSchema($c);
ensureProjectDeregistrationSchema($c);

$projectId = 'PRJ1783512934998';
$project = rdomms_fetch_project_row($c, $projectId);
if (!$project) {
    fwrite(STDERR, "Project not found\n");
    exit(1);
}

$stmt = $c->prepare('SELECT id, fullname, email, role, organization FROM users WHERE id = ?');

foreach ([68, 86] as $userId) {
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $scopeUserId = getDataScopeUserId($user);

    echo "=== User {$userId} ({$user['email']}) ===\n";
    echo 'Creator match: ' . (((int) ($project['created_by'] ?? 0) === (int) $scopeUserId) ? 'yes' : 'no') . "\n";

    ob_start();
    try {
        denyUnlessCanUpdateProject($c, $user, $projectId, $scopeUserId);
        echo "Update permission: ALLOWED\n";
    } catch (Throwable $e) {
        echo 'Update permission: BLOCKED - ' . $e->getMessage() . "\n";
    }
    $output = ob_get_clean();
    if ($output !== '') {
        echo "Update permission response: {$output}\n";
    }

    $incoming = ['status' => 'ongoing', 'progress' => 10];
    try {
        rdomms_guard_project_operational_update($project, $incoming);
        echo "Operational guard: PASS\n";
    } catch (Throwable $e) {
        echo 'Operational guard: FAIL - ' . $e->getMessage() . "\n";
    }
    echo "\n";
}

// Simulate creator update in DB (rollback after)
$c->beginTransaction();
try {
    $update = $c->prepare('UPDATE projects SET status = ?, progress = ? WHERE project_id = ?');
    $update->execute(['ongoing', 10, $projectId]);
    $updated = rdomms_fetch_project_row($c, $projectId);
    echo "Simulated update OK: status={$updated['status']}, progress={$updated['progress']}\n";
} finally {
    $c->rollBack();
    echo "Rolled back test update.\n";
}
