<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/planning-access.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? '';
$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendJSONResponse(['success' => false, 'message' => 'Database connection failed'], 500);
}

$requiresSession = in_array($action, ['get_data', 'sync_table'], true);
$session = null;
if ($requiresSession) {
    $session = verifySession();
    if (!$session) {
        sendJSONResponse(['success' => false, 'message' => 'Unauthorized'], 401);
    }
}

$conn->exec("
    CREATE TABLE IF NOT EXISTS scheduled_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        schedule_id VARCHAR(50) UNIQUE NOT NULL,
        report_type VARCHAR(100) NOT NULL,
        start_date DATE,
        end_date DATE,
        frequency VARCHAR(50),
        active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_report_type (report_type),
        INDEX idx_active (active)
    )
");

$conn->exec("
    CREATE TABLE IF NOT EXISTS report_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id VARCHAR(50) UNIQUE NOT NULL,
        report_type VARCHAR(100) NOT NULL,
        title VARCHAR(255),
        generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        period VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_report_type (report_type),
        INDEX idx_generated_at (generated_at)
    )
");

$conn->exec("
    CREATE TABLE IF NOT EXISTS app_storage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        storage_key VARCHAR(190) NOT NULL UNIQUE,
        payload LONGTEXT,
        updated_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_storage_key (storage_key)
    )
");

