<?php
declare(strict_types=1);

require_once __DIR__ . '/planning-helpers.php';
require_once __DIR__ . '/../config/finance-links.php';

function reportTableExists(PDO $conn, string $table): bool
{
    try {
        $stmt = $conn->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function reportSafeFetchAll(PDO $conn, string $sql, array $params = []): array
{
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('Report fetch skipped: ' . $e->getMessage());
        return [];
    }
}

function ensureReportTables(PDO $conn): void
{
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
}

function reportFormatNumber($value): string
{
    return number_format((float) $value, 0, '.', ',');
}

function reportFormatCurrency($value): string
{
    return 'TSH ' . reportFormatNumber($value);
}

function reportParseDate(?string $value): ?DateTimeImmutable
{
    if (!$value) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $date ?: null;
}

function reportNormalizeText($value): string
{
    return strtolower(trim((string) $value));
}

function reportFilterToken($value): string
{
    return preg_replace('/[\s_\-]+/', '', reportNormalizeText($value));
}

function reportExpandFilterTerms(string $raw, string $kind): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $terms = [$raw];

    if ($kind === 'station') {
        require_once __DIR__ . '/../config/organization-centers.php';
        $normalized = normalizeRdommsOrganizationCenter($raw);
        if ($normalized) {
            $terms[] = $normalized;
            $terms[] = getRdommsOrganizationCenterLabel($normalized);
        } else {
            foreach (getRdommsOrganizationCenters() as $center) {
                if (reportFilterToken($center['value']) === reportFilterToken($raw)
                    || str_contains(reportFilterToken($center['value']), reportFilterToken($raw))) {
                    $terms[] = $center['value'];
                    $terms[] = $center['label'];
                    break;
                }
            }
        }
    } elseif ($kind === 'department') {
        require_once __DIR__ . '/../config/departments.php';
        $normalized = normalizeRdommsDepartment($raw);
        if ($normalized) {
            $terms[] = $normalized;
            $terms[] = getRdommsDepartmentLabel($normalized);
        }
    }

    return array_values(array_unique(array_filter($terms)));
}

function reportPickField(array $record, array $fields)
{
    foreach ($fields as $field) {
        if (!empty($record[$field]) || (isset($record[$field]) && $record[$field] === '0')) {
            return $record[$field];
        }
    }
    return '';
}

function reportParseRecordDate(array $record, array $fields): ?DateTimeImmutable
{
    $value = reportPickField($record, $fields);
    if (!$value) {
        return null;
    }
    try {
        return new DateTimeImmutable((string) $value);
    } catch (Throwable $e) {
        return null;
    }
}

function reportWithinRange(array $record, array $fields, ?DateTimeImmutable $start, ?DateTimeImmutable $end): bool
{
    $date = reportParseRecordDate($record, $fields);
    $hasDateFilter = $start !== null || $end !== null;
    if (!$date) {
        return !$hasDateFilter;
    }
    if ($start && $date < $start) {
        return false;
    }
    if ($end && $date > $end) {
        return false;
    }
    return true;
}

function reportParseMonthValue(string $month, int $year): ?DateTimeImmutable
{
    $month = trim($month);
    if ($month === '') {
        return null;
    }

    if (preg_match('/^(\d{4})-(\d{1,2})/', $month, $match)) {
        $year = (int) $match[1];
        $month = $match[2];
    }

    if (preg_match('/^\d{1,2}$/', $month)) {
        $date = DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%04d-%d-1', $year, (int) $month));
        return $date ?: null;
    }

    $timestamp = strtotime($month . ' 1, ' . $year);
    if ($timestamp !== false) {
        return new DateTimeImmutable(date('Y-m-01', $timestamp));
    }

    return null;
}

function reportPlanPeriodBounds(array $record): ?array
{
    $year = (int) ($record['year'] ?? 0);

    if (!empty($record['month'])) {
        $planYear = $year > 0 ? $year : (int) date('Y');
        $start = reportParseMonthValue((string) $record['month'], $planYear);
        if ($start) {
            $end = $start->modify('last day of this month')->setTime(23, 59, 59);
            return [$start, $end];
        }
    }

    if (!empty($record['quarter'])) {
        $quarterRaw = strtoupper(trim((string) $record['quarter']));
        $quarterNum = 1;
        if (preg_match('/(\d)/', $quarterRaw, $match)) {
            $quarterNum = max(1, min(4, (int) $match[1]));
        }
        $planYear = $year > 0 ? $year : (int) date('Y');
        $startMonth = (($quarterNum - 1) * 3) + 1;
        $start = DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%04d-%d-1', $planYear, $startMonth));
        if ($start) {
            $end = $start->modify('+2 months')->modify('last day of this month')->setTime(23, 59, 59);
            return [$start, $end];
        }
    }

    if ($year > 0) {
        $start = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-01-01', $year));
        $end = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-12-31', $year));
        if ($start && $end) {
            return [$start, $end->setTime(23, 59, 59)];
        }
    }

    return null;
}

