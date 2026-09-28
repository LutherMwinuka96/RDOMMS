<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/departments.php';
require_once __DIR__ . '/project-budget.php';
require_once __DIR__ . '/organization-centers.php';

const RDOMMS_FINANCE_LINK_TYPES = ['project', 'monthly_plan', 'quarterly_plan', 'annual_plan'];

function ensureFinanceLinkSchema(PDO $conn): void
{
    $columns = [
        'linked_type' => 'VARCHAR(32) NULL',
        'linked_id' => 'VARCHAR(64) NULL',
        'linked_label' => 'VARCHAR(255) NULL',
    ];

    foreach (['budget_allocations', 'expenses'] as $table) {
        foreach ($columns as $name => $definition) {
            try {
                $stmt = $conn->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE ?');
                $stmt->execute([$name]);
                if (!$stmt->fetch()) {
                    $conn->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
                }
            } catch (Throwable $e) {
                error_log('ensureFinanceLinkSchema ' . $table . '.' . $name . ': ' . $e->getMessage());
            }
        }
    }
}

function rdomms_normalize_finance_link_type(?string $type): ?string
{
    $normalized = strtolower(trim((string) $type));
    if ($normalized === '' || in_array($normalized, ['general', 'none', 'department'], true)) {
        return null;
    }

    return in_array($normalized, RDOMMS_FINANCE_LINK_TYPES, true) ? $normalized : null;
}

function rdomms_finance_link_label(string $type, array $row): string
{
    switch ($type) {
        case 'project':
            return trim((string) ($row['name'] ?? 'Project'));
        case 'monthly_plan':
            $month = trim((string) ($row['month'] ?? ''));
            $year = trim((string) ($row['year'] ?? ''));
            $objective = trim((string) ($row['objective'] ?? ''));
            return $objective !== ''
                ? "Monthly plan — {$month} {$year}: {$objective}"
                : "Monthly plan — {$month} {$year}";
        case 'quarterly_plan':
            $quarter = trim((string) ($row['quarter'] ?? ''));
            $year = trim((string) ($row['year'] ?? ''));
            $objective = trim((string) ($row['objective'] ?? ''));
            return $objective !== ''
                ? "Quarterly plan — {$quarter} {$year}: {$objective}"
                : "Quarterly plan — {$quarter} {$year}";
        case 'annual_plan':
            $year = trim((string) ($row['year'] ?? ''));
            $objective = trim((string) ($row['objective'] ?? ''));
            return $objective !== ''
                ? "Annual plan — {$year}: {$objective}"
                : "Annual plan — {$year}";
        default:
            return '';
    }
}

