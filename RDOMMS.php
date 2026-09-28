<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
rdomms_require_authenticated_page();

require_once __DIR__ . '/config/page-assets.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$htmlPath = __DIR__ . '/RDOMMS.html';
if (!is_readable($htmlPath)) {
    http_response_code(500);
    echo 'Dashboard is unavailable.';
    exit;
}

echo rdomms_load_page_html($htmlPath);
