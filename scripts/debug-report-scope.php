<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/report-helpers.php';
require_once __DIR__ . '/../config/report-access.php';

$c = (new Database())->getConnection();
foreach ([86, 68] as $userId) {
    $stmt = $c->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        continue;
    }

    $ctx = reportFetchContext($c, $user);
    echo "=== User {$userId} ({$user['email']}) role={$user['role']} org={$user['organization']} dept=" . ($user['department'] ?? '') . " ===\n";
    echo 'Projects: ' . count($ctx['projects']) . "\n";
    echo 'Beneficiaries: ' . count($ctx['beneficiaries']) . "\n";
    echo 'Users: ' . count($ctx['users']) . "\n";
    echo 'Monthly plans: ' . count($ctx['monthlyPlans']) . "\n";
    echo 'Allowed types: ' . implode(',', getAllowedReportTypesForRole($user['role'])) . "\n\n";
}

// Test department filter on projects for head
$stmt = $c->prepare('SELECT * FROM users WHERE id = 86');
$stmt->execute();
$head = $stmt->fetch(PDO::FETCH_ASSOC);
$ctx = reportFetchContext($c, $head);
require_once __DIR__ . '/../config/departments.php';

$departments = [];
foreach ($ctx['projects'] as $p) {
    $departments[$p['sector'] ?? $p['department'] ?? 'none'] = ($departments[$departments[$p['sector'] ?? $p['department'] ?? 'none'] ?? 0] ?? 0) + 1;
}
echo "Project sectors in head portfolio:\n";
print_r($departments);

if (!empty($ctx['projects'])) {
    $sample = $ctx['projects'][0];
    $report = reportBuildData('projects', '2020-01-01', '2030-12-31', '', 'Vocational Training Center', $ctx);
    echo 'Filtered by Vocational Training Center: recordCount=' . ($report['recordCount'] ?? 0) . "\n";
    $report2 = reportBuildData('projects', '2020-01-01', '2030-12-31', '', '', $ctx);
    echo 'No department filter: recordCount=' . ($report2['recordCount'] ?? 0) . "\n";
}
