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

$code = trim((string) ($data['code'] ?? ''));
if ($code === '') {
    sendJSONResponse(['status' => 'error', 'message' => 'Verification code is required'], 400);
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

$plainSecret = rdomms_two_factor_get_setup_secret($conn, $userId);

if ($plainSecret === null || !rdomms_totp_verify($plainSecret, $code)) {
    sendJSONResponse(['status' => 'error', 'message' => 'Invalid verification code. Enter the current 6-digit code from your authenticator app.'], 400);
}

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    sendJSONResponse(['status' => 'error', 'message' => 'User not found'], 404);
}

$backupCodes = rdomms_two_factor_generate_backup_codes();
$hashedCodes = rdomms_two_factor_hash_backup_codes($backupCodes);

$stmt = $conn->prepare(
    'UPDATE users SET two_factor_enabled = 1, two_factor_secret = ?, two_factor_backup_codes = ? WHERE id = ?'
);
$stmt->execute([$plainSecret, $hashedCodes, $user['id']]);

rdomms_two_factor_clear_setup_pending($conn, (int) $user['id']);

logActivity($user['id'], '2FA enabled', 'Auth', 'success', 'Two-factor authentication enabled');

sendJSONResponse([
    'status' => 'success',
    'message' => 'Two-factor authentication enabled',
    'data' => [
        'backup_codes' => $backupCodes,
    ],
]);
