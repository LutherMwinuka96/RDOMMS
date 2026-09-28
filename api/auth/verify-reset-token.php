<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mailer.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSONResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    sendJSONResponse(['success' => false, 'message' => 'Reset token is required'], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['success' => false, 'message' => 'Database connection failed'], 500);
}

ensurePasswordResetTokensTable($conn);

$stmt = $conn->prepare(
    'SELECT prt.id, prt.email, prt.expires_at, prt.used_at, u.fullname
     FROM password_reset_tokens prt
     INNER JOIN users u ON u.id = prt.user_id
     WHERE prt.token = ?
     LIMIT 1'
);
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row) {
    sendJSONResponse(['success' => false, 'message' => 'This reset link is invalid.'], 404);
}

if (!empty($row['used_at'])) {
    sendJSONResponse(['success' => false, 'message' => 'This reset link has already been used.'], 410);
}

if (strtotime((string) $row['expires_at']) < time()) {
    sendJSONResponse(['success' => false, 'message' => 'This reset link has expired. Request a new one.'], 410);
}

sendJSONResponse([
    'success' => true,
    'message' => 'Reset link is valid.',
    'data' => [
        'email' => $row['email'],
        'fullname' => $row['fullname'],
    ],
]);
