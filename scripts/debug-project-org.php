<?php
require_once __DIR__ . '/../config/db.php';
$c = (new Database())->getConnection();
foreach ($c->query('SELECT project_id, name, organization, sector, created_by FROM projects ORDER BY id DESC LIMIT 8') as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
