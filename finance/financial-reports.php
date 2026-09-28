<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$currentUser = getAuthenticatedUser();
if (!$currentUser) {
    header('Location: ' . rdomms_login_redirect_url(false));
    exit;
}
if (!userCanViewFinanceReports($currentUser)) {
    header('Location: ../RDOMMS.php');
    exit;
}

require_once __DIR__ . '/../config/render-page.php';
rdomms_render_html(__DIR__ . '/financial-reports.html');
