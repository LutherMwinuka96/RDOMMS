<?php
declare(strict_types=1);

require_once __DIR__ . '/config/env.php';
rdomms_load_env();
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/waf.php';

if (!defined('RDOMMS_SKIP_HTTPS_REDIRECT')) {
    rdomms_force_https_if_configured();
}

rdomms_waf_protect();

define('RDOMMS_SKIP_WAF', true);
define('RDOMMS_SKIP_SECURITY', true);
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$landingPage = __DIR__ . '/index.html';
if (!is_readable($landingPage)) {
    http_response_code(500);
    echo 'Landing page is unavailable.';
    exit;
}

$html = file_get_contents($landingPage);
if ($html === false) {
    http_response_code(500);
    echo 'Landing page is unavailable.';
    exit;
}

$landingGuard = <<<'HTML'
<script>
window.RDOMMS_LANDING_PAGE = true;
window.RDOMMS_BLOCK_SIGNIN_REDIRECT = true;
</script>
HTML;

if (stripos($html, 'RDOMMS_LANDING_PAGE') === false) {
    $html = preg_replace('/<head([^>]*)>/i', '<head$1>' . "\n" . $landingGuard, $html, 1) ?: $html;
}

echo $html;
