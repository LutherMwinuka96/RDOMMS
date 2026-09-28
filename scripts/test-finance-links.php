<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/finance-links.php';

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

ensureFinanceLinkSchema($conn);

foreach (['budget_allocations', 'expenses'] as $table) {
    $stmt = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'linked_type'");
    echo $table . ': linked_type ' . ($stmt->fetch() ? 'OK' : 'MISSING') . PHP_EOL;
}

$sources = rdomms_fetch_funding_sources($conn, [], []);
echo 'projects: ' . count($sources['projects']) . PHP_EOL;
echo 'monthly_plans: ' . count($sources['monthly_plans']) . PHP_EOL;
echo 'quarterly_plans: ' . count($sources['quarterly_plans']) . PHP_EOL;
echo 'annual_plans: ' . count($sources['annual_plans']) . PHP_EOL;
