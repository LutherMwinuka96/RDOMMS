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

if (rdomms_two_factor_user_enabled($user)) {
    sendJSONResponse(['status' => 'error', 'message' => 'Two-factor authentication is already enabled'], 400);
}

$existingSecret = rdomms_two_factor_get_setup_secret($conn, $userId);
if ($existingSecret !== null) {
    $storedSecret = $existingSecret;
} else {
    $secret = rdomms_totp_generate_secret();
    $storedSecret = rdomms_two_factor_store_secret($secret);
    rdomms_two_factor_save_setup_pending($conn, $userId, $storedSecret);
}

$email = (string) ($user['email'] ?? 'user@rdomms.local');

$provisioningUri = rdomms_totp_provisioning_uri($email, $storedSecret);
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($provisioningUri);

sendJSONResponse([
    'status' => 'success',
    'data' => [
        'secret' => $storedSecret,
        'provisioning_uri' => $provisioningUri,
        'qr_url' => $qrUrl,
    ],
]);
