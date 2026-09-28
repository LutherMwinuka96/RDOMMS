<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
rdomms_require_authenticated_page();

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$pageConfig = [
    'apiSettings' => '../api/settings/general.php',
];

$htmlPath = __DIR__ . '/general-settings.html';
if (!is_readable($htmlPath)) {
    http_response_code(500);
    echo 'General settings page is unavailable.';
    exit;
}

require_once __DIR__ . '/../config/page-assets.php';
$html = rdomms_load_page_html($htmlPath);
$configScript = '<script>window.RDOMMS_GENERAL_SETTINGS='
    . json_encode($pageConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
    . ';</script>';

if (strpos($html, '</head>') !== false) {
    $html = str_replace('</head>', $configScript . "\n</head>", $html);
} else {
    $html = $configScript . $html;
}

echo $html;
