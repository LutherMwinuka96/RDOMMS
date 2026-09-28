<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/project-budget.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$currentUser = getAuthenticatedUser();
if (!$currentUser) {
    header('Location: ' . rdomms_login_redirect_url(false));
    exit;
}

$role = normalizeRoleName($currentUser['role'] ?? '');
if (!in_array($role, ['admin', 'accountant', 'head_of_organization_center', 'head_of_department'], true)) {
    header('Location: ../RDOMMS.php');
    exit;
}

require_once __DIR__ . '/../config/page-assets.php';
echo rdomms_load_page_html(__DIR__ . '/project-budget-approvals.html');
