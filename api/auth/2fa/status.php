<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/two-factor.php';

rdomms_send_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    sendJSONResponse([
        'status' => 'success',
        'data' => [
            'enabled' => rdomms_two_factor_user_enabled($user),
            'encryption_enabled' => rdomms_encryption_enabled(),
        ],
    ]);
}

sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