switch ($action) {
    case 'get_data':
        $user = getAuthenticatedUser($session);
        $scopeUserId = getDataScopeUserId($user);
        $scopeMode = getScopeModeFromRequest($user);
        $canViewFinance = userCanViewFinanceModule($user);
        $staffName = trim((string) ($user['fullname'] ?? ''));
        $data = [];
        $emptyPlans = [
            'monthly_plans' => [],
            'quarterly_plans' => [],
            'annual_plans' => [],
        ];

        require_once __DIR__ . '/config/project-budget.php';
        require_once __DIR__ . '/config/project-deregistration.php';
        ensureProjectBudgetSchema($conn);
        ensureProjectDeregistrationSchema($conn);

        $runScopedQuery = function ($baseSql, $params = []) use ($conn) {
            $stmt = $conn->prepare($baseSql);
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        };

        if ($scopeUserId === null) {
            $data['users'] = $runScopedQuery('SELECT * FROM users ORDER BY id DESC');
        } else {
            $data['users'] = $runScopedQuery('SELECT * FROM users WHERE id = ?', [$scopeUserId]);
        }

        require_once __DIR__ . '/config/departments.php';
        try {
            syncRdommsDepartmentsToDatabase($conn);
        } catch (Throwable $e) {
            error_log('Department sync in get_data failed: ' . $e->getMessage());
        }

        $data['departments'] = $runScopedQuery('SELECT * FROM departments ORDER BY id ASC');

        $beneficiarySql = 'SELECT * FROM beneficiaries WHERE 1=1';
        $beneficiaryParams = [];
        appendBeneficiaryListScopeFilter($beneficiarySql, $beneficiaryParams, $user, $scopeMode);
        $beneficiarySql .= ' ORDER BY id DESC LIMIT 500';
        $data['beneficiaries'] = $runScopedQuery($beneficiarySql, $beneficiaryParams);

        $projectSql = 'SELECT * FROM projects WHERE 1=1';
        $projectParams = [];
        appendProjectListScopeFilter($projectSql, $projectParams, $user, $scopeMode);
        $projectLimit = userCanViewAllData($user) ? 10000 : 500;
        $projectSql .= ' ORDER BY id DESC LIMIT ' . (int) $projectLimit;
        $data['projects'] = $runScopedQuery($projectSql, $projectParams);
        $data['rd_projects'] = $data['projects'];

        if ($canViewFinance) {
            $budgetSql = 'SELECT * FROM budget_allocations WHERE 1=1';
            $budgetParams = [];
            appendFinanceListScopeFilter($budgetSql, $budgetParams, $user);
            $budgetSql .= ' ORDER BY id DESC LIMIT 500';
            $data['budgetAllocations'] = $runScopedQuery($budgetSql, $budgetParams);

            $expenseSql = 'SELECT * FROM expenses WHERE 1=1';
            $expenseParams = [];
            appendFinanceListScopeFilter($expenseSql, $expenseParams, $user);
            $expenseSql .= ' ORDER BY id DESC LIMIT 500';
            $data['expenses'] = $runScopedQuery($expenseSql, $expenseParams);
        } else {
            $data['budgetAllocations'] = [];
            $data['expenses'] = [];
        }

        if ($scopeUserId === null) {
            $data['notifications'] = $runScopedQuery(
                "SELECT * FROM notifications WHERE category IS NULL OR category <> 'system_alert' ORDER BY id DESC LIMIT 500"
            );
            $data['system_alerts'] = $runScopedQuery(
                "SELECT * FROM notifications WHERE category = 'system_alert' ORDER BY id DESC LIMIT 500"
            );
            $data['scheduledReports'] = $runScopedQuery('SELECT * FROM scheduled_reports ORDER BY id DESC LIMIT 200');
            $data['reportHistory'] = $runScopedQuery('SELECT * FROM report_history ORDER BY id DESC LIMIT 500');
            $data['user_activities'] = $runScopedQuery('SELECT * FROM activity_logs ORDER BY id DESC LIMIT 500');
            $data['app_storage'] = $runScopedQuery('SELECT storage_key, payload, updated_at FROM app_storage ORDER BY updated_at DESC LIMIT 2000');
        } else {
            $data['notifications'] = $runScopedQuery(
                "SELECT * FROM notifications
                 WHERE user_id = ?
                   AND (category IS NULL OR category <> 'system_alert')
                 ORDER BY id DESC LIMIT 500",
                [$scopeUserId]
            );
            $data['system_alerts'] = $runScopedQuery(
                "SELECT * FROM notifications
                 WHERE user_id = ?
                   AND category = 'system_alert'
                 ORDER BY id DESC LIMIT 500",
                [$scopeUserId]
            );
            $data['scheduledReports'] = [];
            $data['reportHistory'] = [];
            $data['user_activities'] = $runScopedQuery(
                'SELECT * FROM activity_logs WHERE user_id = ? ORDER BY id DESC LIMIT 500',
                [$scopeUserId]
            );
            $data['app_storage'] = $runScopedQuery(
                'SELECT storage_key, payload, updated_at FROM app_storage WHERE updated_by = ? ORDER BY updated_at DESC LIMIT 2000',
                [$scopeUserId]
            );
        }

        if (userCanAccessPlanningModule($user)) {
            require_once __DIR__ . '/api/planning-helpers.php';
            ensurePlanningSchema($conn);
            $data['plans'] = [];
            foreach (['monthly_plans', 'quarterly_plans', 'annual_plans'] as $planTable) {
                $planSql = "SELECT * FROM {$planTable} WHERE 1=1";
                $planParams = [];
                appendPlanningListScopeFilter($planSql, $planParams, $user);
                $planSql .= ' ORDER BY id DESC LIMIT 200';
                $data['plans'][$planTable] = $runScopedQuery($planSql, $planParams);
            }
        } else {
            $data['plans'] = $emptyPlans;
        }

        sendJSONResponse(['success' => true, 'data' => $data]);

    case 'sync_table':
        $user = getAuthenticatedUser($session);
        if (!$user) {
            sendJSONResponse(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $table = $payload['table'] ?? '';
        $dataValue = $payload['data'] ?? [];
        $rows = is_array($dataValue) ? $dataValue : [];

        $tableMap = [
            'users' => 'users',
            'projects' => 'projects',
            'rd_projects' => 'projects',
            'budgetAllocations' => 'budget_allocations',
            'expenses' => 'expenses',
            'notifications' => 'notifications',
            'system_alerts' => 'notifications',
            'scheduledReports' => 'scheduled_reports',
            'reportHistory' => 'report_history',
            'monthlyPlans' => 'monthly_plans',
            'quarterlyPlans' => 'quarterly_plans',
            'annualPlans' => 'annual_plans',
            'user_activities' => 'activity_logs',
        ];

        if ($table === '') {
            sendJSONResponse(['success' => false, 'message' => 'Table name required'], 400);
        }

        if (isset($tableMap[$table])) {
            rdomms_guard_legacy_sync_table($user, $table);
        }

        $dbTable = $tableMap[$table] ?? null;

        $normalizeDate = function ($value) {
            if (empty($value)) {
                return null;
            }
            $timestamp = strtotime((string) $value);
            return $timestamp ? date('Y-m-d', $timestamp) : null;
        };

        $normalizeDateTime = function ($value) {
            if (empty($value)) {
                return null;
            }
            $timestamp = strtotime((string) $value);
            return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
        };

        $insertRows = function (array $records, callable $mapper) use ($conn, $dbTable) {
            $conn->beginTransaction();
            try {
                $conn->exec("DELETE FROM `$dbTable`");

                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $mapped = $mapper($record);
                    if (empty($mapped)) {
                        continue;
                    }

                    $columns = array_keys($mapped);
                    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                    $columnSql = implode(', ', array_map(fn($col) => "`$col`", $columns));
                    $stmt = $conn->prepare("INSERT INTO `$dbTable` ($columnSql) VALUES ($placeholders)");
                    $stmt->execute(array_values($mapped));
                }

                $conn->commit();
                return true;
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }
        };

        try {
            if (!isset($tableMap[$table])) {
                $payloadValue = $dataValue;

                if ($payloadValue === [] || $payloadValue === null || $payloadValue === '') {
                    $stmt = $conn->prepare('DELETE FROM app_storage WHERE storage_key = ?');
                    $stmt->execute([$table]);
                } else {
                    $stmt = $conn->prepare(
                        'INSERT INTO app_storage (storage_key, payload, updated_by) VALUES (?, ?, ?) 
                         ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP'
                    );
                    $stmt->execute([
                        $table,
                        json_encode($payloadValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $session['user_id'] ?? null
                    ]);
                }

                sendJSONResponse([
                    'success' => true,
                    'message' => 'Storage key synced successfully',
                    'table' => $table,
                    'rows' => ($payloadValue === [] || $payloadValue === null || $payloadValue === '') ? 0 : 1
                ]);
            }

            switch ($table) {
                case 'projects':
                case 'rd_projects':
                    sendJSONResponse([
                        'success' => false,
                        'message' => 'Projects cannot be bulk-synced from browser storage. Use the projects API.',
                    ], 400);

                case 'budgetAllocations':
                    $insertRows($rows, function (array $row) use ($normalizeDateTime) {
                        $allocationId = $row['allocation_id'] ?? $row['id'] ?? null;
                        $department = $row['department'] ?? null;
                        $quarter = strtolower((string) ($row['quarter'] ?? ''));
                        $fiscalYear = $row['fiscal_year'] ?? $row['fiscalYear'] ?? null;

                        if (!$allocationId) {
                            $allocationId = 'ALLOC' . time() . rand(100, 999);
                        }

                        if (!$department || !$quarter || !$fiscalYear) {
                            return [];
                        }

                        return [
                            'allocation_id' => (string) $allocationId,
                            'department' => (string) $department,
                            'quarter' => $quarter,
                            'fiscal_year' => (int) $fiscalYear,
                            'amount' => (float) ($row['amount'] ?? 0),
                            'station' => $row['station'] ?? null,
                            'description' => $row['description'] ?? null,
                            'status' => $row['status'] ?? 'pending',
                            'approved_by' => $row['approved_by'] ?? null,
                            'approved_at' => $normalizeDateTime($row['approved_at'] ?? $row['approvedAt'] ?? null),
                            'created_by' => $row['created_by'] ?? null,
                            'created_at' => $normalizeDateTime($row['created_at'] ?? $row['createdAt'] ?? null),
                            'updated_at' => $normalizeDateTime($row['updated_at'] ?? $row['updatedAt'] ?? null),
                        ];
                    });
                    break;

                case 'expenses':
                    $insertRows($rows, function (array $row) use ($normalizeDate, $normalizeDateTime) {
                        $expenseId = $row['expense_id'] ?? $row['id'] ?? null;
                        $date = $row['expense_date'] ?? $row['date'] ?? $row['expenseDate'] ?? null;
                        $category = $row['category'] ?? null;
                        $department = $row['department'] ?? null;
                        $quarter = strtolower((string) ($row['quarter'] ?? ''));
                        $fiscalYear = $row['fiscal_year'] ?? $row['fiscalYear'] ?? null;

                        if (!$expenseId) {
                            $expenseId = 'EXP' . time() . rand(100, 999);
                        }

                        if (!$date || !$category || !$department || !$quarter || !$fiscalYear) {
                            return [];
                        }

                        return [
                            'expense_id' => (string) $expenseId,
                            'expense_date' => $normalizeDate($date),
                            'category' => (string) $category,
                            'department' => (string) $department,
                            'quarter' => $quarter,
                            'fiscal_year' => (int) $fiscalYear,
                            'amount' => (float) ($row['amount'] ?? 0),
                            'station' => $row['station'] ?? null,
                            'description' => $row['description'] ?? null,
                            'reference' => $row['reference'] ?? null,
                            'status' => $row['status'] ?? 'pending',
                            'approved_by' => $row['approved_by'] ?? null,
                            'approved_at' => $normalizeDateTime($row['approved_at'] ?? $row['approvedAt'] ?? null),
                            'created_by' => $row['created_by'] ?? null,
                            'created_at' => $normalizeDateTime($row['created_at'] ?? $row['createdAt'] ?? null),
                        ];
                    });
                    break;

                case 'notifications':
                case 'system_alerts':
                    $insertRows($rows, function (array $row) use ($normalizeDateTime) {
                        $notificationId = $row['notification_id'] ?? $row['id'] ?? null;
                        $title = $row['title'] ?? null;
                        if (!$notificationId) {
                            $notificationId = 'NOT' . time() . rand(100, 999);
                        }
                        if (!$title) {
                            return [];
                        }

                        $read = $row['is_read'] ?? $row['read'] ?? false;

                        return [
                            'notification_id' => (string) $notificationId,
                            'title' => (string) $title,
                            'message' => $row['message'] ?? $row['desc'] ?? $row['description'] ?? null,
                            'severity' => $row['severity'] ?? 'info',
                            'module' => $row['module'] ?? null,
                            'user_id' => $row['user_id'] ?? null,
                            'is_read' => filter_var($read, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $read,
                            'created_at' => $normalizeDateTime($row['created_at'] ?? $row['createdAt'] ?? null),
                        ];
                    });
                    break;

                case 'user_activities':
                    $insertRows($rows, function (array $row) use ($normalizeDateTime) {
                        return [
                            'user_id' => $row['user_id'] ?? null,
                            'action' => $row['action'] ?? null,
                            'module' => $row['module'] ?? null,
                            'severity' => $row['severity'] ?? 'info',
                            'details' => $row['details'] ?? null,
                            'ip_address' => $row['ip_address'] ?? null,
                            'created_at' => $normalizeDateTime($row['created_at'] ?? $row['createdAt'] ?? null),
                        ];
                    });
                    break;

                case 'scheduledReports':
                    $insertRows($rows, function (array $row) use ($normalizeDateTime) {
                        $scheduleId = $row['id'] ?? $row['schedule_id'] ?? null;
                        if (!$scheduleId) {
                            $scheduleId = 'SR' . time() . rand(100, 999);
                        }
                        $startDate = $row['startDate'] ?? $row['start_date'] ?? null;
                        $endDate = $row['endDate'] ?? $row['end_date'] ?? null;
                        return [
                            'schedule_id' => (string) $scheduleId,
                            'report_type' => $row['reportType'] ?? $row['report_type'] ?? '',
                            'start_date' => $normalizeDate($startDate),
                            'end_date' => $normalizeDate($endDate),
                            'frequency' => $row['frequency'] ?? null,
                            'active' => isset($row['active']) ? (int) (bool) $row['active'] : 1,
                            'created_at' => $normalizeDateTime($row['createdAt'] ?? $row['created_at'] ?? null),
                            'updated_at' => $normalizeDateTime($row['updatedAt'] ?? $row['updated_at'] ?? null),
                        ];
                    });
                    break;

                case 'reportHistory':
                    $insertRows($rows, function (array $row) use ($normalizeDateTime) {
                        $reportId = $row['id'] ?? $row['report_id'] ?? null;
                        if (!$reportId) {
                            $reportId = 'RH' . time() . rand(100, 999);
                        }
                        return [
                            'report_id' => (string) $reportId,
                            'report_type' => $row['reportType'] ?? $row['report_type'] ?? '',
                            'title' => $row['title'] ?? null,
                            'generated_at' => $normalizeDateTime($row['generatedAt'] ?? $row['generated_at'] ?? null),
                            'period' => $row['period'] ?? null,
                            'created_at' => $normalizeDateTime($row['createdAt'] ?? $row['created_at'] ?? null),
                        ];
                    });
                    break;

                case 'monthlyPlans':
                    $insertRows($rows, function (array $row) use ($normalizeDateTime) {
                        $planId = $row['plan_id'] ?? $row['id'] ?? null;
                        $month = $row['month'] ?? null;
                        $year = $row['year'] ?? null;
                        if (!$planId) {
                            $planId = 'MP' . time() . rand(100, 999);
                        }
                        if (!$month || !$year) {
                            return [];
                        }
                        return [
                            'plan_id' => (string) $planId,
                            'month' => (string) $month,
                            'year' => (int) $year,
                            'staff_name' => $row['staff_name'] ?? $row['staffName'] ?? null,
                            'department' => $row['department'] ?? null,
                            'station' => $row['station'] ?? null,
                            'activities' => isset($row['activities']) ? json_encode($row['activities']) : json_encode([]),
                            'total_budget' => (float) ($row['total_budget'] ?? $row['totalBudget'] ?? 0),
                            'approval_status' => $row['approval_status'] ?? $row['approvalStatus'] ?? 'pending',
                            'submitted_at' => $normalizeDateTime($row['submitted_at'] ?? $row['submittedAt'] ?? null),
                        ];
                    });
                    break;

                case 'users':
                    $insertRows($rows, function (array $row) use ($normalizeDateTime) {
                        $userId = $row['user_id'] ?? $row['id'] ?? null;
                        $email = $row['email'] ?? null;
                        $fullname = $row['fullname'] ?? $row['name'] ?? null;
                        if (!$userId) {
                            $userId = 'USR' . time() . rand(100, 999);
                        }
                        if (!$email || !$fullname) {
                            return [];
                        }

                        return [
                            'user_id' => (string) $userId,
                            'fullname' => (string) $fullname,
                            'email' => (string) $email,
                            'password' => $row['password'] ?? '',
                            'role' => $row['role'] ?? 'staff',
                            'department' => $row['department'] ?? null,
                            'organization' => $row['organization'] ?? null,
                            'phone' => $row['phone'] ?? null,
                            'employee_id' => $row['employee_id'] ?? $row['employeeId'] ?? null,
                            'status' => $row['status'] ?? 'active',
                            'profile_picture' => $row['profile_picture'] ?? $row['profilePicture'] ?? null,
                            'created_at' => $normalizeDateTime($row['created_at'] ?? $row['createdAt'] ?? null),
                            'updated_at' => $normalizeDateTime($row['updated_at'] ?? $row['updatedAt'] ?? null),
                        ];
                    });
                    break;

                case 'quarterlyPlans':
                    $insertRows($rows, function (array $row) use ($normalizeDateTime) {
                        $planId = $row['plan_id'] ?? $row['id'] ?? null;
                        $quarter = $row['quarter'] ?? null;
                        $year = $row['year'] ?? null;
                        if (!$planId) {
                            $planId = 'QP' . time() . rand(100, 999);
                        }
                        if (!$quarter || !$year) {
                            return [];
                        }
                        return [
                            'plan_id' => (string) $planId,
                            'quarter' => strtoupper((string) $quarter),
                            'year' => (int) $year,
                            'staff_name' => $row['staff_name'] ?? $row['staffName'] ?? null,
                            'department' => $row['department'] ?? null,
                            'station' => $row['station'] ?? null,
                            'activities' => isset($row['activities']) ? json_encode($row['activities']) : json_encode([]),
                            'total_budget' => (float) ($row['total_budget'] ?? $row['totalBudget'] ?? 0),
                            'approval_status' => $row['approval_status'] ?? $row['approvalStatus'] ?? 'pending',
                            'submitted_at' => $normalizeDateTime($row['submitted_at'] ?? $row['submittedAt'] ?? null),
                        ];
                    });
                    break;

                case 'annualPlans':
                    $insertRows($rows, function (array $row) use ($normalizeDateTime) {
                        $planId = $row['plan_id'] ?? $row['id'] ?? null;
                        $year = $row['year'] ?? null;
                        if (!$planId) {
                            $planId = 'AP' . time() . rand(100, 999);
                        }
                        if (!$year) {
                            return [];
                        }
                        return [
                            'plan_id' => (string) $planId,
                            'year' => (int) $year,
                            'staff_name' => $row['staff_name'] ?? $row['staffName'] ?? null,
                            'department' => $row['department'] ?? null,
                            'station' => $row['station'] ?? null,
                            'activities' => isset($row['activities']) ? json_encode($row['activities']) : json_encode([]),
                            'total_budget' => (float) ($row['total_budget'] ?? $row['totalBudget'] ?? 0),
                            'approval_status' => $row['approval_status'] ?? $row['approvalStatus'] ?? 'pending',
                            'submitted_at' => $normalizeDateTime($row['submitted_at'] ?? $row['submittedAt'] ?? null),
                        ];
                    });
                    break;
            }

            sendJSONResponse([
                'success' => true,
                'message' => 'Table synced successfully',
                'table' => $table,
                'rows' => count($rows)
            ]);
        } catch (Throwable $e) {
            error_log('Sync error for ' . $table . ': ' . $e->getMessage());
            sendJSONResponse([
                'success' => false,
                'message' => 'Failed to sync table',
                'table' => $table
            ], 500);
        }

    default:
        sendJSONResponse(['success' => false, 'message' => 'Unknown action'], 400);
}
