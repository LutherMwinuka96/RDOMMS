<?php
declare(strict_types=1);

require_once __DIR__ . '/departments.php';
require_once __DIR__ . '/organization-centers.php';
require_once __DIR__ . '/db.php';

function rdomms_finance_normalize_quarter(?string $quarter): string
{
    $value = strtolower(trim((string) $quarter));
    if ($value === '') {
        return '';
    }
    if (preg_match('/^q([1-4])$/', $value, $matches)) {
        return 'q' . $matches[1];
    }
    if (preg_match('/^([1-4])$/', $value, $matches)) {
        return 'q' . $matches[1];
    }
    return $value;
}

function rdomms_finance_budget_status(float $allocated, float $spent): array
{
    if ($allocated <= 0 && $spent <= 0) {
        return ['key' => 'no_data', 'label' => 'No budget allocated', 'tone' => 'secondary'];
    }
    if ($allocated <= 0 && $spent > 0) {
        return ['key' => 'unbudgeted', 'label' => 'Unbudgeted spending', 'tone' => 'danger'];
    }

    $ratio = $spent / $allocated;
    if ($ratio > 1) {
        return ['key' => 'over', 'label' => 'Over budget', 'tone' => 'danger'];
    }
    if ($ratio >= 0.85) {
        return ['key' => 'critical', 'label' => 'Near limit', 'tone' => 'warning'];
    }
    if ($ratio >= 0.5) {
        return ['key' => 'on_track', 'label' => 'On track', 'tone' => 'success'];
    }

    return ['key' => 'under', 'label' => 'Under utilized', 'tone' => 'info'];
}

function rdomms_finance_build_row_summary(float $allocated, float $spentApproved, float $spentPending): array
{
    $spentCommitted = $spentApproved + $spentPending;
    $remaining = $allocated - $spentCommitted;
    $utilization = $allocated > 0 ? round(($spentCommitted / $allocated) * 100, 1) : ($spentCommitted > 0 ? 100.0 : 0.0);
    $variance = $allocated - $spentCommitted;

    return [
        'allocated' => round($allocated, 2),
        'spentApproved' => round($spentApproved, 2),
        'spentPending' => round($spentPending, 2),
        'spentCommitted' => round($spentCommitted, 2),
        'remaining' => round($remaining, 2),
        'variance' => round($variance, 2),
        'utilizationPct' => $utilization,
        'status' => rdomms_finance_budget_status($allocated, $spentCommitted),
    ];
}

function rdomms_finance_append_scope(string &$sql, array &$params, ?int $scopeUserId, string $column = 'created_by'): void
{
    if ($scopeUserId !== null) {
        $sql .= " AND {$column} = ?";
        $params[] = $scopeUserId;
    }
}

