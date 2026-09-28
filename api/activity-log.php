<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$session = verifySession();
if (!$session) {
    sendJSONResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$currentUser = requireRole(
    ['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff', 'human_resource_manager', 'accountant'],
    $session
);

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

function rdomms_activity_log_can_view_all(?array $user): bool
{
    return is_array($user) && userCanViewAllData($user);
}

function rdomms_activity_log_apply_user_scope(string &$query, array &$params, ?array $user): void
{
    if (rdomms_activity_log_can_view_all($user)) {
        return;
    }

    $userId = (int) ($user['id'] ?? 0);
    if ($userId > 0) {
        $query .= ' AND l.user_id = ?';
        $params[] = $userId;
        return;
    }

    $query .= ' AND 1 = 0';
}

function rdomms_system_log_modules(): array
{
    return ['Settings', 'System', 'Auth', 'Notifications', 'Roles', 'Approvals', 'Profile'];
}

function rdomms_activity_log_build_query(array $filters, ?array $user = null): array
{
    $query = 'SELECT l.*, u.fullname AS user_name FROM activity_logs l LEFT JOIN users u ON l.user_id = u.id WHERE 1=1';
    $params = [];

    rdomms_activity_log_apply_user_scope($query, $params, $user);

    if (!empty($filters['scope']) && $filters['scope'] === 'system') {
        $modules = rdomms_system_log_modules();
        $placeholders = implode(',', array_fill(0, count($modules), '?'));
        $query .= " AND l.module IN ($placeholders)";
        $params = array_merge($params, $modules);
    }

    if (!empty($filters['module'])) {
        $query .= ' AND l.module = ?';
        $params[] = $filters['module'];
    }

    if (!empty($filters['severity'])) {
        $query .= ' AND l.severity = ?';
        $params[] = $filters['severity'];
    }

    if (!empty($filters['search'])) {
        $query .= ' AND (l.action LIKE ? OR l.details LIKE ? OR u.fullname LIKE ? OR l.module LIKE ?)';
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    if (!empty($filters['start_date'])) {
        $query .= ' AND DATE(l.created_at) >= ?';
        $params[] = $filters['start_date'];
    }

    if (!empty($filters['end_date'])) {
        $query .= ' AND DATE(l.created_at) <= ?';
        $params[] = $filters['end_date'];
    }

    return [$query, $params];
}

function rdomms_activity_log_stats(PDO $conn, array $filters, ?array $user = null): array
{
    [$baseQuery, $params] = rdomms_activity_log_build_query($filters, $user);
    $countQuery = 'SELECT COUNT(*) AS total FROM (' . $baseQuery . ' ORDER BY l.created_at DESC LIMIT 5000) AS scoped_logs';
    $stmt = $conn->prepare($countQuery);
    $stmt->execute($params);
    $total = (int) (($stmt->fetch()['total'] ?? 0));

    $todayQuery = str_replace('WHERE 1=1', 'WHERE 1=1 AND DATE(l.created_at) = CURDATE()', $baseQuery);
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM (' . $todayQuery . ' LIMIT 5000) AS today_logs');
    $stmt->execute($params);
    $today = (int) (($stmt->fetch()['total'] ?? 0));

    $warnQuery = str_replace('WHERE 1=1', "WHERE 1=1 AND l.severity = 'warning'", $baseQuery);
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM (' . $warnQuery . ' LIMIT 5000) AS warn_logs');
    $stmt->execute($params);
    $warnings = (int) (($stmt->fetch()['total'] ?? 0));

    $criticalQuery = str_replace('WHERE 1=1', "WHERE 1=1 AND l.severity = 'danger'", $baseQuery);
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM (' . $criticalQuery . ' LIMIT 5000) AS critical_logs');
    $stmt->execute($params);
    $critical = (int) (($stmt->fetch()['total'] ?? 0));

    return [
        'total' => $total,
        'today' => $today,
        'warnings' => $warnings,
        'critical' => $critical,
    ];
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $filters = [
            'scope' => $_GET['scope'] ?? '',
            'module' => $_GET['module'] ?? '',
            'severity' => $_GET['severity'] ?? '',
            'search' => $_GET['search'] ?? '',
            'start_date' => $_GET['start_date'] ?? '',
            'end_date' => $_GET['end_date'] ?? '',
        ];

        [$query, $params] = rdomms_activity_log_build_query($filters, $currentUser);
        $query .= ' ORDER BY l.created_at DESC LIMIT 500';
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $response = [
            'status' => 'success',
            'data' => $rows,
            'meta' => [
                'scope' => rdomms_activity_log_can_view_all($currentUser) ? 'all' : 'account',
                'is_admin' => rdomms_activity_log_can_view_all($currentUser),
                'count' => count($rows),
            ],
        ];

        if (!empty($_GET['include_stats']) && $_GET['include_stats'] !== '0') {
            $response['stats'] = rdomms_activity_log_stats($conn, $filters, $currentUser);
        }

        if (!empty($filters['scope']) && $filters['scope'] === 'system') {
            $response['modules'] = rdomms_system_log_modules();
        }

        sendJSONResponse($response);
        break;

    case 'DELETE':
        if (normalizeRoleName($currentUser['role'] ?? '') !== 'admin') {
            sendJSONResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $clear = $_GET['clear'] ?? '';

        if ($clear === 'all' || $clear === 'old') {
            $stmt = $conn->prepare('DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
            $stmt->execute();
            logActivity($session['user_id'], 'Cleared old activity logs', 'System', 'warning', 'Cleared logs older than 30 days');
            sendJSONResponse(['status' => 'success', 'message' => 'Logs older than 30 days were cleared']);
        }

        if ($clear === 'system_old') {
            $modules = rdomms_system_log_modules();
            $placeholders = implode(',', array_fill(0, count($modules), '?'));
            $stmt = $conn->prepare(
                "DELETE FROM activity_logs
                 WHERE module IN ($placeholders)
                   AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
            $stmt->execute($modules);
            logActivity($session['user_id'], 'Cleared old system logs', 'System', 'warning', 'Cleared system logs older than 30 days');
            sendJSONResponse(['status' => 'success', 'message' => 'System logs older than 30 days were cleared']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Invalid action'], 400);
        break;

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
