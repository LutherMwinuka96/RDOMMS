<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/dates.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/report-access.php';

$currentUser = getAuthenticatedUser();
if (!$currentUser || !roleCanAccessGenerateReportsPage($currentUser)) {
    header('Location: ' . rdomms_login_redirect_url(false));
    exit;
}

$pageConfig = [
    'apiBase' => '../api/reports.php',
    'monthStart' => date('Y-m-01'),
    'monthEnd' => date('Y-m-t'),
    'currentYear' => rdomms_current_year(),
    'reportAccess' => buildReportAccessClientConfig($currentUser),
];

$htmlPath = __DIR__ . '/generate-reports.html';
if (!is_readable($htmlPath)) {
    http_response_code(500);
    echo 'Report Studio page is unavailable.';
    exit;
}

require_once __DIR__ . '/../config/page-assets.php';
$html = rdomms_load_page_html($htmlPath);
$configScript = '<script>window.RDOMMS_REPORTS_PAGE='
    . json_encode($pageConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
    . ';</script>';

if (strpos($html, '</head>') !== false) {
    $html = str_replace('</head>', $configScript . "\n</head>", $html);
} else {
    $html = $configScript . $html;
}

echo $html;
