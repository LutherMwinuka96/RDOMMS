<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/departments.php';
require_once __DIR__ . '/../config/notification-preferences.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$session = verifySession();
if (!$session) {
    sendJSONResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$teamPickerScope = ($_SERVER['REQUEST_METHOD'] === 'GET') && (($_GET['scope'] ?? '') === 'team');

if ($teamPickerScope) {
    requireRole(['admin', 'human_resource_manager', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff'], $session);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireRole(['admin', 'human_resource_manager', 'head_of_organization_center', 'head_of_department', 'field_officer'], $session);
} elseif (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true)) {
    requireRole(['admin', 'human_resource_manager', 'head_of_organization_center', 'head_of_department'], $session);
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    requireRole(['admin', 'head_of_organization_center'], $session);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

$currentUser = getAuthenticatedUser($session);
$method = $_SERVER['REQUEST_METHOD'];

function userRowToResponse(array $row): array
{
    $row = rdomms_hydrate_user_sensitive_fields($row);
    return [
        'id' => $row['user_id'] ?? $row['id'],
        '_id' => $row['user_id'] ?? $row['id'],
        'user_id' => $row['user_id'] ?? null,
        'fullname' => $row['fullname'] ?? '',
        'email' => $row['email'] ?? '',
        'role' => $row['role'] ?? '',
        'department' => $row['department'] ?? '',
        'organization' => $row['organization'] ?? '',
        'phone' => $row['phone'] ?? '',
        'employeeId' => $row['employee_id'] ?? '',
        'status' => ucfirst((string)($row['status'] ?? 'pending')),
        'profile_picture' => $row['profile_picture'] ?? null,
        'profileImage' => $row['profile_picture'] ?? null,
        'notes' => $row['notes'] ?? null,
        'createdAt' => $row['created_at'] ?? null,
        'updatedAt' => $row['updated_at'] ?? null,
    ];
}

switch ($method) {
    case 'GET':
        $query = 'SELECT id, user_id, fullname, email, role, department, organization, phone, employee_id, status, profile_picture, created_at, updated_at FROM users WHERE 1=1';
        $params = [];

        if ($teamPickerScope) {
            appendTeamMemberScopeFilter($query, $params, $currentUser);
        } else {
            appendUserListScopeFilter($query, $params, $currentUser);
        }

        if (!$teamPickerScope && !empty($_GET['status'])) {
            $query .= ' AND LOWER(status) = LOWER(?)';
            $params[] = $_GET['status'];
        }

        if (!empty($_GET['role'])) {
            $query .= ' AND role = ?';
            $params[] = $_GET['role'];
        }

        if (!empty($_GET['search'])) {
            $query .= ' AND (fullname LIKE ? OR email LIKE ? OR employee_id LIKE ? OR phone LIKE ?)';
            $search = '%' . $_GET['search'] . '%';
            array_push($params, $search, $search, $search, $search);
        }

        $query .= ' ORDER BY created_at DESC LIMIT 500';
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $rows = array_map('userRowToResponse', $stmt->fetchAll());
        sendJSONResponse(['status' => 'success', 'data' => $rows]);

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        $required = ['fullname', 'email', 'password', 'role', 'department', 'organization'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                sendJSONResponse(['status' => 'error', 'message' => "Field '{$field}' is required"], 400);
            }
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            sendJSONResponse(['status' => 'error', 'message' => 'Invalid email format'], 400);
        }

        if (strlen($data['password']) < 8 || !preg_match('/[A-Z]/', $data['password']) || !preg_match('/[0-9]/', $data['password'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Password must be at least 8 characters with uppercase and number'], 400);
        }

        $data['role'] = requireValidRole($data['role']);
        enforceManagedUserPayloadScope($currentUser, $data);

        $department = normalizeRdommsDepartment((string) $data['department']);
        if ($department === null) {
            sendJSONResponse(['status' => 'error', 'message' => 'Invalid department selected'], 400);
        }

        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            sendJSONResponse(['status' => 'error', 'message' => 'Email already registered'], 409);
        }

        $profilePicturePath = null;
        $profilePicture = trim((string)($data['profile_picture'] ?? ''));
        if ($profilePicture !== '') {
            if (!preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,#i', $profilePicture)) {
                sendJSONResponse(['status' => 'error', 'message' => 'Invalid profile picture format'], 400);
            }

            $uploadDir = __DIR__ . '/../uploads/profile-pictures';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                sendJSONResponse(['status' => 'error', 'message' => 'Unable to prepare profile picture storage'], 500);
            }

            [$meta, $base64Data] = explode(',', $profilePicture, 2);
            $binaryData = base64_decode($base64Data, true);
            if ($binaryData === false) {
                sendJSONResponse(['status' => 'error', 'message' => 'Invalid profile picture data'], 400);
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
            if (file_put_contents($uploadDir . '/' . $fileName, $binaryData) === false) {
                sendJSONResponse(['status' => 'error', 'message' => 'Failed to save profile picture'], 500);
            }
            $profilePicturePath = 'uploads/profile-pictures/' . $fileName;
        }

        $userId = 'USR' . time() . rand(100, 999);
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        $status = strtolower((string)($data['status'] ?? 'active'));
        if (!in_array($status, ['active', 'pending', 'suspended'], true)) {
            $status = 'active';
        }

        $stmt = $conn->prepare('INSERT INTO users (user_id, fullname, email, password, role, department, organization, phone, employee_id, profile_picture, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $result = $stmt->execute([
            $userId,
            $data['fullname'],
            $data['email'],
            $hashedPassword,
            $data['role'],
            $department,
            $data['organization'],
            rdomms_encrypt_sensitive(isset($data['phone']) ? (string) $data['phone'] : null),
            rdomms_encrypt_sensitive(isset($data['employeeId']) ? (string) $data['employeeId'] : (isset($data['employee_id']) ? (string) $data['employee_id'] : null)),
            $profilePicturePath,
            $status
        ]);

        if ($result) {
            logActivity($session['user_id'], 'Created user', 'Users', 'success', 'Created user: ' . $data['email']);
            rdomms_dispatch_notification(
                $conn,
                'user_role',
                'New user created',
                'User account created for ' . $data['email'] . ' with role ' . ($data['role'] ?? 'staff') . '.',
                'Users',
                'info',
                null
            );
            sendJSONResponse(['status' => 'success', 'message' => 'User created successfully', 'data' => ['user_id' => $userId, 'profile_picture' => $profilePicturePath]], 201);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Failed to create user'], 500);

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (empty($data['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'User ID required'], 400);
        }

        denyUnlessCanManageUser($conn, $currentUser, (string) $data['id'], 'update');
        enforceManagedUserPayloadScope($currentUser, $data);

        if (isset($data['department'])) {
            $normalizedDepartment = normalizeRdommsDepartment((string) $data['department']);
            if ($normalizedDepartment === null) {
                sendJSONResponse(['status' => 'error', 'message' => 'Invalid department selected'], 400);
            }
            $data['department'] = $normalizedDepartment;
        }

        $allowedFields = ['fullname', 'email', 'role', 'department', 'organization', 'phone', 'employee_id', 'status', 'profile_picture'];
        $updates = [];
        $params = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = ?";
                $value = $data[$field];
                if ($field === 'phone' || $field === 'employee_id') {
                    $value = rdomms_encrypt_sensitive($value !== null ? (string) $value : null);
                }
                $params[] = $value;
            }
        }

        if (isset($data['employeeId'])) {
            $updates[] = 'employee_id = ?';
            $params[] = rdomms_encrypt_sensitive((string) $data['employeeId']);
        }

        if (isset($data['role'])) {
            $data['role'] = requireValidRole($data['role']);
        }

        $previousStmt = $conn->prepare('SELECT fullname, email, role, status FROM users WHERE user_id = ? OR id = ? LIMIT 1');
        $previousStmt->execute([$data['id'], $data['id']]);
        $previousUser = $previousStmt->fetch() ?: [];

        if (!$updates) {
            sendJSONResponse(['status' => 'error', 'message' => 'No fields to update'], 400);
        }

        $params[] = $data['id'];
        $params[] = $data['id'];
        $stmt = $conn->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE user_id = ? OR id = ?');
        $result = $stmt->execute($params);

        if ($result) {
            logActivity($session['user_id'], 'Updated user', 'Users', 'info', 'Updated user ID: ' . $data['id']);

            if (isset($data['role'])) {
                $oldRole = (string) ($previousUser['role'] ?? '');
                $newRole = (string) $data['role'];
                if ($oldRole !== '' && $oldRole !== $newRole) {
                    $label = (string) ($previousUser['email'] ?? $previousUser['fullname'] ?? $data['id']);
                    rdomms_dispatch_notification(
                        $conn,
                        'user_role',
                        'User role updated',
                        sprintf('Role for %s changed from %s to %s.', $label, $oldRole, $newRole),
                        'Users',
                        'info'
                    );

                    $targetStmt = $conn->prepare('SELECT id FROM users WHERE user_id = ? OR id = ? LIMIT 1');
                    $targetStmt->execute([$data['id'], $data['id']]);
                    $targetRow = $targetStmt->fetch();
                    $targetDbId = (int) ($targetRow['id'] ?? 0);
                    if ($targetDbId > 0) {
                        rdomms_notify_user_account(
                            $conn,
                            'user_role',
                            'Your role was updated',
                            sprintf('Your account role changed from %s to %s.', $oldRole, $newRole),
                            'Users',
                            'info',
                            $targetDbId
                        );
                    }
                }
            }

            if (isset($data['status'])) {
                $oldStatus = strtolower((string) ($previousUser['status'] ?? ''));
                $newStatus = strtolower((string) $data['status']);
                if ($oldStatus === 'pending' && $newStatus === 'active') {
                    $targetStmt = $conn->prepare('SELECT id, email FROM users WHERE user_id = ? OR id = ? LIMIT 1');
                    $targetStmt->execute([$data['id'], $data['id']]);
                    $targetRow = $targetStmt->fetch() ?: [];
                    $targetDbId = (int) ($targetRow['id'] ?? 0);
                    if ($targetDbId > 0) {
                        rdomms_notify_user_account(
                            $conn,
                            'user_role',
                            'Account approved',
                            'Your RDOMMS account has been approved. You can now sign in and start using the system.',
                            'Users',
                            'success',
                            $targetDbId
                        );
                    }
                    rdomms_dispatch_notification(
                        $conn,
                        'user_role',
                        'User account approved',
                        sprintf('Account for %s is now active.', (string) ($targetRow['email'] ?? $data['id'])),
                        'Users',
                        'info'
                    );
                }
            }

            sendJSONResponse(['status' => 'success', 'message' => 'User updated successfully']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Failed to update user'], 500);

    case 'DELETE':
        if (empty($_GET['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'User ID required'], 400);
        }

        denyUnlessCanManageUser($conn, $currentUser, (string) $_GET['id'], 'delete');

        $stmt = $conn->prepare('DELETE FROM users WHERE user_id = ? OR id = ?');
        $result = $stmt->execute([$_GET['id'], $_GET['id']]);

        if ($result && $stmt->rowCount() > 0) {
            logActivity($session['user_id'], 'Deleted user', 'Users', 'warning', 'Deleted user ID: ' . $_GET['id']);
            sendJSONResponse(['status' => 'success', 'message' => 'User deleted successfully']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'User not found'], 404);

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
