<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

function rdomms_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }

    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function rdomms_is_production_env(): bool
{
    $env = strtolower(trim((string) rdomms_env('APP_ENV', 'local')));
    return in_array($env, ['production', 'prod', 'live', 'staging'], true);
}

function rdomms_should_force_https(): bool
{
    if (rdomms_env_bool('FORCE_HTTPS', false)) {
        return true;
    }

    if (rdomms_is_production_env()) {
        return rdomms_env_bool('FORCE_HTTPS_AUTO', true);
    }

    return false;
}

function rdomms_session_secure_enabled(): bool
{
    $configured = rdomms_env('SESSION_SECURE');
    if ($configured !== null && $configured !== '') {
        return rdomms_env_bool('SESSION_SECURE', false);
    }

    return rdomms_is_https() || rdomms_is_production_env();
}

function rdomms_csrf_enabled(): bool
{
    return rdomms_env_bool('CSRF_ENABLED', true);
}

function rdomms_is_mutation_request(): bool
{
    return in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['POST', 'PUT', 'DELETE', 'PATCH'], true);
}

function rdomms_get_request_origin(): string
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        return $origin;
    }

    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer === '') {
        return '';
    }

    $parts = parse_url($referer);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return $parts['scheme'] . '://' . $parts['host'] . $port;
}

function rdomms_allowed_cors_origin(): string
{
    $configured = trim((string) rdomms_env('CORS_ALLOWED_ORIGIN', ''));
    if ($configured !== '') {
        return $configured;
    }

    $appUrl = trim((string) rdomms_env('APP_URL', ''));
    if ($appUrl !== '') {
        $parts = parse_url($appUrl);
        if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            return $parts['scheme'] . '://' . $parts['host'] . $port;
        }
    }

    return rdomms_get_request_origin();
}

function rdomms_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    $origin = rdomms_allowed_cors_origin();
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
    header('Access-Control-Allow-Credentials: true');
}

function rdomms_configure_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = rdomms_session_secure_enabled();
    $sameSite = rdomms_env('SESSION_SAMESITE', 'Lax') ?: 'Lax';
    if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
        $sameSite = 'Lax';
    }

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    } else {
        session_set_cookie_params(0, '/', '', $secure, true);
    }
}

