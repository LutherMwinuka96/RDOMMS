<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

function rdomms_session_idle_minutes(): int
{
    $minutes = rdomms_env_int('SESSION_IDLE_MINUTES', 15);
    return max(1, min($minutes, 1440));
}

function rdomms_session_warn_seconds(): int
{
    $seconds = rdomms_env_int('SESSION_WARN_SECONDS', 60);
    return max(15, min($seconds, rdomms_session_idle_minutes() * 60 - 5));
}

function rdomms_session_lifetime_seconds(): int
{
    return rdomms_session_idle_minutes() * 60;
}

function rdomms_session_offline_auth_enabled(): bool
{
    return rdomms_env_bool('SESSION_OFFLINE_AUTH', false);
}

function ensureUserSessionsSchema(PDO $conn): void
{
    try {
        $conn->exec(
            'CREATE TABLE IF NOT EXISTS user_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                session_token VARCHAR(64) NOT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                expires_at TIMESTAMP NULL,
                last_active_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_session_token (session_token),
                INDEX idx_expires_at (expires_at),
                INDEX idx_user_id (user_id)
            )'
        );
    } catch (Throwable $e) {
        error_log('ensureUserSessionsSchema create table: ' . $e->getMessage());
    }

    try {
        $stmt = $conn->prepare('SHOW COLUMNS FROM user_sessions LIKE ?');
        $stmt->execute(['last_active_at']);
        if (!$stmt->fetch()) {
            $conn->exec(
                'ALTER TABLE user_sessions
                 ADD COLUMN last_active_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
                 AFTER expires_at'
            );
        }
    } catch (Throwable $e) {
        error_log('ensureUserSessionsSchema: ' . $e->getMessage());
    }
}

function rdomms_session_expires_at(?PDO $conn = null, ?int $fromTimestamp = null): string
{
    $seconds = rdomms_session_lifetime_seconds();

    if ($conn instanceof PDO) {
        try {
            if ($fromTimestamp !== null) {
                $stmt = $conn->prepare('SELECT DATE_ADD(FROM_UNIXTIME(?), INTERVAL ? SECOND) AS expires_at');
                $stmt->execute([$fromTimestamp, $seconds]);
            } else {
                $stmt = $conn->prepare('SELECT DATE_ADD(NOW(), INTERVAL ? SECOND) AS expires_at');
                $stmt->execute([$seconds]);
            }
            $row = $stmt->fetch();
            if (is_array($row) && !empty($row['expires_at'])) {
                return (string) $row['expires_at'];
            }
        } catch (Throwable $e) {
            error_log('rdomms_session_expires_at db fallback: ' . $e->getMessage());
        }
    }

    $base = $fromTimestamp ?? time();
    return date('Y-m-d H:i:s', $base + $seconds);
}

function rdomms_touch_session(PDO $conn, string $sessionToken): ?array
{
    $token = trim($sessionToken);
    if ($token === '') {
        return null;
    }

    ensureUserSessionsSchema($conn);

    $expiresAt = rdomms_session_expires_at($conn);
    $updated = false;

    try {
        $stmt = $conn->prepare(
            'UPDATE user_sessions
             SET expires_at = ?, last_active_at = CURRENT_TIMESTAMP
             WHERE session_token = ? AND expires_at > NOW()'
        );
        $stmt->execute([$expiresAt, $token]);
        $updated = $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('rdomms_touch_session with last_active_at failed: ' . $e->getMessage());
        $stmt = $conn->prepare(
            'UPDATE user_sessions
             SET expires_at = ?
             WHERE session_token = ? AND expires_at > NOW()'
        );
        $stmt->execute([$expiresAt, $token]);
        $updated = $stmt->rowCount() > 0;
    }

    if (!$updated) {
        return null;
    }

    $fetch = $conn->prepare('SELECT * FROM user_sessions WHERE session_token = ? LIMIT 1');
    $fetch->execute([$token]);
    $row = $fetch->fetch();

    return is_array($row) ? $row : null;
}

function rdomms_destroy_session_token(PDO $conn, string $sessionToken): void
{
    $token = trim($sessionToken);
    if ($token === '') {
        return;
    }

    $stmt = $conn->prepare('DELETE FROM user_sessions WHERE session_token = ?');
    $stmt->execute([$token]);
}

function rdomms_destroy_php_session(): void
{
    ensureSessionStarted();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function rdomms_session_seconds_remaining(?array $sessionRow): int
{
    if (!$sessionRow || empty($sessionRow['expires_at'])) {
        return 0;
    }

    return max(0, (int) strtotime((string) $sessionRow['expires_at']) - time());
}

function rdomms_build_session_client_meta(?array $sessionRow): array
{
    $idleMinutes = rdomms_session_idle_minutes();
    $warnSeconds = rdomms_session_warn_seconds();
    $secondsRemaining = rdomms_session_seconds_remaining($sessionRow);
    $expiresAt = $sessionRow['expires_at'] ?? null;
    $expiresAtIso = null;

    if ($expiresAt) {
        try {
            $expiresAtIso = (new DateTimeImmutable((string) $expiresAt))->format(DateTimeInterface::ATOM);
        } catch (Throwable $e) {
            $expiresAtIso = null;
        }
    }

    $expiresAtUnix = null;
    if ($expiresAt) {
        $expiresUnix = strtotime((string) $expiresAt);
        if ($expiresUnix !== false) {
            $expiresAtUnix = $expiresUnix;
        }
    }

    return [
        'idle_minutes' => $idleMinutes,
        'idle_seconds' => rdomms_session_lifetime_seconds(),
        'warn_seconds' => $warnSeconds,
        'expires_at' => $expiresAt,
        'expires_at_iso' => $expiresAtIso,
        'expires_at_unix' => $expiresAtUnix,
        'server_time_unix' => time(),
        'last_active_at' => $sessionRow['last_active_at'] ?? ($sessionRow['created_at'] ?? null),
        'seconds_remaining' => $secondsRemaining,
        'is_expiring_soon' => $secondsRemaining > 0 && $secondsRemaining <= $warnSeconds,
    ];
}

function rdomms_fetch_active_session(PDO $conn, string $sessionToken, bool $touch = true): ?array
{
    $token = trim($sessionToken);
    if ($token === '') {
        return null;
    }

    ensureUserSessionsSchema($conn);

    $stmt = $conn->prepare(
        'SELECT * FROM user_sessions WHERE session_token = ? AND expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([$token]);
    $session = $stmt->fetch();

    if (!$session) {
        rdomms_destroy_session_token($conn, $token);
        return null;
    }

    if ($touch) {
        $touched = rdomms_touch_session($conn, $token);
        return $touched ?: $session;
    }

    return $session;
}

function rdomms_invalidate_session_fully(PDO $conn, ?string $sessionToken = null): void
{
    if ($sessionToken) {
        rdomms_destroy_session_token($conn, $sessionToken);
    }

    rdomms_destroy_php_session();
}
