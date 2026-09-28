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
$scopeUserId = getDataScopeUserId($currentUser);
$method = $_SERVER['REQUEST_METHOD'];

function resolveBeneficiaryInternalId(PDO $conn, $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $stmt = $conn->prepare('SELECT id FROM beneficiaries WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $value]);
    } else {
        $stmt = $conn->prepare('SELECT id FROM beneficiaries WHERE beneficiary_id = ? LIMIT 1');
        $stmt->execute([(string) $value]);
    }

    $row = $stmt->fetch();
    return $row ? (int) $row['id'] : null;
}

function assertBeneficiaryAccessible(PDO $conn, array $user, int $beneficiaryInternalId, ?string $scopeMode): void
{
    $stmt = $conn->prepare('SELECT * FROM beneficiaries WHERE id = ? LIMIT 1');
    $stmt->execute([$beneficiaryInternalId]);
    $beneficiary = $stmt->fetch();

    if (!$beneficiary) {
        sendJSONResponse(['status' => 'error', 'message' => 'Beneficiary not found'], 404);
    }

    if (!beneficiaryMatchesScope($conn, $user, $beneficiary, $scopeMode)) {
        sendJSONResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
    }
}

function serviceSelectSql(): string
{
    return 'SELECT st.id, st.service_id, st.beneficiary_id, st.service_type, st.service_date, st.quantity, st.unit, st.provider, st.location, st.reference, st.description, st.created_by, st.created_at, b.fullname AS beneficiary_name, b.beneficiary_code, b.beneficiary_id AS beneficiary_ref, b.group_count
            FROM service_tracking st
            INNER JOIN beneficiaries b ON b.id = st.beneficiary_id';
}

