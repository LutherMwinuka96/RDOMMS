<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Report types each role may generate. Admin always receives the full catalog.
 */
function getReportTypeRoleMatrix(): array
{
    return [
        'admin' => [
            'executive',
            'projects',
            'beneficiaries',
            'planning',
            'finance',
            'users',
            'operations',
            'master',
        ],
        'head_of_organization_center' => [
            'executive',
            'projects',
            'beneficiaries',
            'planning',
            'users',
        ],
        'head_of_department' => [
            'projects',
            'beneficiaries',
            'planning',
        ],
        'human_resource_manager' => [
            'users',
        ],
        'accountant' => [
            'finance',
        ],
    ];
}

function getReportTypeLabels(): array
{
    return [
        'executive' => 'Executive Overview',
        'projects' => 'Project Portfolio',
        'beneficiaries' => 'Beneficiary Coverage',
        'planning' => 'Planning & Delivery',
        'finance' => 'Finance Summary',
        'users' => 'Users & Access',
        'operations' => 'Operations & Alerts',
        'master' => 'System Master Report',
    ];
}

function getRolesWithGenerateReportsAccess(): array
{
    return array_keys(getReportTypeRoleMatrix());
}

function getRolesWithVarianceAnalysisAccess(): array
{
    return ['admin', 'accountant'];
}

function getAllowedReportTypesForRole(string $role): array
{
    $role = normalizeRoleName($role);
    $matrix = getReportTypeRoleMatrix();

    return $matrix[$role] ?? [];
}

function roleCanGenerateReportType(string $role, string $type): bool
{
    $type = strtolower(trim($type));
    if ($type === '') {
        return false;
    }

    $allowed = getAllowedReportTypesForRole($role);
    return in_array($type, $allowed, true);
}

function roleCanAccessGenerateReportsPage(?array $user): bool
{
    if (!$user) {
        return false;
    }

    return count(getAllowedReportTypesForRole((string) ($user['role'] ?? ''))) > 0;
}

function roleCanAccessVarianceAnalysis(?array $user): bool
{
    if (!$user) {
        return false;
    }

    $role = normalizeRoleName((string) ($user['role'] ?? ''));
    return in_array($role, getRolesWithVarianceAnalysisAccess(), true);
}

function assertRoleCanGenerateReportType(array $user, string $type): void
{
    if (!roleCanGenerateReportType((string) ($user['role'] ?? ''), $type)) {
        sendJSONResponse([
            'status' => 'error',
            'message' => 'You are not allowed to generate this report type',
        ], 403);
    }
}

function buildReportAccessClientConfig(?array $user): array
{
    $role = normalizeRoleName((string) ($user['role'] ?? ''));

    return [
        'role' => $role,
        'allowedReportTypes' => getAllowedReportTypesForRole($role),
        'canAccessGenerateReports' => roleCanAccessGenerateReportsPage($user),
        'canAccessVarianceAnalysis' => roleCanAccessVarianceAnalysis($user),
        'reportTypeLabels' => getReportTypeLabels(),
        'scopeDefaults' => reportScopeDefaultsForUser($user),
    ];
}

function reportScopeDefaultsForUser(?array $user): array
{
    if (!$user) {
        return [
            'station' => '',
            'department' => '',
            'lockStation' => false,
            'lockDepartment' => false,
        ];
    }

    require_once __DIR__ . '/organization-centers.php';
    require_once __DIR__ . '/departments.php';

    $role = normalizeRoleName((string) ($user['role'] ?? ''));
    $station = '';
    $department = '';
    $lockStation = false;
    $lockDepartment = false;

    if ($role === 'head_of_organization_center') {
        $org = trim((string) ($user['organization'] ?? ''));
        if ($org !== '') {
            $station = normalizeRdommsOrganizationCenter($org) ?? $org;
            $lockStation = true;
        }
    }

    if ($role === 'head_of_department') {
        $org = trim((string) ($user['organization'] ?? ''));
        if ($org !== '') {
            $station = normalizeRdommsOrganizationCenter($org) ?? $org;
        }

        $dept = trim((string) ($user['department'] ?? ''));
        if ($dept !== '') {
            $department = normalizeRdommsDepartment($dept) ?? $dept;
            $lockDepartment = true;
        }
    }

    return [
        'station' => $station,
        'department' => $department,
        'lockStation' => $lockStation,
        'lockDepartment' => $lockDepartment,
    ];
}

/** Apply role-based station/department defaults when the client leaves filters empty. */
function reportApplyDefaultFiltersForUser(array $user, string $station, string $department): array
{
    $defaults = reportScopeDefaultsForUser($user);

    if ($station === '' && ($defaults['station'] ?? '') !== '') {
        $station = (string) $defaults['station'];
    }

    if ($department === '' && ($defaults['department'] ?? '') !== '') {
        $department = (string) $defaults['department'];
    }

    return [$station, $department];
}
