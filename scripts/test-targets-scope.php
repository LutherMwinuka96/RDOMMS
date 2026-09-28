<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/planning-access.php';

$c = (new Database())->getConnection();
$user = [
    'id' => 1,
    'role' => 'head_of_department',
    'organization' => 'rdo_mafinga',
    'department' => 'administration',
];

$sql = 'SELECT * FROM targets WHERE 1=1';
$params = [];
appendPlanningListScopeFilter($sql, $params, $user, '', [
    'has_department' => false,
    'has_station' => false,
]);
$sql .= ' LIMIT 5';

$stmt = $c->prepare($sql);
$stmt->execute($params);
echo "OK rows: " . count($stmt->fetchAll()) . PHP_EOL;
