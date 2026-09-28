<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../config/departments.php';
require_once __DIR__ . '/../../config/system-settings.php';
require_once __DIR__ . '/../../config/notification-preferences.php';

rdomms_send_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

rdomms_enforce_rate_limit('signup', rdomms_rate_limit_identifier());

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$required = ['fullname', 'email', 'password', 'role', 'department', 'organization'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        sendJSONResponse(['status' => 'error', 'message' => "Field '{$field}' is required"], 400);
    }
}

$allowedPublicRoles = [
    'head_of_department',
    'head_of_organization_center',
    'human_resource_manager',
    'accountant',
    'field_officer',
    'staff'
];

if (normalizeRoleName($data['role'] ?? '') === 'admin') {
    sendJSONResponse(['status' => 'error', 'message' => 'Admin accounts cannot be created via signup. Contact the system administrator.'], 403);
}
$selectedRole = normalizeRoleName($data['role']);
requireValidRole($selectedRole, $allowedPublicRoles);

$department = normalizeRdommsDepartment((string) $data['department']);
if ($department === null) {
    sendJSONResponse(['status' => 'error', 'message' => 'Invalid department selected'], 400);
}

if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    sendJSONResponse(['status' => 'error', 'message' => 'Invalid email format'], 400);
}

if (strlen($data['password']) < 8 || !preg_match('/[A-Z]/', $data['password']) || !preg_match('/[0-9]/', $data['password'])) {
    sendJSONResponse(['status' => 'error', 'message' => 'Password must be at least 8 characters with uppercase and number'], 400);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

$settings = rdomms_load_general_settings($conn);
if (!rdomms_is_truthy($settings['allow_registration'] ?? '1')) {
    sendJSONResponse(['status' => 'error', 'message' => 'Public registration is currently disabled by the system administrator.'], 403);
}

$profilePicturePath = null;
$profilePicture = trim((string) ($data['profile_picture'] ?? ''));
if ($profilePicture !== '') {
    if (!preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,#i', $profilePicture)) {
        sendJSONResponse(['status' => 'error', 'message' => 'Invalid profile picture format'], 400);
    }

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
}

$stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$data['email']]);
if ($stmt->fetch()) {
    sendJSONResponse(['status' => 'error', 'message' => 'Email already registered'], 409);
}

$userId = 'USR' . time() . rand(100, 999);
$hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

$stmt = $conn->prepare(
    'INSERT INTO users (user_id, fullname, email, password, role, department, organization, phone, employee_id, profile_picture, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$result = $stmt->execute([
    $userId,
    $data['fullname'],
    $data['email'],
    $hashedPassword,
    $selectedRole,
    $department,
    $data['organization'],
    rdomms_encrypt_sensitive(isset($data['phone']) ? (string) $data['phone'] : null),
    rdomms_encrypt_sensitive(isset($data['employeeId']) ? (string) $data['employeeId'] : null),
    $profilePicturePath,
    'pending'
]);

if ($result) {
    $roleLabel = str_replace('_', ' ', $selectedRole);
    rdomms_notify_admin_users(
        $conn,
        'user_role',
        'New registration awaiting approval',
        sprintf(
            '%s (%s) registered as %s in %s. Review and approve the account in Users → All Users.',
            $data['fullname'],
            $data['email'],
            $roleLabel,
            $data['organization']
        ),
        'Users',
        'warning'
    );

    logActivity(null, 'New user registered', 'Auth', 'success', 'User ' . $data['email'] . ' registered (pending approval)');
    sendJSONResponse([
        'status' => 'success',
        'message' => 'Registration received. Your account is pending administrator approval. You will be able to sign in once approved.',
        'data' => [
            'user_id' => $userId,
            'profile_picture' => $profilePicturePath,
            'pending_approval' => true,
        ]
    ], 201);
}

sendJSONResponse(['status' => 'error', 'message' => 'Registration failed'], 500);
