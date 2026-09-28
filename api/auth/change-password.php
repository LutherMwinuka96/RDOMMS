<?php
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSONResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

$email = strtolower(trim((string) ($data['email'] ?? '')));
$currentPassword = (string) ($data['current_password'] ?? '');
$newPassword = (string) ($data['new_password'] ?? '');

if ($email === '' || $currentPassword === '' || $newPassword === '') {
    sendJSONResponse(['success' => false, 'message' => 'Email, current password and new password are required'], 400);
}

if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
    sendJSONResponse(['success' => false, 'message' => 'New password does not meet security requirements'], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['success' => false, 'message' => 'Database connection failed'], 500);
}

$stmt = $conn->prepare('SELECT * FROM users WHERE LOWER(email) = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    sendJSONResponse(['success' => false, 'message' => 'Account not found.'], 404);
}

if (!password_verify($currentPassword, $user['password'])) {
    sendJSONResponse(['success' => false, 'message' => 'The current password is incorrect.'], 401);
}

$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = $conn->prepare('UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? OR user_id = ?');
$stmt->execute([
    $hashedPassword,
    $user['id'],
    $user['user_id']
]);

$stmt = $conn->prepare('DELETE FROM user_sessions WHERE user_id = ? OR user_id = ?');
$stmt->execute([$user['id'], $user['user_id']]);

sendJSONResponse([
    'success' => true,
    'message' => 'Password changed successfully.'
]);