function reportRecordInDateRange(array $record, array $dateFields, ?DateTimeImmutable $start, ?DateTimeImmutable $end): bool
{
    if ($start === null && $end === null) {
        return true;
    }

    $date = reportParseRecordDate($record, $dateFields);
    if ($date) {
        if ($start && $date < $start) {
            return false;
        }
        if ($end && $date > $end) {
            return false;
        }
        return true;
    }

    $bounds = reportPlanPeriodBounds($record);
    if ($bounds) {
        [$planStart, $planEnd] = $bounds;
        $rangeStart = $start ?? new DateTimeImmutable('1970-01-01');
        $rangeEnd = $end ?? new DateTimeImmutable('2099-12-31 23:59:59');
        return $planStart <= $rangeEnd && $planEnd >= $rangeStart;
    }

    return false;
}

function reportParseProjectBoundary(?string $value): ?DateTimeImmutable
{
    if ($value === null || $value === '') {
        return null;
    }
    try {
        return new DateTimeImmutable((string) $value);
    } catch (Throwable $e) {
        return null;
    }
}

function reportProjectOverlapsRange(array $project, ?DateTimeImmutable $rangeStart, ?DateTimeImmutable $rangeEnd): bool
{
    if ($rangeStart === null && $rangeEnd === null) {
        return true;
    }

    $activeStatuses = ['ongoing', 'delayed', 'not-started'];
    $status = reportNormalizeText($project['status'] ?? '');
    $isActive = in_array($status, $activeStatuses, true);

    $projectStart = reportParseProjectBoundary($project['startDate'] ?? null);
    $projectEnd = reportParseProjectBoundary($project['endDate'] ?? null)
        ?? reportParseProjectBoundary($project['dueDate'] ?? null);

    $effectiveStart = $rangeStart ?? new DateTimeImmutable('1970-01-01');
    $effectiveEnd = $rangeEnd ?? new DateTimeImmutable('2099-12-31 23:59:59');

    if ($projectEnd === null && $isActive && $projectStart !== null) {
        $projectEnd = $effectiveEnd;
    }

    if ($projectStart === null && $projectEnd === null) {
        $created = reportParseProjectBoundary($project['createdAt'] ?? null);
        if ($created === null) {
            return false;
        }
        return $created >= $effectiveStart && $created <= $effectiveEnd;
    }

    if ($projectStart === null) {
        $projectStart = $projectEnd;
    }
    if ($projectEnd === null) {
        $projectEnd = $projectStart;
    }

    return $projectStart <= $effectiveEnd && $projectEnd >= $effectiveStart;
}

function reportFilterProjectsByScope(array $projects, array $filters): array
{
    return array_values(array_filter($projects, static function ($project) use ($filters) {
        return is_array($project) && reportMatchesFilters($project, $filters);
    }));
}

function reportFilterProjectsForPeriod(array $projects, array $filters): array
{
    $rangeStart = $filters['startDate'] ?? null;
    $rangeEnd = $filters['endDate'] ?? null;

    return array_values(array_filter($projects, static function ($project) use ($rangeStart, $rangeEnd) {
        return is_array($project) && reportProjectOverlapsRange($project, $rangeStart, $rangeEnd);
    }));
}

function reportMatchesValue($value, string $needle): bool
{
    if ($needle === '') {
        return true;
    }
    $haystack = reportNormalizeText($value);
    $term = reportNormalizeText($needle);
    if ($haystack === '') {
        return false;
    }
    if ($haystack === $term) {
        return true;
    }
    if (str_contains($haystack, $term) || str_contains($term, $haystack)) {
        return true;
    }
    $hayTok = reportFilterToken($value);
    $termTok = reportFilterToken($needle);
    return $hayTok !== '' && $termTok !== ''
        && (str_contains($hayTok, $termTok) || str_contains($termTok, $hayTok));
}

function reportMatchesFilters(array $record, array $filters): bool
{
    $station = (string) ($filters['station'] ?? '');
    $department = (string) ($filters['department'] ?? '');
    $stationFields = ['station', 'location', 'district', 'site', 'organization'];
    $departmentFields = ['department', 'departmentName', 'unit', 'section', 'category', 'sector'];

    if ($station !== '') {
        $stationTerms = reportExpandFilterTerms($station, 'station');
        $stationMatch = false;
        foreach ($stationFields as $field) {
            foreach ($stationTerms as $term) {
                if (reportMatchesValue($record[$field] ?? '', $term)) {
                    $stationMatch = true;
                    break 2;
                }
            }
        }
        if (!$stationMatch) {
            return false;
        }
    }

    if ($department !== '') {
        $departmentTerms = reportExpandFilterTerms($department, 'department');
        $departmentMatch = false;
        foreach ($departmentFields as $field) {
            foreach ($departmentTerms as $term) {
                if (reportMatchesValue($record[$field] ?? '', $term)) {
                    $departmentMatch = true;
                    break 2;
                }
            }
        }
        if (!$departmentMatch) {
            return false;
        }
    }

    return true;
}

