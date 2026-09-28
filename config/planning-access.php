<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Roles that may open the Planning module and manage planning records. */
function rdomms_planning_module_roles(): array
{
    return [
        'admin',
        'head_of_organization_center',
        'head_of_department',
        'field_officer',
    ];
}

/** Roles that may approve or reject submitted plans. */
function rdomms_planning_approval_roles(): array
{
    return [
        'admin',
        'head_of_organization_center',
        'head_of_department',
    ];
}

function userCanAccessPlanningModule(?array $user): bool
{
    if (!$user) {
        return false;
    }

    return in_array(
        normalizeRoleName($user['role'] ?? ''),
        rdomms_planning_module_roles(),
        true
    );
}

function userCanApprovePlanning(?array $user): bool
{
    if (!$user) {
        return false;
    }

    return in_array(
        normalizeRoleName($user['role'] ?? ''),
        rdomms_planning_approval_roles(),
        true
    );
}

/** Guard planning *.php pages — redirect non-planning roles to the dashboard. */
function rdomms_require_planning_module_page(): array
{
    $user = rdomms_require_authenticated_page();
    if (!userCanAccessPlanningModule($user)) {
        header('Location: ../RDOMMS.php');
        exit;
    }

    if (userCanViewAllData($user)) {
        $slug = strtolower(pathinfo(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), PATHINFO_FILENAME));
        if ($slug !== 'all-plans') {
            header('Location: all-plans.php');
            exit;
        }
    }

    return $user;
}

/** Row-level scope for planning APIs: admins and heads review team plans; field officers see own records. */
function getPlanningDataScopeUserId(?array $user): ?int
{
    if (!$user) {
        return null;
    }

    $role = normalizeRoleName($user['role'] ?? '');
    if ($role === 'admin') {
        return null;
    }

    if (in_array($role, ['head_of_organization_center', 'head_of_department'], true)) {
        return null;
    }

    $userId = (int) ($user['id'] ?? 0);

    return $userId > 0 ? $userId : null;
}

/** Filter planning lists by role — mirrors project visibility for heads and field officers. */
function appendPlanningListScopeFilter(
    &$sql,
    &$params,
    ?array $user,
    string $tableAlias = '',
    array $options = []
): void {
    if (!$user || userCanViewAllData($user)) {
        return;
    }

    $hasStation = !array_key_exists('has_station', $options) || !empty($options['has_station']);
    $hasDepartment = !array_key_exists('has_department', $options) || !empty($options['has_department']);

    $role = normalizeRoleName($user['role'] ?? '');
    $userId = (int) ($user['id'] ?? 0);
    $prefix = $tableAlias !== '' ? rtrim($tableAlias, '.') . '.' : '';

    if ($role === 'field_officer') {
        $sql .= " AND {$prefix}created_by = ?";
        $params[] = $userId;
        return;
    }

    $organization = trim((string) ($user['organization'] ?? ''));

    if ($role === 'head_of_organization_center' && $organization !== '') {
        require_once __DIR__ . '/organization-centers.php';
        $orgKey = normalizeOrganizationKey($organization);

        $stationClause = '';
        if ($hasStation) {
            $stationKeys = getUserOrganizationStationKeys($user);
            if (!$stationKeys) {
                $stationKeys = [$orgKey];
            }
            $stationKeys = array_values(array_unique(array_filter(array_merge(
                $stationKeys,
                [$orgKey, normalizeRdommsOrganizationCenter($organization) ?? '', getRdommsOrganizationCenterLabel($orgKey)]
            ))));
            $stationPlaceholders = implode(', ', array_fill(0, count($stationKeys), '?'));
            $stationClause = " OR {$prefix}station IN ({$stationPlaceholders})";
        }

        $sql .= " AND (
            {$prefix}created_by = ?
            {$stationClause}
            OR {$prefix}created_by IN (
                SELECT id FROM users
                WHERE LOWER(REPLACE(COALESCE(organization, ''), ' ', '_')) = ?
            )
        )";
        $params[] = $userId;
        if ($hasStation) {
            foreach ($stationKeys as $stationKey) {
                $params[] = $stationKey;
            }
        }
        $params[] = $orgKey;
        return;
    }

    if ($role === 'head_of_department' && $organization !== '' && trim((string) ($user['department'] ?? '')) !== '') {
        require_once __DIR__ . '/departments.php';
        require_once __DIR__ . '/organization-centers.php';
        $department = trim((string) $user['department']);
        $normalizedDepartment = normalizeRdommsDepartment($department) ?? $department;
        $departmentLabel = getRdommsDepartmentLabel($normalizedDepartment);
        $orgKey = normalizeOrganizationKey($organization);

        $departmentClause = '';
        if ($hasDepartment) {
            $departmentClause = "
            OR {$prefix}department = ?
            OR {$prefix}department = ?
            OR LOWER({$prefix}department) = LOWER(?)";
        }

        $sql .= " AND (
            {$prefix}created_by = ?
            {$departmentClause}
            OR {$prefix}created_by IN (
                SELECT id FROM users
                WHERE LOWER(REPLACE(COALESCE(organization, ''), ' ', '_')) = ?
                  AND (department = ? OR department = ? OR LOWER(department) = LOWER(?))
            )
        )";
        $params[] = $userId;
        if ($hasDepartment) {
            array_push($params, $normalizedDepartment, $departmentLabel, $department);
        }
        array_push(
            $params,
            $orgKey,
            $normalizedDepartment,
            $departmentLabel,
            $department
        );
        return;
    }

    $sql .= " AND {$prefix}created_by = ?";
    $params[] = $userId;
}
