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

$session = verifySession();
if (!$session) {
    sendJSONResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$password = (string) ($data['password'] ?? '');
$code = trim((string) ($data['code'] ?? ''));

if ($password === '') {
    sendJSONResponse(['status' => 'error', 'message' => 'Password is required to disable 2FA'], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

ensureTwoFactorSchema($conn);

$userId = rdomms_resolve_numeric_user_id($conn, $session['user_id'] ?? null);
if ($userId <= 0) {
    sendJSONResponse(['status' => 'error', 'message' => 'User not found'], 404);
}

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    sendJSONResponse(['status' => 'error', 'message' => 'User not found'], 404);
}

$needsRehash = false;
if (!rdomms_verify_password($password, (string) ($user['password'] ?? ''), $needsRehash)) {
    sendJSONResponse(['status' => 'error', 'message' => 'Invalid password'], 401);
}

if (rdomms_two_factor_user_enabled($user) && $code !== '' && !rdomms_two_factor_verify_code($user, $code)) {
    sendJSONResponse(['status' => 'error', 'message' => 'Invalid verification code'], 400);
}

$stmt = $conn->prepare(
    'UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL, two_factor_backup_codes = NULL WHERE id = ?'
);
$stmt->execute([$user['id']]);

rdomms_two_factor_clear_setup_pending($conn, (int) $user['id']);

try {
    $cleanup = $conn->prepare('DELETE FROM two_factor_login_pending WHERE user_id = ?');
    $cleanup->execute([(int) $user['id']]);
} catch (Throwable $e) {
    error_log('2fa disable cleanup pending: ' . $e->getMessage());
}

logActivity($user['id'], '2FA disabled', 'Auth', 'warning', 'Two-factor authentication disabled');

sendJSONResponse([
    'status' => 'success',
    'message' => 'Two-factor authentication disabled',
]);