function reportFilterRecords(array $records, array $filters): array
{
    $dateFields = $filters['dateFields'] ?? [
        'createdAt', 'created_at', 'timestamp', 'date', 'updatedAt', 'updated_at',
        'submittedAt', 'dueDate', 'endDate', 'startDate', 'generatedAt',
        'expenseDate', 'expense_date', 'registered_date',
    ];

    return array_values(array_filter($records, function ($record) use ($filters, $dateFields) {
        if (!is_array($record)) {
            return false;
        }
        if (!reportMatchesFilters($record, $filters)) {
            return false;
        }
        return reportRecordInDateRange(
            $record,
            $dateFields,
            $filters['startDate'] ?? null,
            $filters['endDate'] ?? null
        );
    }));
}

function reportCountBy(array $records, $field): array
{
    $counts = [];
    foreach ($records as $record) {
        $key = reportNormalizeText(is_callable($field) ? $field($record) : ($record[$field] ?? 'unknown'));
        if ($key === '') {
            $key = 'unknown';
        }
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    return $counts;
}

function reportMapRow(array $row, array $map): array
{
    $record = [];
    foreach ($map as $target => $source) {
        $record[$target] = $row[$source] ?? null;
    }
    return $record;
}

function reportNormalizeProject(array $row): array
{
    return [
        'id' => $row['id'] ?? null,
        'project_id' => $row['project_id'] ?? null,
        'projectId' => $row['project_id'] ?? null,
        'name' => $row['name'] ?? '',
        'projectName' => $row['name'] ?? '',
        'status' => $row['status'] ?? 'unknown',
        'progress' => (int) ($row['progress'] ?? 0),
        'budget' => (float) ($row['budget'] ?? 0),
        'beneficiaries' => (int) ($row['beneficiaries'] ?? 0),
        'startDate' => $row['start_date'] ?? null,
        'endDate' => $row['end_date'] ?? null,
        'dueDate' => $row['end_date'] ?? null,
        'location' => $row['location'] ?? '',
        'district' => $row['district'] ?? '',
        'sector' => $row['sector'] ?? $row['department'] ?? '',
        'organization' => $row['organization'] ?? '',
        'station' => $row['station'] ?? ($row['organization'] ?? ''),
        'department' => $row['department'] ?? ($row['sector'] ?? ''),
        'createdAt' => $row['created_at'] ?? null,
        'updatedAt' => $row['updated_at'] ?? null,
    ];
}

function reportNormalizeBeneficiary(array $row): array
{
    return [
        'fullname' => $row['fullname'] ?? '',
        'name' => $row['fullname'] ?? '',
        'status' => $row['status'] ?? 'pending',
        'category' => $row['category'] ?? '',
        'district' => $row['district'] ?? '',
        'location' => $row['location'] ?? '',
        'registered_date' => $row['registered_date'] ?? null,
        'createdAt' => $row['created_at'] ?? null,
        'department' => $row['assigned_officer'] ?? '',
    ];
}

function reportNormalizeUser(array $row): array
{
    return [
        'fullname' => $row['fullname'] ?? '',
        'email' => $row['email'] ?? '',
        'role' => $row['role'] ?? '',
        'status' => $row['status'] ?? '',
        'department' => $row['department'] ?? '',
        'organization' => $row['organization'] ?? '',
        'createdAt' => $row['created_at'] ?? null,
    ];
}

function reportNormalizeExpense(array $row): array
{
    return [
        'expenseDate' => $row['expense_date'] ?? null,
        'expense_date' => $row['expense_date'] ?? null,
        'category' => $row['category'] ?? '',
        'department' => $row['department'] ?? '',
        'quarter' => $row['quarter'] ?? '',
        'fiscalYear' => $row['fiscal_year'] ?? '',
        'fiscal_year' => $row['fiscal_year'] ?? '',
        'amount' => (float) ($row['amount'] ?? 0),
        'status' => $row['status'] ?? '',
        'reference' => $row['reference'] ?? '',
        'description' => $row['description'] ?? '',
        'station' => $row['station'] ?? '',
        'linked_type' => $row['linked_type'] ?? null,
        'linked_id' => $row['linked_id'] ?? null,
        'linked_label' => $row['linked_label'] ?? null,
        'linkedType' => $row['linked_type'] ?? null,
        'linkedId' => $row['linked_id'] ?? null,
        'linkedLabel' => $row['linked_label'] ?? null,
        'createdAt' => $row['created_at'] ?? null,
    ];
}

function reportNormalizeBudget(array $row): array
{
    return [
        'department' => $row['department'] ?? '',
        'quarter' => $row['quarter'] ?? '',
        'fiscalYear' => $row['fiscal_year'] ?? '',
        'fiscal_year' => $row['fiscal_year'] ?? '',
        'amount' => (float) ($row['amount'] ?? 0),
        'status' => $row['status'] ?? '',
        'station' => $row['station'] ?? '',
        'linked_type' => $row['linked_type'] ?? null,
        'linked_id' => $row['linked_id'] ?? null,
        'linked_label' => $row['linked_label'] ?? null,
        'linkedType' => $row['linked_type'] ?? null,
        'linkedId' => $row['linked_id'] ?? null,
        'linkedLabel' => $row['linked_label'] ?? null,
        'createdAt' => $row['created_at'] ?? null,
    ];
}

function reportNormalizeNotification(array $row): array
{
    return [
        'title' => $row['title'] ?? '',
        'subject' => $row['title'] ?? '',
        'module' => $row['module'] ?? '',
        'type' => $row['module'] ?? '',
        'severity' => $row['severity'] ?? 'info',
        'read' => !empty($row['is_read']),
        'createdAt' => $row['created_at'] ?? null,
        'timestamp' => $row['created_at'] ?? null,
    ];
}

function reportNormalizeActivity(array $row): array
{
    return [
        'action' => $row['action'] ?? '',
        'title' => $row['action'] ?? '',
        'module' => $row['module'] ?? '',
        'severity' => $row['severity'] ?? 'info',
        'timestamp' => $row['created_at'] ?? null,
        'createdAt' => $row['created_at'] ?? null,
    ];
}

function reportFetchScoped(PDO $conn, string $sql, array $params, ?int $scopeUserId, string $ownerColumn): array
{
    if ($scopeUserId !== null) {
        $sql .= " AND {$ownerColumn} = ?";
        $params[] = $scopeUserId;
    }
    return reportSafeFetchAll($conn, $sql, $params);
}

function reportFetchContext(PDO $conn, array $user): array
{
    if (function_exists('ensurePlanningSchema')) {
        try {
            ensurePlanningSchema($conn);
        } catch (Throwable $e) {
            error_log('ensurePlanningSchema in reports: ' . $e->getMessage());
        }
    }

    $scopeMode = getScopeModeFromRequest($user);
    $financeScopeUserId = getFinanceDataScopeUserId($user);

    if (function_exists('ensureFinanceLinkSchema')) {
        try {
            ensureFinanceLinkSchema($conn);
        } catch (Throwable $e) {
            error_log('ensureFinanceLinkSchema in reports: ' . $e->getMessage());
        }
    }

    $projectSql = 'SELECT * FROM projects WHERE 1=1';
    $projectParams = [];
    appendProjectListScopeFilter($projectSql, $projectParams, $user, $scopeMode);
    $projectSql .= ' ORDER BY id DESC LIMIT 500';
    $projects = reportTableExists($conn, 'projects')
        ? array_map('reportNormalizeProject', reportSafeFetchAll($conn, $projectSql, $projectParams))
        : [];

    $beneficiarySql = 'SELECT * FROM beneficiaries WHERE 1=1';
    $beneficiaryParams = [];
    appendBeneficiaryListScopeFilter($beneficiarySql, $beneficiaryParams, $user, $scopeMode);
    $beneficiarySql .= ' ORDER BY id DESC LIMIT 500';
    $beneficiaries = reportTableExists($conn, 'beneficiaries')
        ? array_map('reportNormalizeBeneficiary', reportSafeFetchAll($conn, $beneficiarySql, $beneficiaryParams))
        : [];

    if (reportTableExists($conn, 'users')) {
        $userSql = 'SELECT * FROM users WHERE 1=1';
        $userParams = [];
        appendUserListScopeFilter($userSql, $userParams, $user);
        $userSql .= ' ORDER BY id DESC LIMIT 500';
        $users = array_map(
            'reportNormalizeUser',
            reportSafeFetchAll($conn, $userSql, $userParams)
        );
    } else {
        $users = [];
    }

    $expenseSql = 'SELECT * FROM expenses WHERE 1=1';
    $expenseParams = [];
    appendFinanceListScopeFilter($expenseSql, $expenseParams, $user);
    $expenseSql .= ' ORDER BY id DESC LIMIT 500';
    $expenses = reportTableExists($conn, 'expenses')
        ? array_map('reportNormalizeExpense', reportSafeFetchAll($conn, $expenseSql, $expenseParams))
        : [];

    $budgetSql = 'SELECT * FROM budget_allocations WHERE 1=1';
    $budgetParams = [];
    appendFinanceListScopeFilter($budgetSql, $budgetParams, $user);
    $budgetSql .= ' ORDER BY id DESC LIMIT 500';
    $budgets = reportTableExists($conn, 'budget_allocations')
        ? array_map('reportNormalizeBudget', reportSafeFetchAll($conn, $budgetSql, $budgetParams))
        : [];

    $notifications = reportTableExists($conn, 'notifications')
        ? array_map('reportNormalizeNotification', reportSafeFetchAll(
            $conn,
            'SELECT * FROM notifications ORDER BY created_at DESC LIMIT 300'
        ))
        : [];

    $activities = reportTableExists($conn, 'activity_logs')
        ? array_map('reportNormalizeActivity', reportSafeFetchAll(
            $conn,
            'SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 300'
        ))
        : [];

    require_once __DIR__ . '/../config/planning-access.php';

    $monthlyPlans = [];
    $quarterlyPlans = [];
    $annualPlans = [];
    foreach (['monthly' => 'monthlyPlans', 'quarterly' => 'quarterlyPlans', 'annual' => 'annualPlans'] as $type => $key) {
        $table = $type === 'monthly' ? 'monthly_plans' : ($type === 'quarterly' ? 'quarterly_plans' : 'annual_plans');
        if (!reportTableExists($conn, $table)) {
            continue;
        }
        $sql = "SELECT * FROM {$table} WHERE 1=1";
        $params = [];
        appendPlanningListScopeFilter($sql, $params, $user);
        $sql .= ' ORDER BY saved_at DESC, submitted_at DESC, id DESC LIMIT 200';
        $rows = array_map(
            fn ($row) => mapPlanRowToClient($row, $type),
            reportSafeFetchAll($conn, $sql, $params)
        );
        if ($type === 'monthly') {
            $monthlyPlans = $rows;
        } elseif ($type === 'quarterly') {
            $quarterlyPlans = $rows;
        } else {
            $annualPlans = $rows;
        }
    }

    $evidenceCount = 0;
    try {
        if (function_exists('ensurePlanningSchema')) {
            ensurePlanningSchema($conn);
        }
        $evidenceCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM planning_evidence')->fetch()['total'] ?? 0);
    } catch (Throwable $e) {
        $evidenceCount = 0;
    }

    return compact(
        'projects',
        'beneficiaries',
        'users',
        'expenses',
        'budgets',
        'notifications',
        'activities',
        'monthlyPlans',
        'quarterlyPlans',
        'annualPlans',
        'evidenceCount'
    );
}

function reportMakeSection(string $title, array $summary, array $data, string $chartType, array $chartLabels, array $chartData, array $chartColors): array
{
    return compact('title', 'summary', 'data', 'chartType', 'chartLabels', 'chartData', 'chartColors');
}

function reportTopRows(array $records, callable $mapper, int $limit = 10): array
{
    return array_map($mapper, array_slice($records, 0, $limit));
}

function reportTitleForType(string $type): string
{
    $map = [
        'executive' => 'Executive Overview Report',
        'projects' => 'Project Portfolio Report',
        'beneficiaries' => 'Beneficiary Coverage Report',
        'planning' => 'Planning & Delivery Report',
        'finance' => 'Finance Summary Report',
        'users' => 'Users & Access Report',
        'operations' => 'Operations & Alerts Report',
        'master' => 'System Master Report',
    ];
    return $map[reportNormalizeText($type)] ?? 'System Report';
}

function reportBuildFilterMeta(string $type, string $startStr, string $endStr, string $station, string $department): array
{
    require_once __DIR__ . '/../config/organization-centers.php';
    require_once __DIR__ . '/../config/departments.php';

    $stationNorm = $station !== '' ? (normalizeRdommsOrganizationCenter($station) ?? $station) : '';
    $departmentNorm = $department !== '' ? (normalizeRdommsDepartment($department) ?? $department) : '';

    return [
        'reportType' => reportNormalizeText($type),
        'startDate' => $startStr,
        'endDate' => $endStr,
        'station' => $stationNorm,
        'stationLabel' => $stationNorm !== '' ? getRdommsOrganizationCenterLabel($stationNorm) : 'All stations',
        'department' => $departmentNorm,
        'departmentLabel' => $departmentNorm !== '' ? getRdommsDepartmentLabel($departmentNorm) : 'All departments',
    ];
}

function reportCountFilteredRecords(string $type, array $counts): int
{
    return match (reportNormalizeText($type)) {
        'projects' => (int) ($counts['projects'] ?? 0),
        'beneficiaries' => (int) ($counts['beneficiaries'] ?? 0),
        'planning' => (int) ($counts['plans'] ?? 0),
        'finance' => (int) ($counts['expenses'] ?? 0) + (int) ($counts['budgets'] ?? 0),
        'users' => (int) ($counts['users'] ?? 0),
        'operations' => (int) ($counts['notifications'] ?? 0) + (int) ($counts['activities'] ?? 0),
        'executive', 'master' => (int) ($counts['projects'] ?? 0)
            + (int) ($counts['beneficiaries'] ?? 0)
            + (int) ($counts['expenses'] ?? 0)
            + (int) ($counts['budgets'] ?? 0)
            + (int) ($counts['plans'] ?? 0)
            + (int) ($counts['users'] ?? 0)
            + (int) ($counts['notifications'] ?? 0)
            + (int) ($counts['activities'] ?? 0),
        default => 0,
    };
}

function reportBuildData(string $type, string $startStr, string $endStr, string $station, string $department, array $ctx): array
{
    $startDate = reportParseDate($startStr);
    $endDate = reportParseDate($endStr);
    if ($endDate) {
        $endDate = $endDate->setTime(23, 59, 59);
    }

    $baseFilters = [
        'station' => $station,
        'department' => $department,
        'startDate' => $startDate,
        'endDate' => $endDate,
    ];

    $scopeFilters = [
        'station' => $station,
        'department' => $department,
    ];
    $scopedProjects = reportFilterProjectsByScope($ctx['projects'], $scopeFilters);
    $filteredProjects = reportFilterProjectsForPeriod($scopedProjects, $baseFilters);
    $filteredBeneficiaries = reportFilterRecords($ctx['beneficiaries'], array_merge($baseFilters, [
        'dateFields' => ['createdAt', 'registered_date', 'updatedAt'],
    ]));
    $filteredUsers = reportFilterRecords($ctx['users'], array_merge($baseFilters, [
        'dateFields' => ['createdAt', 'updatedAt'],
    ]));
    $filteredExpenses = reportFilterRecords($ctx['expenses'], array_merge($baseFilters, [
        'dateFields' => ['expenseDate', 'expense_date', 'createdAt'],
    ]));
    $filteredBudgets = reportFilterRecords($ctx['budgets'], array_merge($baseFilters, [
        'dateFields' => ['createdAt', 'updatedAt'],
    ]));
    $filteredNotifications = reportFilterRecords($ctx['notifications'], array_merge($baseFilters, [
        'dateFields' => ['createdAt', 'timestamp', 'updatedAt'],
    ]));
    $filteredActivities = reportFilterRecords($ctx['activities'], array_merge($baseFilters, [
        'dateFields' => ['timestamp', 'createdAt', 'date'],
    ]));
    $filteredMonthly = reportFilterRecords($ctx['monthlyPlans'], array_merge($baseFilters, [
        'dateFields' => ['createdAt', 'updatedAt', 'month', 'savedAt', 'submittedAt'],
    ]));
    $filteredQuarterly = reportFilterRecords($ctx['quarterlyPlans'], array_merge($baseFilters, [
        'dateFields' => ['createdAt', 'updatedAt', 'quarter', 'savedAt', 'submittedAt'],
    ]));
    $filteredAnnual = reportFilterRecords($ctx['annualPlans'], array_merge($baseFilters, [
        'dateFields' => ['createdAt', 'updatedAt', 'year', 'savedAt', 'submittedAt'],
    ]));

    $projectStatusCounts = reportCountBy($filteredProjects, 'status');
    $beneficiaryStatusCounts = reportCountBy($filteredBeneficiaries, 'status');
    $roleCounts = reportCountBy($filteredUsers, 'role');
    $userStatusCounts = reportCountBy($filteredUsers, 'status');
    $expenseStatusCounts = reportCountBy($filteredExpenses, 'status');
    $budgetStatusCounts = reportCountBy($filteredBudgets, 'status');

    $totalExpense = array_sum(array_map(fn ($r) => (float) ($r['amount'] ?? 0), $filteredExpenses));
    $totalBudget = array_sum(array_map(fn ($r) => (float) ($r['amount'] ?? 0), $filteredBudgets));
    $totalProjectBudget = array_sum(array_map(fn ($r) => (float) ($r['budget'] ?? 0), $filteredProjects));

    $projectRows = reportTopRows($filteredProjects, fn ($p) => [
        'Project' => $p['name'] ?: 'Project',
        'Status' => $p['status'] ?? 'unknown',
        'Progress' => ((int) ($p['progress'] ?? 0)) . '%',
        'Budget' => reportFormatCurrency($p['budget'] ?? 0),
        'Beneficiaries' => (int) ($p['beneficiaries'] ?? 0),
        'Start' => $p['startDate'] ?: '-',
        'Due' => $p['endDate'] ?: '-',
        'Location' => $p['location'] ?: ($p['district'] ?: '-'),
    ]);

    $beneficiaryRows = reportTopRows($filteredBeneficiaries, fn ($b) => [
        'Name' => $b['fullname'] ?: 'Beneficiary',
        'Status' => $b['status'] ?? 'pending',
        'Category' => $b['category'] ?: '-',
        'District' => $b['district'] ?: ($b['location'] ?: '-'),
        'Registered' => $b['registered_date'] ?: ($b['createdAt'] ?: '-'),
    ]);

    $userRows = reportTopRows($filteredUsers, fn ($u) => [
        'Name' => $u['fullname'] ?: ($u['email'] ?: 'User'),
        'Role' => $u['role'] ?: '-',
        'Status' => $u['status'] ?: '-',
        'Department' => $u['department'] ?: '-',
        'Organization' => $u['organization'] ?: '-',
        'Created' => $u['createdAt'] ?: '-',
    ]);

    $expenseRows = reportTopRows($filteredExpenses, fn ($e) => [
        'Date' => $e['expenseDate'] ?: ($e['createdAt'] ?: '-'),
        'Category' => $e['category'] ?: '-',
        'Department' => $e['department'] ?: '-',
        'Amount' => reportFormatCurrency($e['amount'] ?? 0),
        'Status' => $e['status'] ?: '-',
        'Reference' => $e['reference'] ?: '-',
    ]);

    $budgetRows = reportTopRows($filteredBudgets, fn ($b) => [
        'Department' => $b['department'] ?: '-',
        'Quarter' => $b['quarter'] ?: '-',
        'Year' => (string) ($b['fiscalYear'] ?: '-'),
        'Amount' => reportFormatCurrency($b['amount'] ?? 0),
        'Status' => $b['status'] ?: '-',
        'Station' => $b['station'] ?: '-',
    ]);

    $planningRecords = array_merge($filteredMonthly, $filteredQuarterly, $filteredAnnual);
    $planningRows = reportTopRows($planningRecords, function ($item) {
        $type = !empty($item['month']) ? 'Monthly' : (!empty($item['quarter']) ? 'Quarterly' : 'Annual');
        return [
            'Plan' => $item['objective'] ?: ($item['month'] ?? $item['quarter'] ?? $item['year'] ?? 'Plan'),
            'Type' => $type,
            'Department' => $item['department'] ?: '-',
            'Status' => $item['status'] ?? ($item['approval_status'] ?? '-'),
            'Staff' => $item['staffName'] ?? '-',
            'Updated' => $item['savedAt'] ?? ($item['submittedAt'] ?? ($item['updatedAt'] ?? '-')),
        ];
    }, 12);

    $activityRows = reportTopRows($filteredActivities, fn ($a) => [
        'Activity' => $a['action'] ?: 'Activity',
        'Module' => $a['module'] ?: '-',
        'Severity' => $a['severity'] ?: 'info',
        'Date' => $a['timestamp'] ?: '-',
    ]);

    $notificationRows = reportTopRows($filteredNotifications, fn ($n) => [
        'Title' => $n['title'] ?: 'Notification',
        'Module' => $n['module'] ?: '-',
        'Severity' => $n['severity'] ?: 'info',
        'Status' => !empty($n['read']) ? 'Read' : 'Unread',
        'Date' => $n['createdAt'] ?: '-',
    ]);

    $moduleRows = [
        ['Module' => 'Projects', 'Records' => count($filteredProjects), 'Note' => count($scopedProjects) . ' in portfolio'],
        ['Module' => 'Beneficiaries', 'Records' => count($filteredBeneficiaries), 'Note' => ($beneficiaryStatusCounts['active'] ?? 0) . ' active'],
        ['Module' => 'Finance', 'Records' => count($filteredExpenses) + count($filteredBudgets), 'Note' => reportFormatCurrency($totalExpense)],
        ['Module' => 'Planning', 'Records' => count($planningRecords), 'Note' => count($filteredMonthly) . ' monthly'],
        ['Module' => 'Users', 'Records' => count($filteredUsers), 'Note' => ($roleCounts['admin'] ?? 0) . ' admins'],
        ['Module' => 'Operations', 'Records' => count($filteredNotifications) + count($filteredActivities), 'Note' => count(array_filter($filteredNotifications, fn ($n) => empty($n['read']))) . ' unread'],
    ];

    $systemOverview = reportMakeSection('Executive Snapshot', [
        'Projects' => count($filteredProjects),
        'In Portfolio' => count($scopedProjects),
        'Beneficiaries' => count($filteredBeneficiaries),
        'Users' => count($filteredUsers),
        'Plans' => count($planningRecords),
        'Expenses' => reportFormatCurrency($totalExpense),
        'Budget Allocated' => reportFormatCurrency($totalBudget),
    ], $moduleRows, 'doughnut',
        array_column($moduleRows, 'Module'),
        array_column($moduleRows, 'Records'),
        ['#4a7c28', '#3b9eca', '#e8a838', '#7ab648', '#2d5016', '#e05252']
    );

    $projectSection = reportMakeSection('Project Portfolio', [
        'Matching Filters' => count($filteredProjects),
        'In Portfolio' => count($scopedProjects),
        'Completed' => $projectStatusCounts['completed'] ?? 0,
        'Ongoing' => $projectStatusCounts['ongoing'] ?? 0,
        'Delayed' => $projectStatusCounts['delayed'] ?? 0,
        'Not Started' => $projectStatusCounts['not-started'] ?? 0,
        'Portfolio Budget' => reportFormatCurrency($totalProjectBudget),
    ], $projectRows, 'bar',
        ['Completed', 'Ongoing', 'Delayed', 'Not Started'],
        [
            $projectStatusCounts['completed'] ?? 0,
            $projectStatusCounts['ongoing'] ?? 0,
            $projectStatusCounts['delayed'] ?? 0,
            $projectStatusCounts['not-started'] ?? 0,
        ],
        ['#7ab648', '#3b9eca', '#e8a838', '#e05252']
    );

    $beneficiarySection = reportMakeSection('Beneficiary Coverage', [
        'Total Beneficiaries' => count($filteredBeneficiaries),
        'Active' => $beneficiaryStatusCounts['active'] ?? 0,
        'Pending' => $beneficiaryStatusCounts['pending'] ?? 0,
        'Inactive' => $beneficiaryStatusCounts['inactive'] ?? 0,
    ], $beneficiaryRows, 'doughnut',
        ['Active', 'Pending', 'Inactive'],
        [
            $beneficiaryStatusCounts['active'] ?? 0,
            $beneficiaryStatusCounts['pending'] ?? 0,
            $beneficiaryStatusCounts['inactive'] ?? 0,
        ],
        ['#7ab648', '#e8a838', '#e05252']
    );

    $planningSection = reportMakeSection('Planning & Delivery', [
        'Monthly Plans' => count($filteredMonthly),
        'Quarterly Plans' => count($filteredQuarterly),
        'Annual Plans' => count($filteredAnnual),
        'Evidence Items' => (int) ($ctx['evidenceCount'] ?? 0),
    ], $planningRows, 'bar',
        ['Monthly', 'Quarterly', 'Annual'],
        [count($filteredMonthly), count($filteredQuarterly), count($filteredAnnual)],
        ['#4a7c28', '#3b9eca', '#7ab648']
    );

    $financeSection = reportMakeSection('Finance Summary', [
        'Total Expenses' => reportFormatCurrency($totalExpense),
        'Budget Allocated' => reportFormatCurrency($totalBudget),
        'Variance' => reportFormatCurrency($totalBudget - $totalExpense),
        'Approved Expenses' => $expenseStatusCounts['approved'] ?? 0,
        'Approved Budgets' => $budgetStatusCounts['approved'] ?? 0,
    ], array_slice(array_merge($expenseRows, $budgetRows), 0, 12), 'bar',
        ['Expenses', 'Budget', 'Approved Exp.', 'Approved Budg.'],
        [$totalExpense, $totalBudget, $expenseStatusCounts['approved'] ?? 0, $budgetStatusCounts['approved'] ?? 0],
        ['#e05252', '#3b9eca', '#7ab648', '#4a7c28']
    );

    $userSection = reportMakeSection('Users & Access', [
        'Total Users' => count($filteredUsers),
        'Administrators' => $roleCounts['admin'] ?? 0,
        'Active' => $userStatusCounts['active'] ?? 0,
        'Pending' => $userStatusCounts['pending'] ?? 0,
    ], $userRows, 'bar',
        array_slice(array_keys($roleCounts), 0, 5),
        array_slice(array_values($roleCounts), 0, 5),
        ['#4a7c28', '#3b9eca', '#e8a838', '#7ab648', '#e05252']
    );

    $logSeverity = reportCountBy($filteredActivities, 'severity');
    $operationsSection = reportMakeSection('Operations & Alerts', [
        'Notifications' => count($filteredNotifications),
        'Unread Alerts' => count(array_filter($filteredNotifications, fn ($n) => empty($n['read']))),
        'Activity Logs' => count($filteredActivities),
        'Warnings' => $logSeverity['warning'] ?? 0,
    ], array_slice(array_merge($notificationRows, $activityRows), 0, 12), 'bar',
        ['Unread', 'Logs', 'Warnings', 'Info'],
        [
            count(array_filter($filteredNotifications, fn ($n) => empty($n['read']))),
            count($filteredActivities),
            $logSeverity['warning'] ?? 0,
            $logSeverity['info'] ?? 0,
        ],
        ['#e05252', '#3b9eca', '#e8a838', '#7ab648']
    );

    $sections = match (reportNormalizeText($type)) {
        'executive' => [$systemOverview, $projectSection, $beneficiarySection, $financeSection],
        'projects' => [$projectSection],
        'beneficiaries' => [$beneficiarySection],
        'planning' => [$planningSection],
        'finance' => [$financeSection],
        'users' => [$userSection],
        'operations' => [$operationsSection],
        'master' => [$systemOverview, $projectSection, $beneficiarySection, $planningSection, $financeSection, $userSection, $operationsSection],
        default => [$systemOverview],
    };

    return [
        'title' => reportTitleForType($type),
        'reportType' => reportNormalizeText($type),
        'period' => "{$startStr} to {$endStr}",
        'generatedAt' => gmdate('c'),
        'filters' => reportBuildFilterMeta($type, $startStr, $endStr, $station, $department),
        'recordCount' => reportCountFilteredRecords($type, [
            'projects' => count($filteredProjects),
            'beneficiaries' => count($filteredBeneficiaries),
            'users' => count($filteredUsers),
            'expenses' => count($filteredExpenses),
            'budgets' => count($filteredBudgets),
            'plans' => count($planningRecords),
            'notifications' => count($filteredNotifications),
            'activities' => count($filteredActivities),
        ]),
        'sections' => $sections,
        'stats' => [
            'projects' => count($ctx['projects']),
            'beneficiaries' => count($ctx['beneficiaries']),
            'historyCount' => 0,
            'scheduledCount' => 0,
        ],
    ];
}
