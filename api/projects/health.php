<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/project-health.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$session = verifySession();
if (!$session) {
    sendJSONResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

requireRole(
    ['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff', 'accountant'],
    $session
);

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

$currentUser = getAuthenticatedUser($session);
$scopeMode = getScopeModeFromRequest($currentUser);

try {
    $payload = rdomms_build_project_health_payload($conn, $currentUser, $scopeMode);
    sendJSONResponse(['status' => 'success', 'data' => $payload]);
} catch (Throwable $e) {
    error_log('project health: ' . $e->getMessage());
    sendJSONResponse(['status' => 'error', 'message' => 'Could not load project health data'], 500);
}
