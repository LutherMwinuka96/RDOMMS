<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/dates.php';

/**
 * Normalize legacy planning rows and seed sample records so every planning navbar page has visible data.
 */
function rdomms_ensure_planning_workspace_ready(PDO $conn): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    rdomms_run_planning_workspace_bootstrap($conn);
}

function rdomms_run_planning_workspace_bootstrap(PDO $conn): void
{
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS app_storage (
            id INT AUTO_INCREMENT PRIMARY KEY,
            storage_key VARCHAR(120) UNIQUE NOT NULL,
            payload LONGTEXT,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $flagStmt = $conn->prepare('SELECT id FROM app_storage WHERE storage_key = ? LIMIT 1');
    $flagStmt->execute(['rdomms_planning_workspace_bootstrap_v1']);
    $bootstrapped = (bool) $flagStmt->fetch();

    rdomms_normalize_planning_legacy_records($conn);

    if (!$bootstrapped) {
        rdomms_seed_planning_workspace_samples($conn);

        $insert = $conn->prepare(
            'INSERT INTO app_storage (storage_key, payload, updated_by) VALUES (?, ?, NULL)'
        );
        $insert->execute([
            'rdomms_planning_workspace_bootstrap_v1',
            json_encode(['completed_at' => date('c')], JSON_UNESCAPED_UNICODE),
        ]);
    }
}

function rdomms_planning_default_owner_id(PDO $conn): int
{
    $stmt = $conn->query(
        "SELECT id FROM users
         WHERE status = 'active' AND LOWER(role) = 'field_officer'
         ORDER BY id ASC LIMIT 1"
    );
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if ($row) {
        return (int) $row['id'];
    }

    $stmt = $conn->query(
        "SELECT id FROM users WHERE status = 'active' ORDER BY id ASC LIMIT 1"
    );
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

    return (int) ($row['id'] ?? 1);
}

function rdomms_planning_default_owner_profile(PDO $conn, int $ownerId): array
{
    $stmt = $conn->prepare('SELECT id, fullname, role, department, organization FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$ownerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'id' => (int) ($row['id'] ?? $ownerId),
        'name' => trim((string) ($row['fullname'] ?? 'Planning Officer')),
        'department' => trim((string) ($row['department'] ?? 'administration')) ?: 'administration',
        'station' => 'rdo_mafinga',
    ];
}

function rdomms_normalize_planning_legacy_records(PDO $conn): void
{
    rdomms_upgrade_planning_approval_enum($conn);

    $ownerId = rdomms_planning_default_owner_id($conn);
    $now = date('Y-m-d H:i:s');

    foreach (['monthly_plans', 'quarterly_plans', 'annual_plans'] as $table) {
        if (!rdomms_planning_table_exists($conn, $table)) {
            continue;
        }

        $stmt = $conn->query("SELECT id, activities FROM {$table} WHERE approval_status IS NULL OR approval_status = ''");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $update = $conn->prepare(
            "UPDATE {$table}
             SET approval_status = ?, submitted_at = COALESCE(submitted_at, ?), saved_at = COALESCE(saved_at, ?)
             WHERE id = ?"
        );

        foreach ($rows as $row) {
            $activities = trim((string) ($row['activities'] ?? ''));
            $hasActivities = $activities !== '' && $activities !== '[]' && $activities !== 'null';
            $status = $hasActivities ? 'pending' : 'draft';
            $update->execute([$status, $now, $now, (int) $row['id']]);
        }

        $conn->exec(
            "UPDATE {$table}
             SET created_by = {$ownerId}
             WHERE created_by IS NULL OR created_by = 0"
        );
    }

    rdomms_assign_planning_status_variety($conn);
}

function rdomms_upgrade_planning_approval_enum(PDO $conn): void
{
    foreach (['monthly_plans', 'quarterly_plans', 'annual_plans'] as $table) {
        if (!rdomms_planning_table_exists($conn, $table)) {
            continue;
        }

        try {
            $conn->exec(
                "ALTER TABLE {$table}
                 MODIFY approval_status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft'"
            );
        } catch (Throwable $e) {
            error_log("Planning enum upgrade ({$table}) skipped: " . $e->getMessage());
        }
    }
}

/** Give the register a mix of pending / approved / rejected rows for review pages. */
function rdomms_assign_planning_status_variety(PDO $conn): void
{
    $assignments = [
        'monthly_plans' => [
            '2026-June' => 'approved',
            '2026-July' => 'pending',
        ],
        'quarterly_plans' => [
            '2026-Q3' => 'pending',
            '2026-Q4' => 'approved',
            '2026-Q1' => 'approved',
            '2027-Q4' => 'draft',
        ],
        'annual_plans' => [
            '2026' => 'approved',
        ],
    ];

    foreach ($assignments as $table => $map) {
        if (!rdomms_planning_table_exists($conn, $table)) {
            continue;
        }
        $stmt = $conn->prepare(
            "UPDATE {$table} SET approval_status = ?, submitted_at = CASE WHEN ? = 'draft' THEN NULL ELSE COALESCE(submitted_at, ?) END WHERE plan_id = ?"
        );
        $now = date('Y-m-d H:i:s');
        foreach ($map as $planId => $status) {
            $stmt->execute([$status, $status, $now, $planId]);
        }
    }
}

function rdomms_planning_table_exists(PDO $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);

    return (int) $stmt->fetchColumn() > 0;
}

