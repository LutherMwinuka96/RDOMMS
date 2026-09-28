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
    sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$session = verifySession(true);
if (!$session) {
    sendJSONResponse([
        'status' => 'error',
        'message' => 'Session expired. Please sign in again.',
        'code' => 'SESSION_EXPIRED',
    ], 401);
}

sendJSONResponse([
    'status' => 'success',
    'message' => 'Session extended',
    'data' => [
        'session' => rdomms_build_session_client_meta($session),
    ],
]);
