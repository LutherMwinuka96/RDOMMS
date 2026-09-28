<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/render-page.php';
require_once __DIR__ . '/../config/project-page-access.php';
rdomms_require_project_module_page();
rdomms_render_protected_html(__DIR__ . '/archived-projects.html');