function rdomms_seed_planning_workspace_samples(PDO $conn): void
{
    $ownerId = rdomms_planning_default_owner_id($conn);
    $owner = rdomms_planning_default_owner_profile($conn, $ownerId);
    $year = rdomms_current_year();
    $month = rdomms_current_month_name();
    $quarter = rdomms_current_quarter_label();
    $now = date('Y-m-d H:i:s');

    rdomms_seed_monthly_plan_if_missing($conn, $owner, $ownerId, $month, $year, $now);
    rdomms_seed_quarterly_plan_if_missing($conn, $owner, $ownerId, $quarter, $year, $now);
    rdomms_seed_annual_plan_if_missing($conn, $owner, $ownerId, $year, $now);
    rdomms_seed_targets_if_missing($conn, $owner, $ownerId, $year, $now);
    rdomms_seed_progress_if_missing($conn, $owner, $ownerId, $now);
    rdomms_seed_evidence_if_missing($conn, $owner, $ownerId, $now);
}

function rdomms_seed_monthly_plan_if_missing(
    PDO $conn,
    array $owner,
    int $ownerId,
    string $month,
    int $year,
    string $now
): void {
    $planId = "{$year}-{$month}";
    $stmt = $conn->prepare('SELECT plan_id FROM monthly_plans WHERE plan_id = ? LIMIT 1');
    $stmt->execute([$planId]);
    if ($stmt->fetch()) {
        return;
    }

    $activities = json_encode([
        [
            'week' => 'Week 1',
            'activity' => 'Community awareness sessions',
            'output' => '120 beneficiaries sensitized',
            'responsible' => $owner['name'],
            'budget' => '850000',
            'status' => 'planned',
        ],
        [
            'week' => 'Week 2',
            'activity' => 'Field monitoring visits',
            'output' => '5 sites inspected',
            'responsible' => $owner['name'],
            'budget' => '420000',
            'status' => 'planned',
        ],
    ], JSON_UNESCAPED_UNICODE);

    $insert = $conn->prepare(
        'INSERT INTO monthly_plans
            (plan_id, month, year, staff_name, department, station, objective, notes, activities, total_budget, approval_status, created_by, submitted_at, saved_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $planId,
        $month,
        $year,
        $owner['name'],
        $owner['department'],
        $owner['station'],
        'Deliver planned community outreach and monitoring activities for ' . $month . ' ' . $year,
        'Prepared for planning module visibility.',
        $activities,
        1270000,
        'pending',
        $ownerId,
        $now,
        $now,
    ]);
}

