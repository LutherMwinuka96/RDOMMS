<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Which dashboard KPI cards each role should see.
 * Keys: projects | ongoing | completed | beneficiaries | budget | expenses | progress | users
 */
function getRoleKpiMatrix(): array
{
    return [
        'admin' => [
            'projects', 'ongoing', 'completed', 'beneficiaries',
            'budget', 'expenses', 'progress', 'users',
        ],
        'head_of_organization_center' => [
            'projects', 'ongoing', 'completed', 'beneficiaries', 'progress', 'users',
        ],
        'head_of_department' => [
            'projects', 'ongoing', 'completed', 'beneficiaries', 'progress',
        ],
        'accountant' => [
            'budget', 'expenses',
        ],
        'human_resource_manager' => [
            'users',
        ],
        'field_officer' => [
            'projects', 'ongoing', 'completed', 'beneficiaries', 'progress',
        ],
        'staff' => [
            'projects', 'beneficiaries',
        ],
    ];
}

function getModuleKpiKeys(string $module, string $role): array
{
    switch ($module) {
        case 'projects':
            if ($role === 'staff') {
                return ['projects'];
            }

            return ['projects', 'ongoing', 'completed', 'progress'];
        case 'beneficiaries':
            return ['beneficiaries'];
        case 'finance':
            return ['budget', 'expenses'];
        case 'users':
            return ['users'];
        default:
            return [];
    }
}

function deriveRoleKpiCardsFromModules(string $role): array
{
    $cards = [];

    foreach (['projects', 'beneficiaries', 'finance', 'users'] as $module) {
        if (!roleHasModuleNavigation($role, $module)) {
            continue;
        }

        $cards = array_merge($cards, getModuleKpiKeys($module, $role));
    }

    return array_values(array_unique($cards));
}

function getRoleKpiCardsForUser(array $user): array
{
    $role = normalizeRoleName($user['role'] ?? '');
    $matrix = getRoleKpiMatrix();

    if (isset($matrix[$role])) {
        return $matrix[$role];
    }

    $derived = deriveRoleKpiCardsFromModules($role);

    return $derived ?: [];
}

function roleHasModuleNavigation(string $role, string $module): bool
{
    $role = normalizeRoleName($role);
    $nav = [
        'projects' => ['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff'],
        'beneficiaries' => ['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff'],
        'planning' => ['admin', 'head_of_organization_center', 'head_of_department', 'field_officer'],
        'finance' => ['admin', 'accountant'],
        'reports' => ['admin', 'head_of_organization_center', 'head_of_department', 'human_resource_manager', 'accountant'],
        'users' => ['admin', 'head_of_organization_center', 'human_resource_manager'],
        'settings' => ['admin', 'head_of_organization_center'],
    ];

    return in_array($role, $nav[$module] ?? [], true);
}

function buildRoleVisualizationVisibility(array $user): array
{
    $cards = getRoleKpiCardsForUser($user);
    $cardSet = array_fill_keys($cards, true);
    $hasProjects = isset($cardSet['projects']) || isset($cardSet['ongoing']) || isset($cardSet['completed']) || isset($cardSet['progress']);
    $hasFinance = isset($cardSet['budget']) || isset($cardSet['expenses']);

    return [
        'overview' => $hasProjects,
        'funding' => $hasProjects,
        'project_status' => isset($cardSet['ongoing']) || isset($cardSet['completed']) || isset($cardSet['projects']),
        'budget_expense' => $hasFinance,
        'recent_projects' => isset($cardSet['projects']) || isset($cardSet['ongoing']) || isset($cardSet['completed']),
        'notifications' => true,
    ];
}

function buildRoleKpiVisibility(array $user): array
{
    $cards = getRoleKpiCardsForUser($user);
    $cardSet = array_fill_keys($cards, true);
    $role = normalizeRoleName($user['role'] ?? '');
    $isAdmin = $role === 'admin';

    return [
        'is_admin' => $isAdmin,
        'role' => $role,
        'cards' => $cards,
        'projects' => isset($cardSet['projects']) || isset($cardSet['ongoing']) || isset($cardSet['completed']) || isset($cardSet['progress']),
        'finance' => isset($cardSet['budget']) || isset($cardSet['expenses']),
        'finance_manage' => $role === 'accountant',
        'finance_reports_only' => $role === 'admin',
        'users' => isset($cardSet['users']),
        'visualizations' => buildRoleVisualizationVisibility($user),
        'navigation' => [
            'projects' => $isAdmin || roleHasModuleNavigation($role, 'projects'),
            'beneficiaries' => $isAdmin || roleHasModuleNavigation($role, 'beneficiaries'),
            'planning' => $isAdmin || roleHasModuleNavigation($role, 'planning'),
            'finance' => $isAdmin || roleHasModuleNavigation($role, 'finance'),
            'reports' => $isAdmin || roleHasModuleNavigation($role, 'reports'),
            'users' => $isAdmin || roleHasModuleNavigation($role, 'users'),
            'settings' => $isAdmin || roleHasModuleNavigation($role, 'settings'),
            'notifications' => true,
        ],
    ];
}
