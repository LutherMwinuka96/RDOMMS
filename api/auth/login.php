<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/system-settings.php';
require_once __DIR__ . '/../../config/two-factor.php';

rdomms_send_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

if (empty($data['email']) || empty($data['password'])) {
    sendJSONResponse(['status' => 'error', 'message' => 'Email and password required'], 400);
}

$email = strtolower(trim((string) $data['email']));
rdomms_enforce_rate_limit('login', rdomms_rate_limit_identifier($email));

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

ensureTwoFactorSchema($conn);
ensureSensitiveDataSchema($conn);
rdomms_maybe_run_encryption_migration($conn);

$stmt = $conn->prepare('SELECT * FROM users WHERE LOWER(email) = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    rdomms_rate_limit_hit('login', rdomms_rate_limit_identifier($email));
    logActivity(null, 'Failed login attempt', 'Auth', 'warning', 'Unknown email: ' . $email);
    sendJSONResponse(['status' => 'error', 'message' => 'Invalid email or password'], 401);
}

$needsRehash = false;
if (!rdomms_verify_password((string) $data['password'], (string) ($user['password'] ?? ''), $needsRehash)) {
    rdomms_rate_limit_hit('login', rdomms_rate_limit_identifier($email));
    logActivity($user['id'], 'Failed login attempt', 'Auth', 'warning', 'Wrong password for: ' . $email);
    sendJSONResponse(['status' => 'error', 'message' => 'Invalid email or password'], 401);
}

if ($needsRehash) {
    try {
        $rehash = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $rehash->execute([password_hash((string) $data['password'], PASSWORD_DEFAULT), $user['id']]);
    } catch (Throwable $e) {
        error_log('login password rehash failed: ' . $e->getMessage());
    }
}

rdomms_rate_limit_clear('login', rdomms_rate_limit_identifier($email));

$settings = rdomms_load_general_settings($conn);
if (rdomms_is_truthy($settings['maintenance_mode'] ?? '0') && normalizeRoleName($user['role'] ?? '') !== 'admin') {
    logActivity($user['id'], 'Maintenance login blocked', 'Auth', 'warning', 'Non-admin login blocked during maintenance: ' . $data['email']);
    sendJSONResponse(['status' => 'error', 'message' => 'System is under maintenance. Only administrators can sign in right now.'], 503);
}

$userStatus = strtolower((string) ($user['status'] ?? 'active'));
if ($userStatus === 'pending') {
    sendJSONResponse(['status' => 'error', 'message' => 'Your account is pending administrator approval. You will be able to sign in once an admin approves your registration.'], 403);
}
if ($userStatus === 'suspended') {
    sendJSONResponse(['status' => 'error', 'message' => 'Your account has been suspended. Please contact an administrator.'], 403);
}

$user = rdomms_two_factor_normalize_user_secret($conn, $user);

if (rdomms_two_factor_user_enabled($user)) {
    ensureSessionStarted();
    $pendingToken = rdomms_two_factor_set_pending($conn, $user);
    sendJSONResponse([
        'status' => 'success',
        'message' => 'Two-factor authentication required',
        'data' => [
            'requires_2fa' => true,
            'pending_token' => $pendingToken,
            'user' => [
                'email' => $user['email'],
                'fullname' => $user['fullname'],
            ],
        ],
    ]);
}

$loginData = rdomms_finalize_login($conn, $user);

sendJSONResponse([
    'status' => 'success',
    'message' => 'Login successful',
    'data' => $loginData,
]);
