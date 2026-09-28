<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
rdomms_require_authenticated_page();

$currentUser = getAuthenticatedUser();
if (!$currentUser || normalizeRoleName($currentUser['role'] ?? '') !== 'admin') {
    header('Location: ../RDOMMS.php');
    exit;
}

require_once __DIR__ . '/../config/render-page.php';
rdomms_render_protected_html(__DIR__ . '/permissions.html');
