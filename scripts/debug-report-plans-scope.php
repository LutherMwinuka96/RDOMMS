<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/planning-access.php';

$c = (new Database())->getConnection();
$stmt = $c->prepare('SELECT * FROM users WHERE id = 85');
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

foreach (['monthly_plans', 'quarterly_plans', 'annual_plans'] as $table) {
    $sql = "SELECT COUNT(*) AS total FROM {$table} WHERE 1=1";
    $params = [];
    appendPlanningListScopeFilter($sql, $params, $user);
    $stmt = $c->prepare($sql);
    $stmt->execute($params);
    echo "{$table}: " . ($stmt->fetch()['total'] ?? 0) . PHP_EOL;
}

$sql = "SELECT COUNT(*) AS total FROM monthly_plans WHERE created_by = ?";
$stmt = $c->prepare($sql);
$stmt->execute([(int) $user['id']]);
echo 'monthly_plans own only: ' . ($stmt->fetch()['total'] ?? 0) . PHP_EOL;
