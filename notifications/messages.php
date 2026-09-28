<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
rdomms_require_authenticated_page();

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$pageConfig = ['apiMessages' => '../api/messages.php'];
$htmlPath = __DIR__ . '/messages.html';
if (!is_readable($htmlPath)) { http_response_code(500); echo 'Messages page unavailable.'; exit; }
require_once __DIR__ . '/../config/page-assets.php';
$html = rdomms_load_page_html($htmlPath);
$configScript = '<script>window.RDOMMS_MESSAGES_PAGE=' . json_encode($pageConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>';
echo str_replace('</head>', $configScript . "\n</head>", $html);
