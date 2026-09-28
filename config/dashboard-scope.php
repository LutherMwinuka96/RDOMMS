<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/departments.php';

/**
 * Resolve dashboard KPI scope metadata for the signed-in user.
 */
function resolveDashboardScopeMetadata(array $user, ?string $scopeMode = null): array
{
    $role = normalizeRoleName($user['role'] ?? '');
    $mode = normalizeDataScopeMode($user, $scopeMode);
    $modeLabel = $mode === 'personal' ? 'My records' : 'Team totals';

    if ($role === 'admin') {
        return [
            'type' => 'organization',
            'mode' => 'team',
            'mode_label' => 'Organization-wide',
            'label' => 'Organization-wide',
            'description' => 'All centers, departments, and records',
            'can_toggle' => false,
        ];
    }

    if ($role === 'head_of_organization_center') {
        $organization = trim((string) ($user['organization'] ?? ''));
        $baseLabel = $organization !== '' ? $organization : 'Your organization center';
        return [
            'type' => 'center',
            'mode' => $mode,
            'mode_label' => $modeLabel,
            'label' => $mode === 'personal' ? 'My records · ' . $baseLabel : $baseLabel,
            'description' => $mode === 'personal'
                ? 'Only beneficiaries and projects you registered or own'
                : 'Projects, beneficiaries, and finance for your center',
            'can_toggle' => true,
        ];
    }

    if ($role === 'head_of_department') {
        $department = trim((string) ($user['department'] ?? ''));
        $organization = trim((string) ($user['organization'] ?? ''));
        $deptLabel = $department !== '' ? getRdommsDepartmentLabel(normalizeRdommsDepartment($department) ?? $department) : 'Your department';
        if ($organization !== '') {
            $deptLabel .= ' · ' . $organization;
        }

        return [
            'type' => 'department',
            'mode' => $mode,
            'mode_label' => $modeLabel,
            'label' => $mode === 'personal' ? 'My records · ' . $deptLabel : $deptLabel,
            'description' => $mode === 'personal'
                ? 'Only records you created in your department'
                : 'Department projects, beneficiaries, and team activity',
            'can_toggle' => true,
        ];
    }

    if ($role === 'accountant') {
        return [
            'type' => 'finance',
            'mode' => 'team',
            'mode_label' => 'Finance operations',
            'label' => 'Finance operations',
            'description' => 'Organization finance KPIs and allocations',
            'can_toggle' => false,
        ];
    }

    if ($role === 'human_resource_manager') {
        return [
            'type' => 'hr',
            'mode' => 'team',
            'mode_label' => 'Human resources',
            'label' => 'Human resources',
            'description' => 'Staff records for your organization center',
            'can_toggle' => false,
        ];
    }

    return [
        'type' => 'personal',
        'mode' => 'personal',
        'mode_label' => 'My workspace',
        'label' => 'My workspace',
        'description' => 'Records you created or are assigned to',
        'can_toggle' => false,
    ];
}

function dashboardCanViewFinanceKpis(array $user): bool
{
    $role = normalizeRoleName($user['role'] ?? '');
    return in_array($role, ['admin', 'head_of_organization_center', 'head_of_department', 'accountant'], true);
}

function dashboardCanViewUserKpis(array $user): bool
{
    $role = normalizeRoleName($user['role'] ?? '');
    return in_array($role, ['admin', 'head_of_organization_center', 'human_resource_manager'], true);
}

function appendDashboardProjectScope(string &$sql, array &$params, array $user, ?string $scopeMode = null): void
{
    appendProjectListScopeFilter($sql, $params, $user, $scopeMode);
}

function appendDashboardBeneficiaryScope(string &$sql, array &$params, array $user, ?string $scopeMode = null): void
{
    appendBeneficiaryListScopeFilter($sql, $params, $user, $scopeMode);
}

function appendDashboardFinanceScope(string &$sql, array &$params, array $user, string $ownerColumn = 'created_by'): void
{
    appendFinanceListScopeFilter($sql, $params, $user, $ownerColumn);
}

function appendDashboardUserScope(string &$sql, array &$params, array $user): void
{
    appendTeamMemberScopeFilter($sql, $params, $user);
}
