<?php
declare(strict_types=1);

require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/session-manager.php';

function ensureTwoFactorSchema(PDO $conn): void
{
    $columns = [
        'two_factor_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'two_factor_secret' => 'VARCHAR(255) NULL',
        'two_factor_backup_codes' => 'TEXT NULL',
    ];

    foreach ($columns as $name => $definition) {
        try {
            $stmt = $conn->prepare('SHOW COLUMNS FROM users LIKE ?');
            $stmt->execute([$name]);
            if (!$stmt->fetch()) {
                $conn->exec("ALTER TABLE users ADD COLUMN {$name} {$definition}");
            }
        } catch (Throwable $e) {
            error_log('ensureTwoFactorSchema ' . $name . ': ' . $e->getMessage());
        }
    }

    $tables = [
        'two_factor_login_pending' => '
            CREATE TABLE IF NOT EXISTS two_factor_login_pending (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pending_token VARCHAR(64) NOT NULL,
                user_id INT NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_pending_token (pending_token),
                INDEX idx_user_id (user_id),
                INDEX idx_expires_at (expires_at)
            )',
        'two_factor_setup_pending' => '
            CREATE TABLE IF NOT EXISTS two_factor_setup_pending (
                user_id INT NOT NULL PRIMARY KEY,
                secret VARCHAR(64) NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_expires_at (expires_at)
            )',
    ];

    foreach ($tables as $sql) {
        try {
            $conn->exec($sql);
        } catch (Throwable $e) {
            error_log('ensureTwoFactorSchema table: ' . $e->getMessage());
        }
    }
}

function rdomms_totp_generate_secret(int $length = 16): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[random_int(0, 31)];
    }

    return $secret;
}

function rdomms_base32_decode(string $value): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value));
    $buffer = 0;
    $bitsLeft = 0;
    $output = '';

    for ($i = 0, $len = strlen($value); $i < $len; $i++) {
        $pos = strpos($alphabet, $value[$i]);
        if ($pos === false) {
            continue;
        }

        $buffer = ($buffer << 5) | $pos;
        $bitsLeft += 5;

        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $output .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }

    return $output;
}

function rdomms_totp_code(string $secret, ?int $timeSlice = null): string
{
    $timeSlice = $timeSlice ?? (int) floor(time() / 30);
    $key = rdomms_base32_decode($secret);
    if ($key === '') {
        return '000000';
    }

    $time = pack('N*', 0) . pack('N*', $timeSlice);
    $hash = hash_hmac('sha1', $time, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $truncated = (
        ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF)
    );

    return str_pad((string) ($truncated % 1000000), 6, '0', STR_PAD_LEFT);
}

function rdomms_resolve_numeric_user_id(PDO $conn, $sessionUserId): int
{
    if ($sessionUserId === null || $sessionUserId === '') {
        return 0;
    }

    if (is_numeric($sessionUserId)) {
        $numeric = (int) $sessionUserId;
        if ($numeric > 0) {
            return $numeric;
        }
    }

    $stmt = $conn->prepare('SELECT id FROM users WHERE id = ? OR user_id = ? LIMIT 1');
    $stmt->execute([$sessionUserId, $sessionUserId]);
    $row = $stmt->fetch();

    return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
}

function rdomms_two_factor_pending_not_expired(?string $expiresAt): bool
{
    if ($expiresAt === null || trim($expiresAt) === '') {
        return false;
    }

    $expiresUnix = strtotime($expiresAt);
    if ($expiresUnix === false) {
        return false;
    }

    return $expiresUnix > time();
}

function rdomms_totp_verify(string $secret, string $code, int $window = 5): bool
{
    $code = rdomms_two_factor_normalize_totp_code($code);
    if ($code === null) {
        return false;
    }

    $normalizedSecret = rdomms_two_factor_store_secret($secret);
    $timeSlice = (int) floor(time() / 30);
    for ($offset = -$window; $offset <= $window; $offset++) {
        if (hash_equals(rdomms_totp_code($normalizedSecret, $timeSlice + $offset), $code)) {
            return true;
        }
    }

    return false;
}

function rdomms_two_factor_normalize_totp_code(string $code): ?string
{
    $digits = preg_replace('/\D+/', '', trim($code));
    if ($digits === null || strlen($digits) !== 6) {
        return null;
    }

    return $digits;
}

function rdomms_two_factor_normalize_backup_code(string $code): ?string
{
    $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($code)));
    if ($normalized === '' || strlen($normalized) !== 8) {
        return null;
    }

    return $normalized;
}

function rdomms_totp_provisioning_uri(string $email, string $secret): string
{
    $issuer = rawurlencode('RDOMMS');
    $label = rawurlencode('RDOMMS:' . $email);
    $normalizedSecret = rdomms_two_factor_store_secret($secret);

    return 'otpauth://totp/' . $label
        . '?secret=' . rawurlencode($normalizedSecret)
        . '&issuer=' . $issuer
        . '&digits=6&period=30&algorithm=SHA1';
}

