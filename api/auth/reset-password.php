<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../config/mailer.php';

rdomms_send_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSONResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

$token = trim((string) ($data['token'] ?? ''));
$newPassword = (string) ($data['new_password'] ?? '');

if ($token === '' || $newPassword === '') {
    sendJSONResponse(['success' => false, 'message' => 'Token and new password are required'], 400);
}

rdomms_enforce_rate_limit(
    'reset_password',
    rdomms_rate_limit_identifier($token),
    rdomms_env_int('RATE_LIMIT_PASSWORD_MAX', 3),
    rdomms_env_int('RATE_LIMIT_PASSWORD_WINDOW', 30)
);

if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
    sendJSONResponse(['success' => false, 'message' => 'New password does not meet security requirements'], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['success' => false, 'message' => 'Database connection failed'], 500);
}

ensurePasswordResetTokensTable($conn);

$stmt = $conn->prepare(
    'SELECT prt.id, prt.user_id, prt.email, prt.expires_at, prt.used_at, u.user_id AS public_user_id
     FROM password_reset_tokens prt
     INNER JOIN users u ON u.id = prt.user_id
     WHERE prt.token = ?
     LIMIT 1'
);
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row) {
    rdomms_rate_limit_hit(
        'reset_password',
        rdomms_rate_limit_identifier($token),
        rdomms_env_int('RATE_LIMIT_PASSWORD_MAX', 3),
        rdomms_env_int('RATE_LIMIT_PASSWORD_WINDOW', 30)
    );
    sendJSONResponse(['success' => false, 'message' => 'This reset link is invalid.'], 404);
}

if (!empty($row['used_at'])) {
    sendJSONResponse(['success' => false, 'message' => 'This reset link has already been used.'], 410);
}

if (strtotime((string) $row['expires_at']) < time()) {
    sendJSONResponse(['success' => false, 'message' => 'This reset link has expired. Request a new one.'], 410);
}

$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

$conn->beginTransaction();
try {
    $stmt = $conn->prepare('UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$hashedPassword, $row['user_id']]);

    $stmt = $conn->prepare('UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$row['id']]);

    $stmt = $conn->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? AND id <> ?');
    $stmt->execute([$row['user_id'], $row['id']]);

    $stmt = $conn->prepare('DELETE FROM user_sessions WHERE user_id = ? OR user_id = ?');
    $stmt->execute([$row['user_id'], $row['public_user_id']]);

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollBack();
    sendJSONResponse(['success' => false, 'message' => 'Unable to reset password'], 500);
}

logActivity(
    $row['public_user_id'],
    'Password reset completed',
    'auth',
    'success',
    'Password reset via email link for ' . $row['email'],
    $_SERVER['REMOTE_ADDR'] ?? null
);

rdomms_rate_limit_clear('reset_password', rdomms_rate_limit_identifier($token));

sendJSONResponse([
    'success' => true,
    'message' => 'Password reset successfully. You can now sign in with your new password.',
]);
