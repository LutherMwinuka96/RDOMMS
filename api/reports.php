<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/report-access.php';
require_once __DIR__ . '/report-helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$session = verifySession();
if (!$session) {
    sendJSONResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$readRoles = getRolesWithGenerateReportsAccess();
$readRoles[] = 'admin';
$readRoles = array_values(array_unique($readRoles));
$writeRoles = $readRoles;

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

ensureReportTables($conn);

$user = getAuthenticatedUser($session);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    requireRole($readRoles, $session);
} else {
    requireRole($writeRoles, $session);
}

function reportReadJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

function reportMapHistoryRow(array $row): array
{
    return [
        'id' => $row['report_id'],
        'report_id' => $row['report_id'],
        'reportType' => $row['report_type'],
        'report_type' => $row['report_type'],
        'title' => $row['title'] ?? '',
        'generatedAt' => $row['generated_at'] ?? null,
        'generated_at' => $row['generated_at'] ?? null,
        'period' => $row['period'] ?? '',
        'createdAt' => $row['created_at'] ?? null,
    ];
}

function reportMapScheduleRow(array $row): array
{
    return [
        'id' => $row['schedule_id'],
        'schedule_id' => $row['schedule_id'],
        'reportType' => $row['report_type'],
        'report_type' => $row['report_type'],
        'startDate' => $row['start_date'] ?? null,
        'start_date' => $row['start_date'] ?? null,
        'endDate' => $row['end_date'] ?? null,
        'end_date' => $row['end_date'] ?? null,
        'frequency' => $row['frequency'] ?? 'monthly',
        'active' => !empty($row['active']),
        'createdAt' => $row['created_at'] ?? null,
    ];
}