function rdomms_ensure_csrf_token(): string
{
    ensureSessionStarted();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function rdomms_get_submitted_csrf_token(): string
{
    $header = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($header !== '') {
        return $header;
    }

    return trim((string) ($_POST['csrf_token'] ?? ''));
}

function rdomms_require_csrf(): void
{
    if (!rdomms_csrf_enabled()) {
        return;
    }

    ensureSessionStarted();
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    $submitted = rdomms_get_submitted_csrf_token();

    if ($expected === '' || $submitted === '' || !hash_equals($expected, $submitted)) {
        sendJSONResponse(['status' => 'error', 'message' => 'Invalid or missing CSRF token'], 403);
    }
}

function rdomms_rate_limit_storage_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'rate-limits';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function rdomms_rate_limit_key(string $action, string $identifier): string
{
    return hash('sha256', strtolower($action) . '|' . strtolower($identifier));
}

function rdomms_rate_limit_read(string $action, string $identifier): array
{
    $file = rdomms_rate_limit_storage_dir() . DIRECTORY_SEPARATOR . rdomms_rate_limit_key($action, $identifier) . '.json';
    if (!is_readable($file)) {
        return ['attempts' => 0, 'locked_until' => 0, 'first_attempt' => time()];
    }

    $payload = json_decode((string) file_get_contents($file), true);
    if (!is_array($payload)) {
        return ['attempts' => 0, 'locked_until' => 0, 'first_attempt' => time()];
    }

    return [
        'attempts' => (int) ($payload['attempts'] ?? 0),
        'locked_until' => (int) ($payload['locked_until'] ?? 0),
        'first_attempt' => (int) ($payload['first_attempt'] ?? time()),
    ];
}

function rdomms_rate_limit_write(string $action, string $identifier, array $state): void
{
    $file = rdomms_rate_limit_storage_dir() . DIRECTORY_SEPARATOR . rdomms_rate_limit_key($action, $identifier) . '.json';
    file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function rdomms_rate_limit_clear(string $action, string $identifier): void
{
    $file = rdomms_rate_limit_storage_dir() . DIRECTORY_SEPARATOR . rdomms_rate_limit_key($action, $identifier) . '.json';
    if (is_file($file)) {
        @unlink($file);
    }
}

function rdomms_rate_limit_check(string $action, string $identifier, ?int $maxAttempts = null, ?int $windowMinutes = null): array
{
    $maxAttempts = $maxAttempts ?? rdomms_env_int('RATE_LIMIT_LOGIN_MAX', 5);
    $windowMinutes = $windowMinutes ?? rdomms_env_int('RATE_LIMIT_LOGIN_WINDOW', 15);
    $now = time();

    $state = rdomms_rate_limit_read($action, $identifier);
    if (($state['locked_until'] ?? 0) > $now) {
        return [
            'allowed' => false,
            'retry_after' => (int) $state['locked_until'] - $now,
            'message' => 'Too many attempts. Please try again later.',
        ];
    }

    if (($state['first_attempt'] ?? $now) + ($windowMinutes * 60) < $now) {
        $state = ['attempts' => 0, 'locked_until' => 0, 'first_attempt' => $now];
    }

    if (($state['attempts'] ?? 0) >= $maxAttempts) {
        $state['locked_until'] = $now + ($windowMinutes * 60);
        rdomms_rate_limit_write($action, $identifier, $state);
        return [
            'allowed' => false,
            'retry_after' => $windowMinutes * 60,
            'message' => 'Too many attempts. Please try again in ' . $windowMinutes . ' minutes.',
        ];
    }

    return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
}

function rdomms_rate_limit_hit(string $action, string $identifier, ?int $maxAttempts = null, ?int $windowMinutes = null): void
{
    $maxAttempts = $maxAttempts ?? rdomms_env_int('RATE_LIMIT_LOGIN_MAX', 5);
    $windowMinutes = $windowMinutes ?? rdomms_env_int('RATE_LIMIT_LOGIN_WINDOW', 15);
    $now = time();

    $state = rdomms_rate_limit_read($action, $identifier);
    if (($state['first_attempt'] ?? $now) + ($windowMinutes * 60) < $now) {
        $state = ['attempts' => 0, 'locked_until' => 0, 'first_attempt' => $now];
    }

    $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
    if ($state['attempts'] >= $maxAttempts) {
        $state['locked_until'] = $now + ($windowMinutes * 60);
    }

    rdomms_rate_limit_write($action, $identifier, $state);
}

function rdomms_client_ip(): string
{
    return trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function rdomms_rate_limit_identifier(string $email = ''): string
{
    $email = strtolower(trim($email));
    return rdomms_client_ip() . '|' . ($email !== '' ? $email : 'anonymous');
}

function rdomms_enforce_rate_limit(string $action, string $identifier, ?int $maxAttempts = null, ?int $windowMinutes = null): void
{
    $result = rdomms_rate_limit_check($action, $identifier, $maxAttempts, $windowMinutes);
    if (!$result['allowed']) {
        sendJSONResponse([
            'status' => 'error',
            'message' => $result['message'],
            'retry_after' => $result['retry_after'],
        ], 429);
    }
}

function rdomms_is_csrf_exempt_request(): bool
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $exempt = [
        '/api/auth/csrf.php',
        '/api/install.php',
        '/api/auth/login.php',
        '/api/auth/signup.php',
        '/api/auth/forgot-password.php',
        '/api/auth/reset-password.php',
        '/api/auth/restore-session.php',
        '/api/auth/logout.php',
        '/api/auth/session-ping.php',
        '/api/auth/update-profile.php',
        '/api/auth/2fa/verify-login.php',
    ];

    foreach ($exempt as $path) {
        if (str_ends_with($script, $path)) {
            return true;
        }
    }

    return false;
}

function rdomms_bootstrap_api_security(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!str_contains($script, '/api/')) {
        return;
    }

    rdomms_send_security_headers();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if (rdomms_is_mutation_request() && !rdomms_is_csrf_exempt_request()) {
        rdomms_require_csrf();
    }
}

function rdomms_force_https_if_configured(): void
{
    rdomms_sync_https_flag_file();

    if (!rdomms_should_force_https() || rdomms_is_https()) {
        return;
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if ($host === '') {
        return;
    }

    header('Location: https://' . $host . $uri, true, 301);
    exit;
}

function rdomms_sync_https_flag_file(): void
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'security';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $flag = $dir . DIRECTORY_SEPARATOR . '.force-https';
    if (rdomms_should_force_https()) {
        if (!is_file($flag)) {
            file_put_contents($flag, date('c'));
        }
        return;
    }

    if (is_file($flag)) {
        @unlink($flag);
    }
}