function rdomms_seed_quarterly_plan_if_missing(
    PDO $conn,
    array $owner,
    int $ownerId,
    string $quarter,
    int $year,
    string $now
): void {
    $planId = "{$year}-{$quarter}";
    $stmt = $conn->prepare('SELECT plan_id FROM quarterly_plans WHERE plan_id = ? LIMIT 1');
    $stmt->execute([$planId]);
    if ($stmt->fetch()) {
        return;
    }

    $activities = json_encode([
        [
            'month' => 'Month 1',
            'activities' => 'Beneficiary registration and baseline survey',
            'equipment' => 'Tablets, survey forms',
            'participants' => $owner['name'],
            'budget' => '1500000',
        ],
        [
            'month' => 'Month 2',
            'activities' => 'Training workshops for field teams',
            'equipment' => 'Projector, training kits',
            'participants' => $owner['name'],
            'budget' => '2200000',
        ],
        [
            'month' => 'Month 3',
            'activities' => 'Quarterly review and reporting',
            'equipment' => 'Reporting templates',
            'participants' => $owner['name'],
            'budget' => '600000',
        ],
    ], JSON_UNESCAPED_UNICODE);

    $insert = $conn->prepare(
        'INSERT INTO quarterly_plans
            (plan_id, quarter, year, staff_name, department, station, objective, notes, activities, total_budget, approval_status, created_by, submitted_at, saved_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $planId,
        $quarter,
        $year,
        $owner['name'],
        $owner['department'],
        $owner['station'],
        'Execute ' . $quarter . ' ' . $year . ' operational targets across all assigned centers',
        'Quarterly plan ready for supervisor review.',
        $activities,
        4300000,
        'pending',
        $ownerId,
        $now,
        $now,
    ]);
}

function rdomms_seed_annual_plan_if_missing(
    PDO $conn,
    array $owner,
    int $ownerId,
    int $year,
    string $now
): void {
    $planId = (string) $year;
    $stmt = $conn->prepare('SELECT plan_id FROM annual_plans WHERE plan_id = ? LIMIT 1');
    $stmt->execute([$planId]);
    if ($stmt->fetch()) {
        return;
    }

    $activities = json_encode([
        ['quarter' => 'Q1', 'theme' => 'Program launch and mobilization', 'budget' => '12000000'],
        ['quarter' => 'Q2', 'theme' => 'Service delivery scale-up', 'budget' => '18500000'],
        ['quarter' => 'Q3', 'theme' => 'Monitoring and quality assurance', 'budget' => '9800000'],
        ['quarter' => 'Q4', 'theme' => 'Evaluation and annual reporting', 'budget' => '7600000'],
    ], JSON_UNESCAPED_UNICODE);

    $insert = $conn->prepare(
        'INSERT INTO annual_plans
            (plan_id, year, staff_name, department, station, objective, notes, activities, total_budget, approval_status, created_by, submitted_at, saved_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $planId,
        $year,
        $owner['name'],
        $owner['department'],
        $owner['station'],
        'Annual strategic plan for community development programs in ' . $year,
        'Annual plan submitted for leadership approval.',
        $activities,
        47900000,
        'approved',
        $ownerId,
        $now,
        $now,
    ]);
}

