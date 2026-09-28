<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/page-assets.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$session = verifySession();
$currentUser = getAuthenticatedUser($session);
if (!$currentUser) {
    header('Location: ' . rdomms_login_redirect_url(false));
    exit;
}

$pageConfig = [
    'apiAlerts' => '../api/notifications.php',
    'isAdmin' => is_array($currentUser) && userCanViewAllData($currentUser),
    'userId' => (int) ($currentUser['id'] ?? 0),
];

$htmlPath = __DIR__ . '/system-alerts.html';
if (!is_readable($htmlPath)) {
    http_response_code(500);
    echo 'System alerts page unavailable.';
    exit;
}

$html = rdomms_load_page_html($htmlPath);
$configScript = '<script>window.RDOMMS_ALERTS_PAGE=' . json_encode($pageConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>';
echo str_replace('</head>', $configScript . "\n</head>", $html);

