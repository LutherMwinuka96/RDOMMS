<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/finance-links.php';

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

$session = verifySession();
if (!$session) {
    sendJSONResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

requireRole(['admin', 'accountant'], $session);

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

$currentUser = getAuthenticatedUser($session);
$filters = [
    'organization' => $_GET['organization'] ?? $_GET['station'] ?? '',
    'department' => $_GET['department'] ?? '',
    'year' => $_GET['year'] ?? $_GET['fiscal_year'] ?? '',
    'quarter' => $_GET['quarter'] ?? '',
];

$sources = rdomms_fetch_funding_sources($conn, $currentUser, $filters);

sendJSONResponse([
    'status' => 'success',
    'data' => $sources,
]);
