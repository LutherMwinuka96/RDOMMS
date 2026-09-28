<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/planning-helpers.php';

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

ensurePlanningSchema($conn);
echo "Planning bootstrap complete.\n\n";
