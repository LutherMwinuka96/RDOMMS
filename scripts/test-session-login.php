<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session-manager.php';

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    echo "DB connection FAILED\n";
    exit(1);
}

echo "DB OK\n";

try {
    ensureUserSessionsSchema($conn);
    echo "user_sessions schema OK\n";
} catch (Throwable $e) {
    echo "Schema error: " . $e->getMessage() . "\n";
}

$phpNow = date('Y-m-d H:i:s');
$mysqlNow = $conn->query('SELECT NOW() AS n')->fetch()['n'] ?? 'unknown';
echo "PHP now:   {$phpNow}\n";
echo "MySQL now: {$mysqlNow}\n";

$expiresAt = rdomms_session_expires_at($conn);
echo "Session expires_at would be: {$expiresAt}\n";

$stmt = $conn->prepare('SELECT ? > NOW() AS valid');
$stmt->execute([$expiresAt]);
$row = $stmt->fetch();
echo 'expires_at > NOW(): ' . (($row['valid'] ?? 0) ? 'YES' : 'NO') . "\n";

$count = (int) $conn->query('SELECT COUNT(*) AS c FROM user_sessions')->fetch()['c'];
echo "Active session rows: {$count}\n";
