<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
rdomms_require_authenticated_page();

require_once __DIR__ . '/../config/render-page.php';
rdomms_render_protected_html(__DIR__ . '/beneficiary-history.html');