function rdomms_two_factor_get_secret(array $user): ?string
{
    $stored = trim((string) ($user['two_factor_secret'] ?? ''));
    if ($stored === '') {
        return null;
    }

    if (str_starts_with($stored, 'enc1:')) {
        $decrypted = rdomms_decrypt_sensitive($stored);
        if ($decrypted === null || $decrypted === '' || $decrypted === $stored) {
            return null;
        }
        $stored = $decrypted;
    }

    $normalized = rdomms_two_factor_store_secret($stored);
    return $normalized !== '' ? $normalized : null;
}

function rdomms_two_factor_store_secret(string $secret): string
{
    return strtoupper(preg_replace('/[^A-Z2-7]/', '', trim($secret)));
}

function rdomms_two_factor_generate_backup_codes(int $count = 8): array
{
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(4)));
    }

    return $codes;
}

function rdomms_two_factor_hash_backup_codes(array $codes): string
{
    $hashed = array_map(static fn ($code) => password_hash(strtoupper(trim($code)), PASSWORD_DEFAULT), $codes);
    return json_encode($hashed, JSON_UNESCAPED_UNICODE);
}

function rdomms_two_factor_verify_backup_code(array $user, string $code): bool
{
    $normalized = rdomms_two_factor_normalize_backup_code($code);
    if ($normalized === null) {
        return false;
    }

    $raw = (string) ($user['two_factor_backup_codes'] ?? '');
    if ($raw === '') {
        return false;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return false;
    }

    foreach ($decoded as $hash) {
        if (is_string($hash) && password_verify($normalized, $hash)) {
            return true;
        }
    }

    return false;
}

function rdomms_two_factor_normalize_user_secret(PDO $conn, array $user): array
{
    $stored = trim((string) ($user['two_factor_secret'] ?? ''));
    if ($stored === '') {
        return $user;
    }

    $plain = rdomms_two_factor_get_secret($user);
    if ($plain === null || $plain === '') {
        return $user;
    }

    $normalized = rdomms_two_factor_store_secret($plain);
    if ($normalized === $stored || str_starts_with($stored, 'enc1:')) {
        try {
            $stmt = $conn->prepare('UPDATE users SET two_factor_secret = ? WHERE id = ?');
            $stmt->execute([$normalized, $user['id']]);
            $user['two_factor_secret'] = $normalized;
        } catch (Throwable $e) {
            error_log('rdomms_two_factor_normalize_user_secret: ' . $e->getMessage());
        }
    }

    return $user;
}

function rdomms_two_factor_user_enabled(array $user): bool
{
    if ((int) ($user['two_factor_enabled'] ?? 0) !== 1) {
        return false;
    }

    return rdomms_two_factor_get_secret($user) !== null;
}

function rdomms_two_factor_save_setup_pending(PDO $conn, int $userId, string $secret): void
{
    ensureTwoFactorSchema($conn);
    $normalized = rdomms_two_factor_store_secret($secret);

    $cleanup = $conn->prepare('DELETE FROM two_factor_setup_pending WHERE expires_at <= NOW() OR user_id = ?');
    $cleanup->execute([$userId]);

    $stmt = $conn->prepare(
        'INSERT INTO two_factor_setup_pending (user_id, secret, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))
         ON DUPLICATE KEY UPDATE secret = VALUES(secret), expires_at = VALUES(expires_at)'
    );
    $stmt->execute([$userId, $normalized]);
}

function rdomms_two_factor_get_setup_secret(PDO $conn, int $userId): ?string
{
    ensureTwoFactorSchema($conn);

    $stmt = $conn->prepare(
        'SELECT secret, expires_at FROM two_factor_setup_pending WHERE user_id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || !rdomms_two_factor_pending_not_expired((string) ($row['expires_at'] ?? ''))) {
        return null;
    }

    $secret = rdomms_two_factor_store_secret((string) ($row['secret'] ?? ''));
    return $secret !== '' ? $secret : null;
}

function rdomms_two_factor_clear_setup_pending(PDO $conn, int $userId): void
{
    try {
        ensureTwoFactorSchema($conn);
        $stmt = $conn->prepare('DELETE FROM two_factor_setup_pending WHERE user_id = ?');
        $stmt->execute([$userId]);
    } catch (Throwable $e) {
        error_log('rdomms_two_factor_clear_setup_pending: ' . $e->getMessage());
    }
}

