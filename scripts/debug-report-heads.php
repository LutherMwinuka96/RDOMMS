<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/report-helpers.php';
require_once __DIR__ . '/../config/report-access.php';
require_once __DIR__ . '/../config/departments.php';

$c = (new Database())->getConnection();

$stmt = $c->query("SELECT id, email, role, organization, department FROM users WHERE role IN ('head_of_organization_center','head_of_department') ORDER BY id");
$heads = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($heads as $user) {
    $ctx = reportFetchContext($c, $user);
    $dept = normalizeRdommsDepartment((string) ($user['department'] ?? '')) ?? ($user['department'] ?? '');
    $report = reportBuildData('projects', '2020-01-01', '2030-12-31', '', (string) $dept, $ctx);

    echo "User {$user['id']} {$user['email']} role={$user['role']}\n";
    echo "  org={$user['organization']} dept={$user['department']} normalized={$dept}\n";
    echo "  portfolio projects=" . count($ctx['projects']) . " users=" . count($ctx['users']) . " plans=" . (count($ctx['monthlyPlans']) + count($ctx['quarterlyPlans']) + count($ctx['annualPlans'])) . "\n";
    echo "  dept-filtered projects report count=" . ($report['recordCount'] ?? 0) . "\n";
    echo "  can access page: " . (roleCanAccessGenerateReportsPage($user) ? 'yes' : 'no') . "\n\n";
}
