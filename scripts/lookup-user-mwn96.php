<?php
require_once __DIR__ . '/../config/db.php';

$c = (new Database())->getConnection();
$stmt = $c->prepare("SELECT id, user_id, fullname, email, role, department, organization, status FROM users WHERE LOWER(email) = ? LIMIT 1");
$stmt->execute(['mwn96@gmail.com']);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode($user, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
if ($user) {
    echo 'beneficiaries: ' . $c->query('SELECT COUNT(*) FROM beneficiaries WHERE registered_by = ' . (int)$user['id'])->fetchColumn() . PHP_EOL;
    echo 'monthly_plans: ' . $c->query('SELECT COUNT(*) FROM monthly_plans WHERE created_by = ' . (int)$user['id'])->fetchColumn() . PHP_EOL;
    echo 'quarterly_plans: ' . $c->query('SELECT COUNT(*) FROM quarterly_plans WHERE created_by = ' . (int)$user['id'])->fetchColumn() . PHP_EOL;
    echo 'annual_plans: ' . $c->query('SELECT COUNT(*) FROM annual_plans WHERE created_by = ' . (int)$user['id'])->fetchColumn() . PHP_EOL;
}
