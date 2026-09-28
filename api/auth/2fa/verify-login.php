<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/two-factor.php';

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

$pendingToken = trim((string) ($data['pending_token'] ?? ''));
$code = trim((string) ($data['code'] ?? ''));

if ($pendingToken === '' || $code === '') {
    sendJSONResponse(['status' => 'error', 'message' => 'Verification code and pending token are required'], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

ensureTwoFactorSchema($conn);

$user = rdomms_two_factor_get_pending_user($conn, $pendingToken);
if (!$user) {
    sendJSONResponse([
        'status' => 'error',
        'message' => 'Verification session expired. Please go back and sign in again with your email and password.',
    ], 401);
}

$user = rdomms_two_factor_normalize_user_secret($conn, $user);

if (!rdomms_two_factor_user_enabled($user)) {
    rdomms_two_factor_clear_pending($conn, $pendingToken);
    sendJSONResponse(['status' => 'error', 'message' => 'Two-factor authentication is not configured for this account.'], 400);
}

$email = strtolower(trim((string) ($user['email'] ?? '')));
rdomms_enforce_rate_limit('2fa_login', rdomms_rate_limit_identifier($email), 10, 15);

if (!rdomms_two_factor_verify_code($user, $code)) {
    rdomms_rate_limit_hit('2fa_login', rdomms_rate_limit_identifier($email));
    logActivity($user['id'], 'Failed 2FA verification', 'Auth', 'warning', 'Invalid 2FA code during login');
    sendJSONResponse([
        'status' => 'error',
        'message' => 'Invalid verification code. Open your authenticator app and enter the current 6-digit code for RDOMMS.',
    ], 401);
}

if (rdomms_two_factor_is_backup_code($code)) {
    rdomms_two_factor_consume_backup_code($conn, $user, $code);
}

rdomms_two_factor_clear_pending($conn, $pendingToken);
rdomms_rate_limit_clear('2fa_login', rdomms_rate_limit_identifier($email));

$userStatus = strtolower((string) ($user['status'] ?? 'active'));
if ($userStatus === 'pending') {
    sendJSONResponse([
        'status' => 'error',
        'message' => 'Your account is pending administrator approval. You will be able to sign in once an admin approves your registration.',
    ], 403);
}
if ($userStatus === 'suspended') {
    sendJSONResponse([
        'status' => 'error',
        'message' => 'Your account has been suspended. Please contact an administrator.',
    ], 403);
}

$loginData = rdomms_finalize_login($conn, $user);

sendJSONResponse([
    'status' => 'success',
    'message' => 'Login successful',
    'data' => $loginData,
]);