switch ($method) {
    case 'GET':
        if (!empty($_GET['id'])) {
            $query = serviceSelectSql() . ' WHERE st.service_id = ? OR st.id = ? LIMIT 1';
            $stmt = $conn->prepare($query);
            $stmt->execute([$_GET['id'], $_GET['id']]);
            $service = $stmt->fetch();

            if (!$service) {
                sendJSONResponse(['status' => 'error', 'message' => 'Service record not found'], 404);
            }

            $scopeUserId = getDataScopeUserId($currentUser);
            denyUnlessOwnsRecord($service, 'created_by', $scopeUserId);
            assertBeneficiaryAccessible($conn, $currentUser, (int) $service['beneficiary_id'], $scopeMode);
            sendJSONResponse(['status' => 'success', 'data' => $service]);
        }

        $query = serviceSelectSql() . ' WHERE 1=1';
        $params = [];
        $beneficiaryScopeSql = 'SELECT id FROM beneficiaries WHERE 1=1';
        $beneficiaryScopeParams = [];
        appendBeneficiaryListScopeFilter($beneficiaryScopeSql, $beneficiaryScopeParams, $currentUser, $scopeMode);
        $query .= ' AND st.beneficiary_id IN (' . $beneficiaryScopeSql . ')';
        $params = array_merge($params, $beneficiaryScopeParams);

        if (!empty($_GET['beneficiary_id'])) {
            $beneficiaryInternalId = resolveBeneficiaryInternalId($conn, $_GET['beneficiary_id']);
            if (!$beneficiaryInternalId) {
                sendJSONResponse(['status' => 'success', 'data' => []]);
            }
            $query .= ' AND st.beneficiary_id = ?';
            $params[] = $beneficiaryInternalId;
        }

        if (!empty($_GET['service_type'])) {
            $query .= ' AND st.service_type = ?';
            $params[] = $_GET['service_type'];
        }

        if (!empty($_GET['search'])) {
            $query .= ' AND (st.service_type LIKE ? OR st.location LIKE ? OR st.provider LIKE ? OR st.reference LIKE ? OR st.description LIKE ? OR b.fullname LIKE ? OR b.beneficiary_code LIKE ?)';
            $search = '%' . $_GET['search'] . '%';
            $params = array_merge($params, array_fill(0, 7, $search));
        }

        $query .= ' ORDER BY st.service_date DESC, st.created_at DESC LIMIT 200';
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        sendJSONResponse(['status' => 'success', 'data' => $stmt->fetchAll()]);
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        $beneficiaryInternalId = resolveBeneficiaryInternalId($conn, $data['beneficiaryId'] ?? $data['beneficiary_id'] ?? null);
        $serviceType = trim((string) ($data['serviceType'] ?? $data['service_type'] ?? ''));
        $serviceDate = trim((string) ($data['serviceDate'] ?? $data['service_date'] ?? ''));

        if (!$beneficiaryInternalId || $serviceType === '' || $serviceDate === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Beneficiary, service type, and service date are required'], 400);
        }

        assertBeneficiaryAccessible($conn, $currentUser, $beneficiaryInternalId, $scopeMode);

        $serviceId = 'SRV' . time() . rand(100, 999);
        $stmt = $conn->prepare(
            'INSERT INTO service_tracking (service_id, beneficiary_id, service_type, service_date, quantity, unit, provider, location, reference, description, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $result = $stmt->execute([
            $serviceId,
            $beneficiaryInternalId,
            $serviceType,
            $serviceDate,
            isset($data['quantity']) && $data['quantity'] !== '' ? (int) $data['quantity'] : null,
            $data['unit'] ?? null,
            $data['provider'] ?? null,
            $data['location'] ?? null,
            $data['reference'] ?? null,
            $data['description'] ?? null,
            $session['user_id'],
        ]);

        if ($result) {
            logActivity($session['user_id'], 'Recorded service delivery', 'Service Tracking', 'success', 'Recorded service: ' . $serviceType);
            sendJSONResponse(['status' => 'success', 'message' => 'Service recorded successfully', 'data' => ['service_id' => $serviceId]], 201);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Failed to record service'], 500);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (empty($data['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Service ID required'], 400);
        }

        denyUnlessRecordOwned($conn, 'service_tracking', 'service_id = ? OR id = ?', [$data['id'], $data['id']], 'created_by', $scopeUserId);

        $updates = [];
        $params = [];

        if (array_key_exists('beneficiaryId', $data) || array_key_exists('beneficiary_id', $data)) {
            $beneficiaryInternalId = resolveBeneficiaryInternalId($conn, $data['beneficiaryId'] ?? $data['beneficiary_id']);
            if (!$beneficiaryInternalId) {
                sendJSONResponse(['status' => 'error', 'message' => 'Beneficiary not found'], 400);
            }
            assertBeneficiaryAccessible($conn, $currentUser, $beneficiaryInternalId, $scopeMode);
            $updates[] = 'beneficiary_id = ?';
            $params[] = $beneficiaryInternalId;
        }

        $fieldMap = [
            'serviceType' => 'service_type',
            'service_type' => 'service_type',
            'serviceDate' => 'service_date',
            'service_date' => 'service_date',
            'quantity' => 'quantity',
            'unit' => 'unit',
            'provider' => 'provider',
            'location' => 'location',
            'reference' => 'reference',
            'description' => 'description',
        ];

        foreach ($fieldMap as $inputKey => $column) {
            if (!array_key_exists($inputKey, $data)) {
                continue;
            }

            $alreadyAdded = false;
            foreach ($updates as $updateClause) {
                if ($updateClause === "{$column} = ?") {
                    $alreadyAdded = true;
                    break;
                }
            }
            if ($alreadyAdded) {
                continue;
            }

            $value = $data[$inputKey];
            if ($column === 'quantity') {
                $value = ($value === '' || $value === null) ? null : (int) $value;
            }

            $updates[] = "{$column} = ?";
            $params[] = $value;
        }

        if (!$updates) {
            sendJSONResponse(['status' => 'error', 'message' => 'No fields to update'], 400);
        }

        $params[] = $data['id'];
        $params[] = $data['id'];
        $query = 'UPDATE service_tracking SET ' . implode(', ', $updates) . ' WHERE service_id = ? OR id = ?';
        $stmt = $conn->prepare($query);
        $result = $stmt->execute($params);

        if ($result) {
            logActivity($session['user_id'], 'Updated service delivery', 'Service Tracking', 'info', 'Updated service ID: ' . $data['id']);
            sendJSONResponse(['status' => 'success', 'message' => 'Service updated successfully']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Failed to update service'], 500);
        break;

    case 'DELETE':
        if (empty($_GET['id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Service ID required'], 400);
        }

        denyUnlessRecordOwned($conn, 'service_tracking', 'service_id = ? OR id = ?', [$_GET['id'], $_GET['id']], 'created_by', $scopeUserId);

        $stmt = $conn->prepare('DELETE FROM service_tracking WHERE service_id = ? OR id = ?');
        $result = $stmt->execute([$_GET['id'], $_GET['id']]);

        if ($result && $stmt->rowCount() > 0) {
            logActivity($session['user_id'], 'Deleted service delivery', 'Service Tracking', 'warning', 'Deleted service ID: ' . $_GET['id']);
            sendJSONResponse(['status' => 'success', 'message' => 'Service deleted successfully']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Service record not found'], 404);
        break;

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
