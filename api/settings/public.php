<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/system-settings.php';
require_once __DIR__ . '/../../config/organization-profile.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

$settings = rdomms_load_general_settings($conn);
$organization = rdomms_load_primary_organization($conn);
$payload = rdomms_public_settings_for_client($settings);
$payload['organization'] = rdomms_organization_public_client($organization);

sendJSONResponse([
    'status' => 'success',
    'data' => $payload,
]);