function rdomms_fetch_finance_link_row(PDO $conn, string $type, string $id): ?array
{
    $type = rdomms_normalize_finance_link_type($type);
    $id = trim($id);
    if ($type === null || $id === '') {
        return null;
    }

    $queries = [
        'project' => 'SELECT project_id, name, organization, sector, budget, status FROM projects WHERE project_id = ? OR id = ? LIMIT 1',
        'monthly_plan' => 'SELECT plan_id, month, year, department, station, objective, total_budget, approval_status FROM monthly_plans WHERE plan_id = ? OR id = ? LIMIT 1',
        'quarterly_plan' => 'SELECT plan_id, quarter, year, department, station, objective, total_budget, approval_status FROM quarterly_plans WHERE plan_id = ? OR id = ? LIMIT 1',
        'annual_plan' => 'SELECT plan_id, year, department, station, objective, total_budget, approval_status FROM annual_plans WHERE plan_id = ? OR id = ? LIMIT 1',
    ];

    if (!isset($queries[$type])) {
        return null;
    }

    $stmt = $conn->prepare($queries[$type]);
    $stmt->execute([$id, $id]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function rdomms_resolve_finance_link(PDO $conn, ?string $type, ?string $id, ?string $label = null): array
{
    $normalizedType = rdomms_normalize_finance_link_type($type);
    $normalizedId = trim((string) $id);

    if ($normalizedType === null || $normalizedId === '') {
        return [
            'linked_type' => null,
            'linked_id' => null,
            'linked_label' => null,
        ];
    }

    $row = rdomms_fetch_finance_link_row($conn, $normalizedType, $normalizedId);
    if (!$row) {
        sendJSONResponse(['status' => 'error', 'message' => 'Selected project or plan was not found'], 400);
    }

    $resolvedLabel = trim((string) $label);
    if ($resolvedLabel === '') {
        $resolvedLabel = rdomms_finance_link_label($normalizedType, $row);
    }

    $publicId = match ($normalizedType) {
        'project' => (string) ($row['project_id'] ?? $normalizedId),
        default => (string) ($row['plan_id'] ?? $normalizedId),
    };

    return [
        'linked_type' => $normalizedType,
        'linked_id' => $publicId,
        'linked_label' => $resolvedLabel,
        'meta' => $row,
    ];
}

function rdomms_normalize_plan_quarter(?string $quarter): ?string
{
    $value = strtolower(trim((string) $quarter));
    $map = [
        'q1' => 'Q1',
        'q2' => 'Q2',
        'q3' => 'Q3',
        'q4' => 'Q4',
    ];

    return $map[$value] ?? (in_array(strtoupper($value), ['Q1', 'Q2', 'Q3', 'Q4'], true) ? strtoupper($value) : null);
}

function rdomms_fetch_project_expense_totals(PDO $conn, array $projectPublicIds): array
{
    ensureFinanceLinkSchema($conn);
    $totals = [];
    foreach ($projectPublicIds as $id) {
        $totals[(string) $id] = ['approved' => 0.0, 'pending' => 0.0, 'count' => 0];
    }

    if (!$projectPublicIds) {
        return $totals;
    }

    $placeholders = implode(', ', array_fill(0, count($projectPublicIds), '?'));
    $sql = "SELECT linked_id, amount, status
            FROM expenses
            WHERE linked_type = 'project'
              AND linked_id IN ({$placeholders})
              AND status <> 'rejected'";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_values($projectPublicIds));

    foreach ($stmt->fetchAll() ?: [] as $row) {
        $key = (string) ($row['linked_id'] ?? '');
        if ($key === '' || !isset($totals[$key])) {
            continue;
        }
        $amount = (float) ($row['amount'] ?? 0);
        $totals[$key]['count']++;
        if (strtolower((string) ($row['status'] ?? '')) === 'approved') {
            $totals[$key]['approved'] += $amount;
        } else {
            $totals[$key]['pending'] += $amount;
        }
    }

    return $totals;
}

function rdomms_append_funding_filter(string &$sql, array &$params, array $filters, string $stationColumn, string $departmentColumn): void
{
    if (!empty($filters['organization']) || !empty($filters['station'])) {
        $organization = trim((string) ($filters['organization'] ?? $filters['station'] ?? ''));
        $sql .= " AND (LOWER(REPLACE({$stationColumn}, ' ', '_')) = ? OR LOWER({$stationColumn}) = LOWER(?))";
        array_push($params, normalizeOrganizationKey($organization), $organization);
    }

    if (!empty($filters['department'])) {
        $department = trim((string) $filters['department']);
        $normalizedDepartment = normalizeRdommsDepartment($department) ?? $department;
        $departmentLabel = getRdommsDepartmentLabel($normalizedDepartment);
        $sql .= " AND ({$departmentColumn} = ? OR {$departmentColumn} = ? OR LOWER({$departmentColumn}) = LOWER(?))";
        array_push($params, $normalizedDepartment, $departmentLabel, $department);
    }

    if (!empty($filters['year'])) {
        $sql .= ' AND year = ?';
        $params[] = (int) $filters['year'];
    }
}

function rdomms_fetch_funding_sources(PDO $conn, array $user, array $filters = []): array
{
    ensureFinanceLinkSchema($conn);
    ensureProjectBudgetSchema($conn);

    $projects = [];
    $projectSql = "
        SELECT project_id, name, organization, sector, budget, status, funding_source,
               budget_approval_status, budget_reject_reason
        FROM projects
        WHERE status IS NULL OR status IN ('not-started', 'ongoing', 'delayed') OR budget > 0
    ";
    $projectParams = [];
    if (!empty($filters['organization']) || !empty($filters['station'])) {
        $organization = trim((string) ($filters['organization'] ?? $filters['station'] ?? ''));
        $projectSql .= " AND (LOWER(REPLACE(COALESCE(organization, ''), ' ', '_')) = ? OR LOWER(organization) = LOWER(?))";
        array_push($projectParams, normalizeOrganizationKey($organization), $organization);
    }
    if (!empty($filters['department'])) {
        $department = trim((string) $filters['department']);
        $normalizedDepartment = normalizeRdommsDepartment($department) ?? $department;
        $departmentLabel = getRdommsDepartmentLabel($normalizedDepartment);
        $projectSql .= ' AND (sector = ? OR sector = ? OR LOWER(sector) = LOWER(?))';
        array_push($projectParams, $normalizedDepartment, $departmentLabel, $department);
    }
    $projectSql .= ' ORDER BY name ASC LIMIT 250';
    $stmt = $conn->prepare($projectSql);
    $stmt->execute($projectParams);
    $projectRows = $stmt->fetchAll() ?: [];
    $projectIds = array_map(static fn(array $row): string => (string) ($row['project_id'] ?? ''), $projectRows);
    $expenseTotals = rdomms_fetch_project_expense_totals($conn, array_filter($projectIds));

    foreach ($projectRows as $row) {
        $projectId = (string) ($row['project_id'] ?? '');
        $expense = $expenseTotals[$projectId] ?? ['approved' => 0.0, 'pending' => 0.0, 'count' => 0];
        $spentCommitted = (float) $expense['approved'] + (float) $expense['pending'];
        $projects[] = [
            'type' => 'project',
            'id' => $projectId,
            'label' => (string) ($row['name'] ?? 'Project'),
            'subtitle' => trim((string) (($row['organization'] ?? '') . ' · ' . ($row['sector'] ?? ''))),
            'budget' => (float) ($row['budget'] ?? 0),
            'spent' => $spentCommitted,
            'spent_committed' => $spentCommitted,
            'linked_expense_count' => (int) ($expense['count'] ?? 0),
            'organization' => (string) ($row['organization'] ?? ''),
            'department' => (string) ($row['sector'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'budget_approval_status' => rdomms_project_budget_status($row),
            'budget_can_operate' => rdomms_project_can_operate($row),
            'budget_reject_reason' => (string) ($row['budget_reject_reason'] ?? ''),
        ];
    }

    $monthly = [];
    $monthlySql = 'SELECT plan_id, month, year, department, station, objective, total_budget, approval_status FROM monthly_plans WHERE approval_status IS NULL OR approval_status != \'rejected\'';
    $monthlyParams = [];
    rdomms_append_funding_filter($monthlySql, $monthlyParams, $filters, 'station', 'department');
    $monthlySql .= ' ORDER BY year DESC, month DESC LIMIT 250';
    $stmt = $conn->prepare($monthlySql);
    $stmt->execute($monthlyParams);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $monthly[] = [
            'type' => 'monthly_plan',
            'id' => (string) ($row['plan_id'] ?? ''),
            'label' => rdomms_finance_link_label('monthly_plan', $row),
            'subtitle' => trim((string) (($row['station'] ?? '') . ' · ' . ($row['department'] ?? ''))),
            'budget' => (float) ($row['total_budget'] ?? 0),
            'organization' => (string) ($row['station'] ?? ''),
            'department' => (string) ($row['department'] ?? ''),
            'status' => (string) ($row['approval_status'] ?? ''),
            'year' => (int) ($row['year'] ?? 0),
        ];
    }

    $quarterly = [];
    $quarterlySql = 'SELECT plan_id, quarter, year, department, station, objective, total_budget, approval_status FROM quarterly_plans WHERE approval_status IS NULL OR approval_status != \'rejected\'';
    $quarterlyParams = [];
    rdomms_append_funding_filter($quarterlySql, $quarterlyParams, $filters, 'station', 'department');
    if (!empty($filters['quarter'])) {
        $planQuarter = rdomms_normalize_plan_quarter((string) $filters['quarter']);
        if ($planQuarter) {
            $quarterlySql .= ' AND quarter = ?';
            $quarterlyParams[] = $planQuarter;
        }
    }
    $quarterlySql .= ' ORDER BY year DESC, quarter DESC LIMIT 250';
    $stmt = $conn->prepare($quarterlySql);
    $stmt->execute($quarterlyParams);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $quarterly[] = [
            'type' => 'quarterly_plan',
            'id' => (string) ($row['plan_id'] ?? ''),
            'label' => rdomms_finance_link_label('quarterly_plan', $row),
            'subtitle' => trim((string) (($row['station'] ?? '') . ' · ' . ($row['department'] ?? ''))),
            'budget' => (float) ($row['total_budget'] ?? 0),
            'organization' => (string) ($row['station'] ?? ''),
            'department' => (string) ($row['department'] ?? ''),
            'status' => (string) ($row['approval_status'] ?? ''),
            'year' => (int) ($row['year'] ?? 0),
            'quarter' => (string) ($row['quarter'] ?? ''),
        ];
    }

    $annual = [];
    $annualSql = 'SELECT plan_id, year, department, station, objective, total_budget, approval_status FROM annual_plans WHERE approval_status IS NULL OR approval_status != \'rejected\'';
    $annualParams = [];
    rdomms_append_funding_filter($annualSql, $annualParams, $filters, 'station', 'department');
    $annualSql .= ' ORDER BY year DESC LIMIT 250';
    $stmt = $conn->prepare($annualSql);
    $stmt->execute($annualParams);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $annual[] = [
            'type' => 'annual_plan',
            'id' => (string) ($row['plan_id'] ?? ''),
            'label' => rdomms_finance_link_label('annual_plan', $row),
            'subtitle' => trim((string) (($row['station'] ?? '') . ' · ' . ($row['department'] ?? ''))),
            'budget' => (float) ($row['total_budget'] ?? 0),
            'organization' => (string) ($row['station'] ?? ''),
            'department' => (string) ($row['department'] ?? ''),
            'status' => (string) ($row['approval_status'] ?? ''),
            'year' => (int) ($row['year'] ?? 0),
        ];
    }

    return [
        'projects' => $projects,
        'monthly_plans' => $monthly,
        'quarterly_plans' => $quarterly,
        'annual_plans' => $annual,
        'flat' => array_merge($projects, $monthly, $quarterly, $annual),
    ];
}

function rdomms_finance_link_finance_columns(): array
{
    return ['linked_type', 'linked_id', 'linked_label'];
}
