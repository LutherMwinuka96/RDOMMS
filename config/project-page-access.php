<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Project pages admins may open (read-only portfolio review). */
function rdomms_admin_project_pages(): array
{
    return [
        'all-projects',
        'archived-projects',
    ];
}

/** Roles that may open any project module page (non-admin subset enforced separately). */
function rdomms_project_module_roles(): array
{
    return [
        'admin',
        'head_of_organization_center',
        'head_of_department',
        'field_officer',
        'staff',
        'accountant',
    ];
}

function userCanAccessProjectModule(?array $user): bool
{
    if (!$user) {
        return false;
    }

    return in_array(
        normalizeRoleName($user['role'] ?? ''),
        rdomms_project_module_roles(),
        true
    );
}

function rdomms_current_project_page_slug(): string
{
    return strtolower(pathinfo(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), PATHINFO_FILENAME));
}

function rdomms_redirect_to_project_page(string $page = 'all-projects.php'): void
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_contains($script, '/documents/')) {
        header('Location: ../projects/' . ltrim($page, '/'));
        exit;
    }

    header('Location: ' . $page);
    exit;
}

/** Guard project *.php pages — admins are limited to portfolio/archive views. */
function rdomms_require_project_module_page(): array
{
    $user = rdomms_require_authenticated_page();
    if (!userCanAccessProjectModule($user)) {
        header('Location: ../RDOMMS.php');
        exit;
    }

    if (userCanViewAllData($user)) {
        $slug = rdomms_current_project_page_slug();
        if (!in_array($slug, rdomms_admin_project_pages(), true)) {
            rdomms_redirect_to_project_page('all-projects.php');
        }
    }

    return $user;
}
