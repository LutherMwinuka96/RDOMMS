<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$session = verifySession();
$currentUser = getAuthenticatedUser($session);
if (!$currentUser) {
    header('Location: ' . rdomms_login_redirect_url(false));
    exit;
}
$isAdmin = is_array($currentUser) && userCanViewAllData($currentUser);

$pageConfig = [
    'apiActivityLog' => '../api/activity-log.php',
    'allUsersUrl' => 'all-users.php',
    'isAdmin' => $isAdmin,
    'scope' => $isAdmin ? 'all' : 'account',
];

$htmlPath = __DIR__ . '/activity-log.html';
if (!is_readable($htmlPath)) {
    http_response_code(500);
    echo 'Activity log page is unavailable.';
    exit;
}

require_once __DIR__ . '/../config/page-assets.php';
$html = rdomms_load_page_html($htmlPath);
$configScript = '<script>window.RDOMMS_ACTIVITY_LOG='
    . json_encode($pageConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
    . ';</script>';

if (strpos($html, '</head>') !== false) {
    $html = str_replace('</head>', $configScript . "\n</head>", $html);
} else {
    $html = $configScript . $html;
}

echo $html;
