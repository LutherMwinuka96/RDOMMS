<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/two-factor.php';

$db = new Database();
$conn = $db->getConnection();

foreach ([68, 78] as $id) {
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) continue;

    $user = rdomms_two_factor_normalize_user_secret($conn, $user);
    $secret = rdomms_two_factor_get_secret($user);
    echo "User $id ({$user['email']})\n";
    echo "  raw secret: {$user['two_factor_secret']}\n";
    echo "  parsed len: " . strlen((string)$secret) . " value: $secret\n";
    if ($secret) {
        echo "  current code: " . rdomms_totp_code($secret) . "\n";
    }
    echo "\n";
}
