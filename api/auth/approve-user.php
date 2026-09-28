<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/notification-preferences.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$userId = trim((string) ($data['user_id'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$status = strtolower(trim((string) ($data['status'] ?? 'active')));

if ($userId === '' && $email === '') {
    sendJSONResponse(['status' => 'error', 'message' => 'User ID or email is required'], 400);
}

if (!in_array($status, ['active', 'pending', 'suspended'], true)) {
    sendJSONResponse(['status' => 'error', 'message' => 'Invalid status'], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

ensureSessionStarted();
$session = verifySession();
if (!$session) {
    sendJSONResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$stmt = $conn->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$session['user_id']]);
$currentUser = $stmt->fetch();
if (!$currentUser || strtolower((string) $currentUser['role']) !== 'admin') {
    sendJSONResponse(['status' => 'error', 'message' => 'Admin access required'], 403);
}

if ($userId !== '') {
    $stmt = $conn->prepare('UPDATE users SET status = ? WHERE user_id = ?');
    $stmt->execute([$status, $userId]);
    $lookup = $conn->prepare('SELECT id, fullname, email FROM users WHERE user_id = ? LIMIT 1');
    $lookup->execute([$userId]);
} else {
    $stmt = $conn->prepare('UPDATE users SET status = ? WHERE email = ?');
    $stmt->execute([$status, $email]);
    $lookup = $conn->prepare('SELECT id, fullname, email FROM users WHERE email = ? LIMIT 1');
    $lookup->execute([$email]);
}

$approvedUser = $lookup->fetch() ?: [];

if ($stmt->rowCount() === 0) {
    sendJSONResponse(['status' => 'error', 'message' => 'User not found or already up to date'], 404);
}

if ($status === 'active') {
    $targetDbId = (int) ($approvedUser['id'] ?? 0);
    if ($targetDbId > 0) {
        rdomms_notify_user_account(
            $conn,
            'user_role',
            'Account approved',
            'Your RDOMMS account is now active. You can sign in and start using the system.',
            'Users',
            'success',
            $targetDbId
        );
    }
}

logActivity($currentUser['id'], 'Updated user status', 'Auth', 'success', 'User status changed to ' . $status);

sendJSONResponse([
    'status' => 'success',
    'message' => 'User status updated successfully',
    'data' => [
        'status' => $status,
        'user_id' => $userId !== '' ? $userId : null,
        'email' => $email !== '' ? $email : null,
    ],
]);
