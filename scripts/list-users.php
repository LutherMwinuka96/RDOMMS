<?php
require_once __DIR__ . '/../config/db.php';
$c = (new Database())->getConnection();
$stmt = $c->query("SELECT id, user_id, email, fullname, role, department, organization, status FROM users ORDER BY id DESC LIMIT 30");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
