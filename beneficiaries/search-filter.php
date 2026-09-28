<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
rdomms_require_authenticated_page();

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$pageConfig = [
    'apiBeneficiaries' => '../api/beneficiaries.php',
];

$htmlPath = __DIR__ . '/search-filter.html';
if (!is_readable($htmlPath)) {
    http_response_code(500);
    echo 'Search and filter page is unavailable.';
    exit;
}

require_once __DIR__ . '/../config/page-assets.php';
$html = rdomms_load_page_html($htmlPath);
$configScript = '<script>window.RDOMMS_SEARCH_FILTER='
    . json_encode($pageConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
    . ';</script>';

if (strpos($html, '</head>') !== false) {
    $html = str_replace('</head>', $configScript . "\n</head>", $html);
} else {
    $html = $configScript . $html;
}

echo $html;
