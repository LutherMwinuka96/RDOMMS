<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../config/session-manager.php';

rdomms_send_security_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sendJSONResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput ?: '', true);
if (!is_array($data)) {
    $data = [];
}

$sessionToken = trim((string) (
    $_SERVER['HTTP_X_SESSION_TOKEN']
    ?? ($data['session_token'] ?? '')
    ?? ''
));

if ($sessionToken === '') {
    sendJSONResponse(['success' => false, 'message' => 'Session token required'], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['success' => false, 'message' => 'Database connection failed'], 500);
}

$session = rdomms_fetch_active_session($conn, $sessionToken, false);
if (!$session) {
    sendJSONResponse([
        'success' => false,
        'message' => 'Session expired. Please sign in again.',
        'code' => 'SESSION_EXPIRED',
    ], 401);
}

ensureSessionStarted();
$_SESSION['user_id'] = $session['user_id'] ?? null;
$_SESSION['session_token'] = $sessionToken;

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ? OR user_id = ? LIMIT 1');
$stmt->execute([
    $session['user_id'] ?? null,
    $session['user_id'] ?? null,
]);
$user = $stmt->fetch();

if (!$user) {
    sendJSONResponse(['success' => false, 'message' => 'User not found'], 404);
}

$_SESSION['user_name'] = $user['fullname'] ?? '';
$_SESSION['user_role'] = normalizeRoleName($user['role'] ?? 'user');

sendJSONResponse([
    'success' => true,
    'data' => [
        'user' => [
            'id' => $user['user_id'] ?? $user['id'],
            'db_id' => (int) ($user['id'] ?? 0),
            'fullname' => $user['fullname'] ?? '',
            'email' => $user['email'] ?? '',
            'role' => normalizeRoleName($user['role'] ?? 'user'),
            'department' => $user['department'] ?? '',
            'organization' => $user['organization'] ?? '',
            'profile_picture' => $user['profile_picture'] ?? null,
        ],
        'session_token' => $sessionToken,
        'login_time' => $session['created_at'] ?? null,
        'session' => rdomms_build_session_client_meta($session),
    ],
]);
