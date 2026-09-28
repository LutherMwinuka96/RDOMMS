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

foreach (['monthly_plans', 'quarterly_plans', 'annual_plans', 'targets', 'progress_updates', 'planning_evidence'] as $table) {
    try {
        $count = (int) $conn->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        echo "{$table}: {$count}\n";
        if ($count > 0 && str_ends_with($table, '_plans')) {
            $rows = $conn->query("SELECT plan_id, approval_status, submitted_at, created_by, staff_name, department, station, CHAR_LENGTH(COALESCE(activities,'')) AS act_len FROM {$table} ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                echo '  ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    } catch (Throwable $e) {
        echo "{$table}: ERROR " . $e->getMessage() . "\n";
    }
}
