<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../config/system-settings.php';
require_once __DIR__ . '/../../config/organization-profile.php';
require_once __DIR__ . '/../../config/session-manager.php';

rdomms_send_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSONResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$session = verifySession(false);
if (!$session) {
    sendJSONResponse([
        'success' => false,
        'message' => 'Session expired. Please sign in again.',
        'code' => 'SESSION_EXPIRED',
    ], 401);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['success' => false, 'message' => 'Database connection failed'], 500);
}

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ? OR user_id = ? LIMIT 1');
$stmt->execute([
    $session['user_id'] ?? null,
    $session['user_id'] ?? null
]);
$user = $stmt->fetch();

if (!$user) {
    sendJSONResponse(['success' => false, 'message' => 'User not found'], 404);
}

$settings = rdomms_load_general_settings($conn);
$organization = rdomms_load_primary_organization($conn);

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
            'profile_picture' => $user['profile_picture'] ?? null
        ],
        'session_token' => $_SESSION['session_token'] ?? null,
        'login_time' => $session['created_at'] ?? null,
        'session' => rdomms_build_session_client_meta($session),
        'system_settings' => rdomms_general_settings_for_client($settings),
        'organization' => rdomms_organization_row_to_client($organization),
    ]
]);
