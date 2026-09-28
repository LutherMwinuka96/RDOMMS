<?php
declare(strict_types=1);

/**
 * CLI test for TOTP + DB pending tables.
 * Usage: php scripts/test-2fa.php
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/two-factor.php';

echo "=== RDOMMS 2FA Test ===\n\n";

// RFC 6238 test vector (Appendix B)
$secret = 'JBSWY3DPEHPK3PXP';
$vectors = [
    59 => '287082',
    1111111109 => '081804',
    1111111111 => '050471',
    1234567890 => '890059',
    2000000000 => '692790',
];

echo "TOTP test vectors:\n";
$pass = 0;
$fail = 0;
foreach ($vectors as $time => $expected) {
    $slice = (int) floor($time / 30);
    $actual = rdomms_totp_code($secret, $slice);
    $ok = hash_equals($expected, $actual);
    echo sprintf("  t=%d expected=%s actual=%s %s\n", $time, $expected, $actual, $ok ? 'OK' : 'FAIL');
    $ok ? $pass++ : $fail++;
}

// Verify round-trip
$current = rdomms_totp_code($secret);
$verifyOk = rdomms_totp_verify($secret, $current);
echo sprintf("\nLive verify (current code): %s\n", $verifyOk ? 'OK' : 'FAIL');

echo sprintf("\nVectors: %d pass, %d fail\n", $pass, $fail);

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    echo "\nDB: CONNECTION FAILED\n";
    exit(1);
}

ensureTwoFactorSchema($conn);
echo "\nDB: connected, schema ensured\n";

// Check users with 2FA
$stmt = $conn->query(
    'SELECT id, email, two_factor_enabled,
            LENGTH(two_factor_secret) AS secret_len,
            LEFT(two_factor_secret, 8) AS secret_prefix
     FROM users WHERE two_factor_enabled = 1 LIMIT 5'
);
$rows = $stmt->fetchAll();
echo "\nUsers with 2FA enabled: " . count($rows) . "\n";
foreach ($rows as $row) {
    echo sprintf(
        "  id=%d email=%s secret_len=%s prefix=%s\n",
        $row['id'],
        $row['email'],
        $row['secret_len'] ?? 'null',
        $row['secret_prefix'] ?? ''
    );

    $userStmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
    $userStmt->execute([$row['id']]);
    $user = $userStmt->fetch();
    if ($user) {
        $plain = rdomms_two_factor_get_secret($user);
        if ($plain) {
            $code = rdomms_totp_code($plain);
            $v = rdomms_totp_verify($plain, $code);
            echo sprintf("    secret readable=%s live_verify=%s code=%s\n", $plain !== '' ? 'yes' : 'no', $v ? 'OK' : 'FAIL', $code);
        } else {
            echo "    secret readable=NO (corrupt or encrypted)\n";
        }
    }
}

// Pending tables
foreach (['two_factor_login_pending', 'two_factor_setup_pending'] as $table) {
    try {
        $c = $conn->query("SELECT COUNT(*) AS c FROM {$table}")->fetch();
        echo "\n{$table} rows: " . ($c['c'] ?? 0) . "\n";
    } catch (Throwable $e) {
        echo "\n{$table}: ERROR " . $e->getMessage() . "\n";
    }
}

// Timezone check
$phpTz = date_default_timezone_get();
$dbNow = $conn->query('SELECT NOW() AS n, @@session.time_zone AS tz')->fetch();
echo sprintf("\nPHP timezone: %s | MySQL NOW=%s tz=%s\n", $phpTz, $dbNow['n'] ?? '?', $dbNow['tz'] ?? '?');

exit($fail > 0 ? 1 : 0);