try {
    if ($method === 'GET' && $action === 'types') {
        $access = buildReportAccessClientConfig($user);
        sendJSONResponse([
            'status' => 'success',
            'data' => $access,
        ]);
    }

    if ($method === 'GET' && $action === 'summary') {
        $ctx = reportFetchContext($conn, $user);
        $historyCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM report_history')->fetch()['total'] ?? 0);
        $scheduledCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM scheduled_reports WHERE active = 1')->fetch()['total'] ?? 0);
        $latest = $conn->query('SELECT generated_at FROM report_history ORDER BY generated_at DESC LIMIT 1')->fetch();

        sendJSONResponse([
            'status' => 'success',
            'data' => [
                'projects' => count($ctx['projects']),
                'beneficiaries' => count($ctx['beneficiaries']),
                'historyCount' => $historyCount,
                'scheduledCount' => $scheduledCount,
                'lastGeneratedAt' => $latest['generated_at'] ?? null,
            ],
        ]);
    }

    if ($method === 'GET' && $action === 'plans') {
        $ctx = reportFetchContext($conn, $user);
        sendJSONResponse([
            'status' => 'success',
            'data' => [
                'monthlyPlans' => array_slice($ctx['monthlyPlans'], 0, 5),
                'quarterlyPlans' => array_slice($ctx['quarterlyPlans'], 0, 5),
                'annualPlans' => array_slice($ctx['annualPlans'], 0, 5),
                'counts' => [
                    'monthly' => count($ctx['monthlyPlans']),
                    'quarterly' => count($ctx['quarterlyPlans']),
                    'annual' => count($ctx['annualPlans']),
                ],
            ],
        ]);
    }

    if ($method === 'GET' && $action === 'variance') {
        if (!roleCanAccessVarianceAnalysis($user)) {
            sendJSONResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $ctx = reportFetchContext($conn, $user);
        $targets = [];

        if (reportTableExists($conn, 'targets')) {
            try {
                ensurePlanningSchema($conn);
            } catch (Throwable $e) {
                error_log('ensurePlanningSchema variance: ' . $e->getMessage());
            }

            require_once __DIR__ . '/../config/planning-access.php';
            $sql = 'SELECT * FROM targets WHERE 1=1';
            $params = [];
            appendPlanningListScopeFilter($sql, $params, $user, '', [
                'has_department' => false,
                'has_station' => false,
            ]);
            $sql .= ' ORDER BY updated_at DESC LIMIT 200';
            $targets = array_map(
                'mapTargetRowToClient',
                reportSafeFetchAll($conn, $sql, $params)
            );
        }

        sendJSONResponse([
            'status' => 'success',
            'data' => [
                'allocations' => $ctx['budgets'],
                'expenses' => $ctx['expenses'],
                'projects' => $ctx['projects'],
                'monthlyPlans' => $ctx['monthlyPlans'],
                'quarterlyPlans' => $ctx['quarterlyPlans'],
                'annualPlans' => $ctx['annualPlans'],
                'targets' => $targets,
                'counts' => [
                    'allocations' => count($ctx['budgets']),
                    'expenses' => count($ctx['expenses']),
                    'projects' => count($ctx['projects']),
                    'targets' => count($targets),
                ],
            ],
        ]);
    }

    if ($method === 'GET' && $action === 'generate') {
        require_once __DIR__ . '/../config/organization-centers.php';
        require_once __DIR__ . '/../config/departments.php';

        $type = trim((string) ($_GET['report_type'] ?? $_GET['type'] ?? ''));
        $startDate = trim((string) ($_GET['start_date'] ?? ''));
        $endDate = trim((string) ($_GET['end_date'] ?? ''));
        $stationRaw = trim((string) ($_GET['station'] ?? ''));
        $departmentRaw = trim((string) ($_GET['department'] ?? ''));
        $station = $stationRaw !== '' ? (normalizeRdommsOrganizationCenter($stationRaw) ?? $stationRaw) : '';
        $department = $departmentRaw !== '' ? (normalizeRdommsDepartment($departmentRaw) ?? $departmentRaw) : '';
        [$station, $department] = reportApplyDefaultFiltersForUser($user, $station, $department);

        if ($type === '' || $startDate === '' || $endDate === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Report type and date range are required'], 400);
        }
        if ($startDate > $endDate) {
            sendJSONResponse(['status' => 'error', 'message' => 'Start date cannot be after end date'], 400);
        }

        assertRoleCanGenerateReportType($user, $type);

        $ctx = reportFetchContext($conn, $user);
        $report = reportBuildData($type, $startDate, $endDate, $station, $department, $ctx);

        $historyCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM report_history')->fetch()['total'] ?? 0);
        $scheduledCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM scheduled_reports WHERE active = 1')->fetch()['total'] ?? 0);
        $report['stats'] = [
            'projects' => count($ctx['projects']),
            'beneficiaries' => count($ctx['beneficiaries']),
            'historyCount' => $historyCount,
            'scheduledCount' => $scheduledCount,
        ];

        sendJSONResponse(['status' => 'success', 'data' => $report]);
    }

    if ($method === 'GET' && $action === 'history') {
        $rows = $conn->query('SELECT * FROM report_history ORDER BY generated_at DESC LIMIT 100')->fetchAll() ?: [];
        $allowedTypes = getAllowedReportTypesForRole((string) ($user['role'] ?? ''));
        $filtered = array_values(array_filter(
            $rows,
            static function (array $row) use ($allowedTypes): bool {
                $type = strtolower(trim((string) ($row['report_type'] ?? '')));
                return $type !== '' && in_array($type, $allowedTypes, true);
            }
        ));
        sendJSONResponse([
            'status' => 'success',
            'data' => array_map('reportMapHistoryRow', $filtered),
        ]);
    }

    if ($method === 'GET' && $action === 'schedules') {
        $rows = $conn->query('SELECT * FROM scheduled_reports ORDER BY created_at DESC LIMIT 100')->fetchAll() ?: [];
        $allowedTypes = getAllowedReportTypesForRole((string) ($user['role'] ?? ''));
        $filtered = array_values(array_filter(
            $rows,
            static function (array $row) use ($allowedTypes): bool {
                $type = strtolower(trim((string) ($row['report_type'] ?? '')));
                return $type !== '' && in_array($type, $allowedTypes, true);
            }
        ));
        sendJSONResponse([
            'status' => 'success',
            'data' => array_map('reportMapScheduleRow', $filtered),
        ]);
    }

    if ($method === 'POST' && $action === 'history') {
        $body = reportReadJsonBody();
        $reportType = trim((string) ($body['reportType'] ?? $body['report_type'] ?? ''));
        $title = trim((string) ($body['title'] ?? ''));
        $period = trim((string) ($body['period'] ?? ''));

        if ($reportType === '' || $title === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Report type and title are required'], 400);
        }

        assertRoleCanGenerateReportType($user, $reportType);

        $reportId = 'RPT' . time() . random_int(100, 999);
        $stmt = $conn->prepare(
            'INSERT INTO report_history (report_id, report_type, title, period) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$reportId, $reportType, $title, $period]);

        sendJSONResponse([
            'status' => 'success',
            'message' => 'Report saved to history',
            'data' => [
                'id' => $reportId,
                'reportType' => $reportType,
                'title' => $title,
                'period' => $period,
                'generatedAt' => gmdate('c'),
            ],
        ], 201);
    }

    if ($method === 'POST' && $action === 'schedule') {
        $body = reportReadJsonBody();
        $reportType = trim((string) ($body['reportType'] ?? $body['report_type'] ?? ''));
        $startDate = trim((string) ($body['startDate'] ?? $body['start_date'] ?? ''));
        $endDate = trim((string) ($body['endDate'] ?? $body['end_date'] ?? ''));
        $frequency = trim((string) ($body['frequency'] ?? 'monthly'));

        if ($reportType === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Report type is required'], 400);
        }

        assertRoleCanGenerateReportType($user, $reportType);

        $scheduleId = 'SCH' . time() . random_int(100, 999);
        $stmt = $conn->prepare(
            'INSERT INTO scheduled_reports (schedule_id, report_type, start_date, end_date, frequency, active) VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $scheduleId,
            $reportType,
            $startDate !== '' ? $startDate : null,
            $endDate !== '' ? $endDate : null,
            $frequency,
        ]);

        sendJSONResponse([
            'status' => 'success',
            'message' => 'Report scheduled',
            'data' => reportMapScheduleRow([
                'schedule_id' => $scheduleId,
                'report_type' => $reportType,
                'start_date' => $startDate ?: null,
                'end_date' => $endDate ?: null,
                'frequency' => $frequency,
                'active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]),
        ], 201);
    }

    if ($method === 'DELETE' && $action === 'schedule') {
        $scheduleId = trim((string) ($_GET['id'] ?? $_GET['schedule_id'] ?? ''));
        if ($scheduleId === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Schedule id is required'], 400);
        }

        $stmt = $conn->prepare('DELETE FROM scheduled_reports WHERE schedule_id = ?');
        $stmt->execute([$scheduleId]);

        sendJSONResponse([
            'status' => 'success',
            'message' => 'Scheduled report removed',
        ]);
    }

    sendJSONResponse(['status' => 'error', 'message' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('Reports API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $detail = $e->getMessage();
    $message = 'Report request failed';
    if ($e instanceof PDOException && $detail !== '') {
        $message = 'Database error while building report. Please ensure the database schema is up to date.';
    }
    sendJSONResponse(['status' => 'error', 'message' => $message, 'detail' => $detail], 500);
}
