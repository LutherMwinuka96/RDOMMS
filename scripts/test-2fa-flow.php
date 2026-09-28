<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/two-factor.php';

$db = new Database();
$conn = $db->getConnection();
ensureTwoFactorSchema($conn);

echo "=== Pending login tokens ===\n";
$rows = $conn->query('SELECT pending_token, user_id, expires_at, created_at, expires_at > NOW() AS still_valid FROM two_factor_login_pending')->fetchAll();
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Simulate verify for user 78 ===\n";
$stmt = $conn->prepare('SELECT * FROM users WHERE id = 78');
$stmt->execute();
$user = $stmt->fetch();
if (!$user) {
    echo "User 78 not found\n";
    exit(1);
}

$user = rdomms_two_factor_normalize_user_secret($conn, $user);
$secret = rdomms_two_factor_get_secret($user);
echo "Secret: $secret\n";
$code = rdomms_totp_code($secret);
echo "Current code: $code\n";
echo "Verify: " . (rdomms_totp_verify($secret, $code) ? 'OK' : 'FAIL') . "\n";

$token = rdomms_two_factor_set_pending($conn, $user);
echo "New pending token: $token\n";

$pendingUser = rdomms_two_factor_get_pending_user($conn, $token);
echo "Pending lookup: " . ($pendingUser ? 'OK id=' . $pendingUser['id'] : 'FAIL') . "\n";

if ($pendingUser) {
    $pendingUser = rdomms_two_factor_normalize_user_secret($conn, $pendingUser);
    echo "Verify via pending user: " . (rdomms_two_factor_verify_code($pendingUser, $code) ? 'OK' : 'FAIL') . "\n";
}

// Check confirm flow user id resolution
echo "\n=== Setup pending test ===\n";
$testSecret = rdomms_totp_generate_secret();
rdomms_two_factor_save_setup_pending($conn, 78, $testSecret);
$retrieved = rdomms_two_factor_get_setup_secret($conn, 78);
echo "Setup save/retrieve match: " . ($retrieved === rdomms_two_factor_store_secret($testSecret) ? 'OK' : 'FAIL') . "\n";
$setupCode = rdomms_totp_code($retrieved);
echo "Setup code verify: " . (rdomms_totp_verify($retrieved, $setupCode) ? 'OK' : 'FAIL') . "\n";
rdomms_two_factor_clear_setup_pending($conn, 78);
