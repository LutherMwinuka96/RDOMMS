<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
rdomms_require_authenticated_page();

header('Location: beneficiary-list.php', true, 301);
exit;