function rdomms_two_factor_set_pending(PDO $conn, array $user): string
{
    ensureTwoFactorSchema($conn);

    $token = bin2hex(random_bytes(16));
    $userId = (int) ($user['id'] ?? 0);

    $cleanup = $conn->prepare('DELETE FROM two_factor_login_pending WHERE expires_at <= NOW() OR user_id = ?');
    $cleanup->execute([$userId]);

    $stmt = $conn->prepare(
        'INSERT INTO two_factor_login_pending (pending_token, user_id, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
    );
    $stmt->execute([$token, $userId]);

    return $token;
}

function rdomms_two_factor_get_pending_user(PDO $conn, string $pendingToken): ?array
{
    $token = trim($pendingToken);
    if ($token === '') {
        return null;
    }

    ensureTwoFactorSchema($conn);

    $stmt = $conn->prepare(
        'SELECT user_id, expires_at FROM two_factor_login_pending WHERE pending_token = ? LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row || !rdomms_two_factor_pending_not_expired((string) ($row['expires_at'] ?? ''))) {
        return null;
    }

    $userId = (int) ($row['user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    $userStmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();

    return is_array($user) ? $user : null;
}

function rdomms_two_factor_clear_pending(?PDO $conn = null, ?string $pendingToken = null): void
{
    if ($conn === null || $pendingToken === null || trim($pendingToken) === '') {
        return;
    }

    try {
        ensureTwoFactorSchema($conn);
        $stmt = $conn->prepare('DELETE FROM two_factor_login_pending WHERE pending_token = ?');
        $stmt->execute([trim($pendingToken)]);
    } catch (Throwable $e) {
        error_log('rdomms_two_factor_clear_pending: ' . $e->getMessage());
    }
}

function rdomms_two_factor_verify_code(array $user, string $code): bool
{
    $secret = rdomms_two_factor_get_secret($user);
    if ($secret !== null && rdomms_totp_verify($secret, $code)) {
        return true;
    }

    return rdomms_two_factor_verify_backup_code($user, $code);
}

function rdomms_two_factor_is_backup_code(string $code): bool
{
    return rdomms_two_factor_normalize_backup_code($code) !== null;
}

function rdomms_two_factor_consume_backup_code(PDO $conn, array $user, string $code): bool
{
    $normalized = rdomms_two_factor_normalize_backup_code($code);
    if ($normalized === null) {
        return false;
    }

    $raw = (string) ($user['two_factor_backup_codes'] ?? '');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return false;
    }

    $remaining = [];
    $matched = false;

    foreach ($decoded as $hash) {
        if (!$matched && is_string($hash) && password_verify($normalized, $hash)) {
            $matched = true;
            continue;
        }
        $remaining[] = $hash;
    }

    if (!$matched) {
        return false;
    }

    $stmt = $conn->prepare('UPDATE users SET two_factor_backup_codes = ? WHERE id = ?');
    $stmt->execute([json_encode($remaining, JSON_UNESCAPED_UNICODE), $user['id']]);

    return true;
}

function rdomms_finalize_login(PDO $conn, array $user): array
{
    ensureSessionStarted();
    session_regenerate_id(true);

    $sessionToken = bin2hex(random_bytes(32));
    $expiresAt = rdomms_session_expires_at($conn);
    ensureUserSessionsSchema($conn);

    $sessionStored = false;
    try {
        $stmt = $conn->prepare(
            'INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at, last_active_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            $user['id'],
            $sessionToken,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $expiresAt,
        ]);
        $sessionStored = true;
    } catch (Throwable $e) {
        error_log('user_sessions insert with last_active_at failed: ' . $e->getMessage());
        try {
            $stmt = $conn->prepare(
                'INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $user['id'],
                $sessionToken,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $expiresAt,
            ]);
            $sessionStored = true;
        } catch (Throwable $inner) {
            error_log('user_sessions insert fallback failed: ' . $inner->getMessage());
        }
    }

    if (!$sessionStored) {
        error_log('login: continuing with PHP session only for user id ' . (string) ($user['id'] ?? ''));
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['session_token'] = $sessionToken;
    $_SESSION['user_name'] = $user['fullname'];
    $_SESSION['user_role'] = normalizeRoleName($user['role'] ?? 'user');

    logActivity($user['id'], 'User logged in', 'Auth', 'success', 'User ' . $user['fullname'] . ' logged in successfully');

    $user = rdomms_hydrate_user_sensitive_fields($user);

    return [
        'user' => [
            'id' => $user['user_id'],
            'db_id' => (int) $user['id'],
            'fullname' => $user['fullname'],
            'email' => $user['email'],
            'role' => normalizeRoleName($user['role'] ?? 'user'),
            'department' => $user['department'],
            'organization' => $user['organization'],
            'profile_picture' => $user['profile_picture'] ?? null,
            'two_factor_enabled' => rdomms_two_factor_user_enabled($user),
        ],
        'session_token' => $sessionToken,
        'session' => rdomms_build_session_client_meta([
            'expires_at' => $expiresAt,
            'last_active_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]),
    ];
}
