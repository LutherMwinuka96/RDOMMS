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

$email = strtolower(trim((string) ($data['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendJSONResponse(['success' => false, 'message' => 'A valid email address is required'], 400);
}

rdomms_enforce_rate_limit(
    'forgot_password',
    rdomms_rate_limit_identifier($email),
    rdomms_env_int('RATE_LIMIT_PASSWORD_MAX', 3),
    rdomms_env_int('RATE_LIMIT_PASSWORD_WINDOW', 30)
);

$resetBaseUrl = trim((string) ($data['reset_base_url'] ?? ''));

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['success' => false, 'message' => 'Database connection failed'], 500);
}

ensurePasswordResetTokensTable($conn);

$stmt = $conn->prepare('SELECT id, user_id, fullname, email FROM users WHERE LOWER(email) = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    rdomms_rate_limit_hit(
        'forgot_password',
        rdomms_rate_limit_identifier($email),
        rdomms_env_int('RATE_LIMIT_PASSWORD_MAX', 3),
        rdomms_env_int('RATE_LIMIT_PASSWORD_WINDOW', 30)
    );
    sendJSONResponse(['success' => false, 'message' => 'This email address is not registered in our database.'], 404);
}

$token = bin2hex(random_bytes(32));
$expiresMinutes = 60;
$expiresAt = (new DateTimeImmutable('now'))->modify('+' . $expiresMinutes . ' minutes')->format('Y-m-d H:i:s');

$conn->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$user['id']]);

$stmt = $conn->prepare(
    'INSERT INTO password_reset_tokens (user_id, email, token, expires_at) VALUES (?, ?, ?, ?)'
);
$stmt->execute([$user['id'], $user['email'], $token, $expiresAt]);

$resetLink = buildPasswordResetUrl($token, $resetBaseUrl !== '' ? $resetBaseUrl : null);

$mailResult = sendPasswordResetEmail($user['email'], $user['fullname'], $resetLink, $expiresMinutes);
if (empty($mailResult['success'])) {
    $conn->prepare('DELETE FROM password_reset_tokens WHERE token = ?')->execute([$token]);
    sendJSONResponse([
        'success' => false,
        'message' => $mailResult['message'] ?? 'Unable to send reset email. Configure SMTP at settings/email-smtp.html',
        'data' => [
            'delivery_method' => $mailResult['method'] ?? 'none',
            'setup_url' => getAppBaseUrl() . '/settings/email-smtp.html',
        ],
    ], 500);
}

logActivity(
    $user['user_id'],
    'Password reset link sent',
    'auth',
    'info',
    'Reset email dispatched to ' . $user['email'],
    $_SERVER['REMOTE_ADDR'] ?? null
);

rdomms_rate_limit_hit(
    'forgot_password',
    rdomms_rate_limit_identifier($email),
    rdomms_env_int('RATE_LIMIT_PASSWORD_MAX', 3),
    rdomms_env_int('RATE_LIMIT_PASSWORD_WINDOW', 30)
);

sendJSONResponse([
    'success' => true,
    'message' => 'A password reset link has been sent to ' . $user['email'] . '. Please check your inbox and spam folder.',
    'data' => [
        'email' => $user['email'],
        'expires_in_minutes' => $expiresMinutes,
        'delivery_method' => $mailResult['method'] ?? 'smtp',
        'reset_link' => $resetLink,
    ],
]);
