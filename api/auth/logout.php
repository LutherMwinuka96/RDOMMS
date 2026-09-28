<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../config/session-manager.php';

rdomms_send_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ensureSessionStarted();

$db = new Database();
$conn = $db->getConnection();

if (isset($_SESSION['session_token']) && $conn) {
    rdomms_destroy_session_token($conn, (string) $_SESSION['session_token']);
    logActivity($_SESSION['user_id'] ?? null, 'User logged out', 'Auth', 'info', 'User logged out');
}

rdomms_destroy_php_session();

sendJSONResponse(['status' => 'success', 'message' => 'Logged out successfully']);

