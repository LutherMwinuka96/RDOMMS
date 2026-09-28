<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/departments.php';

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

$session = verifySession();
if (!$session) {
    sendJSONResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ? OR user_id = ? LIMIT 1');
$stmt->execute([
    $session['user_id'] ?? null,
    $session['user_id'] ?? null
]);
$user = $stmt->fetch();

if (!$user) {
    sendJSONResponse(['status' => 'error', 'message' => 'User not found'], 404);
}

$user = rdomms_hydrate_user_sensitive_fields($user);

$fullname = trim((string) ($data['fullname'] ?? $user['fullname'] ?? ''));
$phone = trim((string) ($data['phone'] ?? $user['phone'] ?? ''));
$departmentInput = trim((string) ($data['department'] ?? $user['department'] ?? ''));
$department = $departmentInput !== '' ? normalizeRdommsDepartment($departmentInput) : '';
if ($departmentInput !== '' && $department === null) {
    sendJSONResponse(['status' => 'error', 'message' => 'Invalid department selected'], 400);
}
$organization = trim((string) ($data['organization'] ?? $user['organization'] ?? ''));
$profilePicture = trim((string) ($data['profile_picture'] ?? ''));

if ($fullname === '') {
    sendJSONResponse(['status' => 'error', 'message' => 'Full name is required'], 400);
}

$profilePicturePath = $user['profile_picture'] ?? null;
if ($profilePicture !== '') {
    if (preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,#i', $profilePicture)) {
        $uploadDir = __DIR__ . '/../../uploads/profile-pictures';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            sendJSONResponse(['status' => 'error', 'message' => 'Unable to prepare profile picture storage'], 500);
        }

        [$meta, $base64Data] = explode(',', $profilePicture, 2);
        $binaryData = base64_decode($base64Data, true);
        if ($binaryData === false) {
            sendJSONResponse(['status' => 'error', 'message' => 'Invalid profile picture data'], 400);
        }

        if (strlen($binaryData) > 2 * 1024 * 1024) {
            sendJSONResponse(['status' => 'error', 'message' => 'Profile picture must be 2MB or smaller'], 400);
        }

        $extension = 'png';
        if (stripos($meta, 'jpeg') !== false || stripos($meta, 'jpg') !== false) {
            $extension = 'jpg';
        } elseif (stripos($meta, 'gif') !== false) {
            $extension = 'gif';
        } elseif (stripos($meta, 'webp') !== false) {
            $extension = 'webp';
        }

        $fileName = uniqid('profile_', true) . '.' . $extension;
        $filePath = $uploadDir . '/' . $fileName;
        if (file_put_contents($filePath, $binaryData) === false) {
            sendJSONResponse(['status' => 'error', 'message' => 'Failed to save profile picture'], 500);
        }

        $profilePicturePath = 'uploads/profile-pictures/' . $fileName;
    } elseif (
        preg_match('#^uploads/profile-pictures/[a-zA-Z0-9._-]+$#', $profilePicture)
        || preg_match('#^assets/[a-zA-Z0-9/._-]+$#', $profilePicture)
    ) {
        $profilePicturePath = $profilePicture;
    }
}

$stmt = $conn->prepare(
    'UPDATE users SET fullname = ?, phone = ?, department = ?, organization = ?, profile_picture = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? OR user_id = ?'
);
$result = $stmt->execute([
    $fullname,
    rdomms_encrypt_sensitive($phone ?: null),
    $department ?: null,
    $organization ?: null,
    $profilePicturePath,
    $user['id'],
    $user['user_id']
]);

if (!$result) {
    sendJSONResponse(['status' => 'error', 'message' => 'Failed to update profile'], 500);
}

$_SESSION['user_name'] = $fullname;

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ? OR user_id = ? LIMIT 1');
$stmt->execute([$user['id'], $user['user_id']]);
$updatedUser = $stmt->fetch() ?: $user;
$updatedUser = rdomms_hydrate_user_sensitive_fields($updatedUser);

logActivity($user['id'], 'Profile updated', 'Profile', 'success', 'User profile updated');

sendJSONResponse([
    'status' => 'success',
    'message' => 'Profile updated successfully',
    'data' => [
        'user' => [
            'id' => $updatedUser['user_id'] ?? $updatedUser['id'],
            'db_id' => (int) ($updatedUser['id'] ?? 0),
            'fullname' => $updatedUser['fullname'] ?? $fullname,
            'email' => $updatedUser['email'] ?? '',
            'role' => normalizeRoleName($updatedUser['role'] ?? 'user'),
            'department' => $updatedUser['department'] ?? '',
            'organization' => $updatedUser['organization'] ?? '',
            'phone' => $updatedUser['phone'] ?? '',
            'profile_picture' => $updatedUser['profile_picture'] ?? $profilePicturePath
        ]
    ]
]);