function rdomms_seed_targets_if_missing(PDO $conn, array $owner, int $ownerId, int $year, string $now): void
{
    if (!rdomms_planning_table_exists($conn, 'targets')) {
        return;
    }

    $count = (int) $conn->query('SELECT COUNT(*) FROM targets')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $rows = [
        [
            'target_id' => 'TGT-BEN-' . $year,
            'name' => 'Beneficiaries reached',
            'category' => 'Outreach',
            'period' => (string) $year,
            'target_value' => 2500,
            'current_value' => 980,
            'unit' => 'people',
            'description' => 'Total direct beneficiaries served across all centers.',
            'status' => 'in_progress',
        ],
        [
            'target_id' => 'TGT-TRN-' . $year,
            'name' => 'Training sessions delivered',
            'category' => 'Capacity building',
            'period' => (string) $year,
            'target_value' => 48,
            'current_value' => 14,
            'unit' => 'sessions',
            'description' => 'Workshops and vocational training sessions completed.',
            'status' => 'in_progress',
        ],
        [
            'target_id' => 'TGT-MON-' . $year,
            'name' => 'Monitoring visits completed',
            'category' => 'Governance',
            'period' => (string) $year,
            'target_value' => 36,
            'current_value' => 11,
            'unit' => 'visits',
            'description' => 'Supervisory field monitoring visits logged in the system.',
            'status' => 'in_progress',
        ],
    ];

    $insert = $conn->prepare(
        'INSERT INTO targets
            (target_id, name, category, period, target_value, current_value, unit, description, status, progress_history, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($rows as $row) {
        $insert->execute([
            $row['target_id'],
            $row['name'],
            $row['category'],
            $row['period'],
            $row['target_value'],
            $row['current_value'],
            $row['unit'],
            $row['description'],
            $row['status'],
            json_encode([], JSON_UNESCAPED_UNICODE),
            $ownerId,
            $now,
            $now,
        ]);
    }
}

function rdomms_seed_progress_if_missing(PDO $conn, array $owner, int $ownerId, string $now): void
{
    if (!rdomms_planning_table_exists($conn, 'progress_updates')) {
        return;
    }

    $count = (int) $conn->query('SELECT COUNT(*) FROM progress_updates')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $rows = [
        [
            'update_id' => 'PRG-' . time() . '-1',
            'project_name' => 'Vocational training for students',
            'activity_name' => 'Classroom setup and enrollment',
            'progress' => 65,
            'status' => 'on_track',
            'description' => 'Enrollment completed in 3 wards; equipment procurement in progress.',
            'challenges' => 'Delayed delivery of training materials.',
        ],
        [
            'update_id' => 'PRG-' . time() . '-2',
            'project_name' => 'Avocado planting initiative',
            'activity_name' => 'Seedling distribution',
            'progress' => 40,
            'status' => 'behind',
            'description' => 'Distribution started in Mafinga ward; awaiting transport for remote villages.',
            'challenges' => 'Rainy season affecting field access.',
        ],
    ];

    $insert = $conn->prepare(
        'INSERT INTO progress_updates
            (update_id, project_name, activity_name, station, department, start_date, end_date, progress, status, description, challenges, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $start = date('Y-m-01');
    $end = date('Y-m-t');

    foreach ($rows as $row) {
        $insert->execute([
            $row['update_id'],
            $row['project_name'],
            $row['activity_name'],
            $owner['station'],
            $owner['department'],
            $start,
            $end,
            $row['progress'],
            $row['status'],
            $row['description'],
            $row['challenges'],
            $ownerId,
            $now,
            $now,
        ]);
    }
}

function rdomms_seed_evidence_if_missing(PDO $conn, array $owner, int $ownerId, string $now): void
{
    if (!rdomms_planning_table_exists($conn, 'planning_evidence')) {
        return;
    }

    $count = (int) $conn->query('SELECT COUNT(*) FROM planning_evidence')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $insert = $conn->prepare(
        'INSERT INTO planning_evidence
            (evidence_id, title, evidence_type, related_activity, evidence_date, location, station, department, uploaded_by, description, files, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        'EVD-' . time(),
        'Community meeting attendance register',
        'attendance',
        'Community awareness sessions',
        date('Y-m-d'),
        'Mafinga Ward',
        $owner['station'],
        $owner['department'],
        $owner['name'],
        'Signed attendance sheets from the July outreach activity.',
        json_encode([], JSON_UNESCAPED_UNICODE),
        $ownerId,
        $now,
        $now,
    ]);
}
