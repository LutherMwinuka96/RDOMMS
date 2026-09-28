<?php
declare(strict_types=1);

define('RDOMMS_SKIP_SECURITY', true);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/security.php';

rdomms_send_security_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$token = rdomms_ensure_csrf_token();

sendJSONResponse([
    'status' => 'success',
    'data' => [
        'token' => $token,
    ],
]);