function rdomms_finance_fetch_allocations(PDO $conn, ?int $scopeUserId, array $filters, ?array $user = null): array
{
    $sql = "SELECT department, quarter, fiscal_year, amount, status, station
            FROM budget_allocations
            WHERE status = 'approved'";
    $params = [];

    if (is_array($user)) {
        appendFinanceListScopeFilter($sql, $params, $user);
    } else {
        rdomms_finance_append_scope($sql, $params, $scopeUserId);
    }

    if (!empty($filters['department'])) {
        $sql .= ' AND department = ?';
        $params[] = $filters['department'];
    }
    if (!empty($filters['quarter'])) {
        $sql .= ' AND quarter = ?';
        $params[] = rdomms_finance_normalize_quarter((string) $filters['quarter']);
    }
    if (!empty($filters['year'])) {
        $sql .= ' AND fiscal_year = ?';
        $params[] = (int) $filters['year'];
    }
    if (!empty($filters['organization'])) {
        $sql .= ' AND station = ?';
        $params[] = normalizeRdommsOrganizationCenter((string) $filters['organization']) ?? $filters['organization'];
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function rdomms_finance_fetch_expenses(PDO $conn, ?int $scopeUserId, array $filters, ?array $user = null): array
{
    $sql = "SELECT department, quarter, fiscal_year, amount, status, description, station
            FROM expenses
            WHERE status IN ('approved', 'pending')";
    $params = [];

    if (is_array($user)) {
        appendFinanceListScopeFilter($sql, $params, $user);
    } else {
        rdomms_finance_append_scope($sql, $params, $scopeUserId);
    }

    if (!empty($filters['department'])) {
        $sql .= ' AND department = ?';
        $params[] = $filters['department'];
    }
    if (!empty($filters['quarter'])) {
        $sql .= ' AND quarter = ?';
        $params[] = rdomms_finance_normalize_quarter((string) $filters['quarter']);
    }
    if (!empty($filters['year'])) {
        $sql .= ' AND fiscal_year = ?';
        $params[] = (int) $filters['year'];
    }
    if (!empty($filters['organization'])) {
        $sql .= ' AND station = ?';
        $params[] = normalizeRdommsOrganizationCenter((string) $filters['organization']) ?? $filters['organization'];
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function rdomms_finance_sum_expenses(array $expenses): array
{
    $approved = 0.0;
    $pending = 0.0;

    foreach ($expenses as $expense) {
        $amount = (float) ($expense['amount'] ?? 0);
        if (($expense['status'] ?? '') === 'approved') {
            $approved += $amount;
        } else {
            $pending += $amount;
        }
    }

    return ['approved' => $approved, 'pending' => $pending];
}

function rdomms_finance_department_rows(array $allocations, array $expenses): array
{
    $rows = [];

    foreach ($allocations as $allocation) {
        $dept = (string) ($allocation['department'] ?? 'Unassigned');
        if (!isset($rows[$dept])) {
            $rows[$dept] = ['name' => $dept, 'allocated' => 0.0, 'approved' => 0.0, 'pending' => 0.0];
        }
        $rows[$dept]['allocated'] += (float) ($allocation['amount'] ?? 0);
    }

    foreach ($expenses as $expense) {
        $dept = (string) ($expense['department'] ?? 'Unassigned');
        if (!isset($rows[$dept])) {
            $rows[$dept] = ['name' => $dept, 'allocated' => 0.0, 'approved' => 0.0, 'pending' => 0.0];
        }
        $amount = (float) ($expense['amount'] ?? 0);
        if (($expense['status'] ?? '') === 'approved') {
            $rows[$dept]['approved'] += $amount;
        } else {
            $rows[$dept]['pending'] += $amount;
        }
    }

    $result = [];
    foreach ($rows as $row) {
        $summary = rdomms_finance_build_row_summary($row['allocated'], $row['approved'], $row['pending']);
        $result[] = array_merge(
            [
                'name' => $row['name'],
                'label' => getRdommsDepartmentLabel($row['name']) ?: $row['name'],
            ],
            $summary
        );
    }

    usort($result, static fn(array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));
    return $result;
}

function rdomms_finance_project_rows(PDO $conn, ?int $scopeUserId, array $filters, array $expenses): array
{
    $sql = 'SELECT id, project_id, name, organization, sector, budget, status FROM projects WHERE 1=1';
    $params = [];
    rdomms_finance_append_scope($sql, $params, $scopeUserId);

    if (!empty($filters['department'])) {
        $sql .= ' AND (sector = ? OR organization = ?)';
        $params[] = $filters['department'];
        $params[] = $filters['department'];
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $projects = $stmt->fetchAll() ?: [];
    $rows = [];

    foreach ($projects as $project) {
        $name = (string) ($project['name'] ?? 'Unnamed project');
        $sector = (string) ($project['sector'] ?? '');
        $allocated = (float) ($project['budget'] ?? 0);
        $approved = 0.0;
        $pending = 0.0;
        $nameKey = strtolower($name);

        foreach ($expenses as $expense) {
            $desc = strtolower((string) ($expense['description'] ?? ''));
            $dept = (string) ($expense['department'] ?? '');
            $matchesProject = ($nameKey !== '' && str_contains($desc, $nameKey))
                || departmentsMatch($dept, $sector)
                || departmentsMatch($dept, (string) ($project['organization'] ?? ''));

            if (!$matchesProject) {
                continue;
            }

            $amount = (float) ($expense['amount'] ?? 0);
            if (($expense['status'] ?? '') === 'approved') {
                $approved += $amount;
            } else {
                $pending += $amount;
            }
        }

        $summary = rdomms_finance_build_row_summary($allocated, $approved, $pending);
        $rows[] = array_merge(
            [
                'name' => $name,
                'label' => $name,
                'projectId' => (string) ($project['project_id'] ?? $project['id'] ?? ''),
                'sector' => $sector,
                'organization' => (string) ($project['organization'] ?? ''),
                'projectStatus' => (string) ($project['status'] ?? ''),
            ],
            $summary
        );
    }

    usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));
    return $rows;
}

function rdomms_finance_organization_center_rows(array $allocations, array $expenses): array
{
    $rows = [];

    foreach (getRdommsOrganizationCenters() as $center) {
        $rows[$center['value']] = [
            'name' => $center['value'],
            'label' => $center['label'],
            'allocated' => 0.0,
            'approved' => 0.0,
            'pending' => 0.0,
        ];
    }

    foreach ($allocations as $allocation) {
        $org = normalizeRdommsOrganizationCenter((string) ($allocation['station'] ?? ''));
        if ($org === null || $org === '') {
            continue;
        }
        if (!isset($rows[$org])) {
            $rows[$org] = [
                'name' => $org,
                'label' => getRdommsOrganizationCenterLabel($org),
                'allocated' => 0.0,
                'approved' => 0.0,
                'pending' => 0.0,
            ];
        }
        $rows[$org]['allocated'] += (float) ($allocation['amount'] ?? 0);
    }

    foreach ($expenses as $expense) {
        $org = normalizeRdommsOrganizationCenter((string) ($expense['station'] ?? ''));
        if ($org === null || $org === '') {
            continue;
        }
        if (!isset($rows[$org])) {
            $rows[$org] = [
                'name' => $org,
                'label' => getRdommsOrganizationCenterLabel($org),
                'allocated' => 0.0,
                'approved' => 0.0,
                'pending' => 0.0,
            ];
        }
        $amount = (float) ($expense['amount'] ?? 0);
        if (($expense['status'] ?? '') === 'approved') {
            $rows[$org]['approved'] += $amount;
        } else {
            $rows[$org]['pending'] += $amount;
        }
    }

    $result = [];
    foreach ($rows as $row) {
        if ($row['allocated'] <= 0 && $row['approved'] <= 0 && $row['pending'] <= 0) {
            continue;
        }
        $summary = rdomms_finance_build_row_summary($row['allocated'], $row['approved'], $row['pending']);
        $result[] = array_merge(
            [
                'name' => $row['name'],
                'label' => $row['label'],
            ],
            $summary
        );
    }

    usort($result, static fn(array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));
    return $result;
}

function rdomms_finance_organization_label(PDO $conn, array $user): string
{
    $organization = trim((string) ($user['organization'] ?? ''));
    if ($organization !== '') {
        return $organization;
    }

    if (!function_exists('rdomms_table_exists')) {
        $check = $conn->query("SHOW TABLES LIKE 'organizations'");
        if ($check && $check->fetch()) {
            $row = $conn->query('SELECT name FROM organizations ORDER BY id ASC LIMIT 1')->fetch();
            if (!empty($row['name'])) {
                return (string) $row['name'];
            }
        }
    }

    return 'Organization';
}

function rdomms_finance_build_budget_overview(PDO $conn, ?int $scopeUserId, array $user, array $filters): array
{
    $view = strtolower(trim((string) ($filters['view'] ?? 'department')));
    if (!in_array($view, ['department', 'organization', 'project'], true)) {
        $view = 'department';
    }

    $normalizedFilters = [
        'department' => trim((string) ($filters['department'] ?? '')),
        'organization' => trim((string) ($filters['organization'] ?? '')),
        'quarter' => rdomms_finance_normalize_quarter((string) ($filters['quarter'] ?? '')),
        'year' => trim((string) ($filters['year'] ?? '')),
    ];

    $allocations = rdomms_finance_fetch_allocations($conn, $scopeUserId, $normalizedFilters, $user);
    $expenses = rdomms_finance_fetch_expenses($conn, $scopeUserId, $normalizedFilters, $user);

    $allocatedTotal = array_reduce(
        $allocations,
        static fn(float $sum, array $row): float => $sum + (float) ($row['amount'] ?? 0),
        0.0
    );
    $expenseTotals = rdomms_finance_sum_expenses($expenses);
    $summary = rdomms_finance_build_row_summary(
        $allocatedTotal,
        $expenseTotals['approved'],
        $expenseTotals['pending']
    );

    $departmentRows = rdomms_finance_department_rows($allocations, $expenses);
    $projectRows = rdomms_finance_project_rows($conn, $scopeUserId, $normalizedFilters, $expenses);
    $organizationCenterRows = rdomms_finance_organization_center_rows($allocations, $expenses);

    $organizationRow = array_merge(
        [
            'name' => 'organization',
            'label' => rdomms_finance_organization_label($conn, $user),
        ],
        $summary
    );

    $activeRows = match ($view) {
        'project' => $projectRows,
        'organization' => $organizationCenterRows ?: [$organizationRow],
        default => $departmentRows,
    };

    $selectionLabel = '';
    if ($normalizedFilters['organization'] !== '') {
        $selectionLabel = getRdommsOrganizationCenterLabel($normalizedFilters['organization']);
    }
    if ($normalizedFilters['department'] !== '') {
        $selectionLabel = trim($selectionLabel . ' ' . (getRdommsDepartmentLabel($normalizedFilters['department']) ?: $normalizedFilters['department']));
    }
    if ($normalizedFilters['quarter'] !== '') {
        $selectionLabel = trim($selectionLabel . ' ' . strtoupper($normalizedFilters['quarter']));
    }
    if ($normalizedFilters['year'] !== '') {
        $selectionLabel = trim($selectionLabel . ' ' . $normalizedFilters['year']);
    }
    if ($selectionLabel === '') {
        $selectionLabel = $view === 'organization'
            ? $organizationRow['label']
            : ($view === 'project' ? 'All projects' : 'All departments');
    }

    return [
        'view' => $view,
        'filters' => $normalizedFilters,
        'selectionLabel' => $selectionLabel,
        'organization' => $organizationRow,
        'summary' => $summary,
        'rows' => $activeRows,
        'departments' => $departmentRows,
        'projects' => $projectRows,
        'organizations' => $organizationCenterRows,
        'counts' => [
            'departments' => count($departmentRows),
            'projects' => count($projectRows),
            'allocations' => count($allocations),
            'expenses' => count($expenses),
        ],
    ];
}
