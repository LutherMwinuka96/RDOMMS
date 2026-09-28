<?php
require_once __DIR__ . '/../config/db.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireRole(['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff'], $session);
} elseif (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'], true)) {
    requireRole(['admin', 'head_of_organization_center', 'head_of_department', 'field_officer'], $session);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

$currentUser = getAuthenticatedUser($session);
$scopeMode = getScopeModeFromRequest($currentUser);
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (!empty($_GET['id'])) {
            $stmt = $conn->prepare('SELECT * FROM beneficiaries WHERE beneficiary_id = ? OR id = ? LIMIT 1');
            $stmt->execute([$_GET['id'], $_GET['id']]);
            $beneficiary = $stmt->fetch();

            if ($beneficiary) {
                denyUnlessBeneficiaryInScope($conn, $currentUser, $_GET['id'], $scopeMode);
                sendJSONResponse(['status' => 'success', 'data' => rdomms_hydrate_beneficiary_sensitive_fields($beneficiary)]);
            }

            sendJSONResponse(['status' => 'error', 'message' => 'Beneficiary not found'], 404);
        }

        $query = 'SELECT * FROM beneficiaries WHERE 1=1';
        $params = [];
        appendBeneficiaryListScopeFilter($query, $params, $currentUser, $scopeMode);

        if (!empty($_GET['category'])) {
            $query .= ' AND category = ?';
            $params[] = $_GET['category'];
        }

        if (!empty($_GET['status'])) {
            $query .= ' AND status = ?';
            $params[] = $_GET['status'];
        }

        if (!empty($_GET['search'])) {
            $query .= ' AND (fullname LIKE ? OR beneficiary_code LIKE ? OR location LIKE ?)';
            $search = '%' . $_GET['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $query .= ' ORDER BY created_at DESC LIMIT 100';
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $rows = array_map('rdomms_hydrate_beneficiary_sensitive_fields', $stmt->fetchAll());
        sendJSONResponse(['status' => 'success', 'data' => $rows]);

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (empty($data['fullname']) || empty($data['category'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Fullname and category are required'], 400);
        }

        $beneficiaryId = 'BEN' . time() . rand(100, 999);
        $beneficiaryCode = !empty($data['beneficiaryCode']) ? $data['beneficiaryCode'] : ('BEN-' . date('Ymd') . rand(1000, 9999));

        $stmt = $conn->prepare(
            'INSERT INTO beneficiaries (beneficiary_id, fullname, beneficiary_code, gender, age, category, group_count, status, phone, national_id, location, district, ward, household_size, registered_date, assigned_officer, services_needed, notes, registered_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $result = $stmt->execute([
            $beneficiaryId,
            $data['fullname'],
            $beneficiaryCode,
            $data['gender'] ?? 'other',
            $data['age'] ?? null,
            $data['category'],
            $data['groupCount'] ?? 1,
            $data['status'] ?? 'pending',
            rdomms_encrypt_sensitive(isset($data['phone']) ? (string) $data['phone'] : null),
            rdomms_encrypt_sensitive(isset($data['nationalId']) ? (string) $data['nationalId'] : null),
            $data['location'] ?? null,
            $data['district'] ?? null,
            $data['ward'] ?? null,
            $data['householdSize'] ?? null,
            $data['registeredDate'] ?? date('Y-m-d'),
            $data['assignedOfficer'] ?? null,
            $data['servicesNeeded'] ?? null,
            $data['notes'] ?? null,
            $session['user_id']
        ]);

        if ($result) {
            logActivity($session['user_id'], 'Created beneficiary', 'Beneficiaries', 'success', 'Created beneficiary: ' . $data['fullname']);
            sendJSONResponse(['status' => 'success', 'message' => 'Beneficiary created successfully', 'data' => ['beneficiary_id' => $beneficiaryId]], 201);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Failed to create beneficiary'], 500);

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (empty($data['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Beneficiary ID required'], 400);
        }

        denyUnlessBeneficiaryInScope($conn, $currentUser, $data['id'], $scopeMode);

        $updates = [];
        $params = [];
        $allowedFields = ['fullname', 'gender', 'age', 'category', 'group_count', 'status', 'phone', 'national_id', 'location', 'district', 'ward', 'household_size', 'services_needed', 'notes'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = ?";
                $value = $data[$field];
                if ($field === 'phone' || $field === 'national_id') {
                    $value = rdomms_encrypt_sensitive($value !== null ? (string) $value : null);
                }
                $params[] = $value;
            }
        }

        if (!$updates) {
            sendJSONResponse(['status' => 'error', 'message' => 'No fields to update'], 400);
        }

        $params[] = $data['id'];
        $params[] = $data['id'];
        $query = 'UPDATE beneficiaries SET ' . implode(', ', $updates) . ' WHERE beneficiary_id = ? OR id = ?';
        $stmt = $conn->prepare($query);
        $result = $stmt->execute($params);

        if ($result) {
            logActivity($session['user_id'], 'Updated beneficiary', 'Beneficiaries', 'info', 'Updated beneficiary ID: ' . $data['id']);
            sendJSONResponse(['status' => 'success', 'message' => 'Beneficiary updated successfully']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Failed to update beneficiary'], 500);

    case 'DELETE':
        if (empty($_GET['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Beneficiary ID required'], 400);
        }

        denyUnlessBeneficiaryInScope($conn, $currentUser, $_GET['id'], $scopeMode);

        $stmt = $conn->prepare('DELETE FROM beneficiaries WHERE beneficiary_id = ? OR id = ?');
        $result = $stmt->execute([$_GET['id'], $_GET['id']]);

        if ($result && $stmt->rowCount() > 0) {
            logActivity($session['user_id'], 'Deleted beneficiary', 'Beneficiaries', 'warning', 'Deleted beneficiary ID: ' . $_GET['id']);
            sendJSONResponse(['status' => 'success', 'message' => 'Beneficiary deleted successfully']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Beneficiary not found'], 404);

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
