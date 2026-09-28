<?php
require_once __DIR__ . '/../config/db.php';
$c = (new Database())->getConnection();
$stmt = $c->query("SHOW COLUMNS FROM monthly_plans LIKE 'approval_status'");
echo json_encode($stmt->fetch(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT) . PHP_EOL;
$stmt = $c->query("SELECT id, plan_id, approval_status, HEX(approval_status) AS hex_status FROM monthly_plans LIMIT 3");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row) . PHP_EOL;
}
