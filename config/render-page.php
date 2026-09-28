<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';
rdomms_load_env();
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/waf.php';

if (!defined('RDOMMS_SKIP_HTTPS_REDIRECT')) {
    rdomms_force_https_if_configured();
}

rdomms_waf_protect();

require_once __DIR__ . '/page-assets.php';

/**
 * Serve a static HTML template from the same module folder as the calling PHP entry.
 */
function rdomms_render_html(string $templatePath): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    echo rdomms_load_page_html($templatePath);
}

/**
 * Serve an HTML template only when the visitor has a valid server session.
 */
function rdomms_render_protected_html(string $templatePath): void
{
    require_once __DIR__ . '/db.php';
    rdomms_require_authenticated_page();
    rdomms_render_html($templatePath);
}
