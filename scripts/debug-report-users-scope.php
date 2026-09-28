<?php
require_once __DIR__ . '/../config/db.php';

$c = (new Database())->getConnection();
$stmt = $c->prepare('SELECT * FROM users WHERE id = 86');
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$sql = 'SELECT id, email, role, organization, department FROM users WHERE 1=1';
$params = [];
appendUserListScopeFilter($sql, $params, $user);
$sql .= ' ORDER BY id';
$stmt = $c->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo 'Users visible to head 86 via appendUserListScopeFilter: ' . count($rows) . PHP_EOL;
foreach ($rows as $row) {
    echo json_encode($row) . PHP_EOL;
}
