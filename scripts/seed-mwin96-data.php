<?php
/**
 * Seed beneficiaries and planning records for mwin96@gmail.com (user typed mwn96@gmail.com).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/encryption.php';
require_once __DIR__ . '/../config/dates.php';
require_once __DIR__ . '/../api/planning-helpers.php';

$targetEmail = 'mwin96@gmail.com';

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    fwrite(STDERR, "Database connection failed\n");
    exit(1);
}

ensurePlanningSchema($conn);

$stmt = $conn->prepare('SELECT id, user_id, fullname, email, role, department, organization FROM users WHERE LOWER(email) = ? LIMIT 1');
$stmt->execute([strtolower($targetEmail)]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    fwrite(STDERR, "User not found: {$targetEmail}\n");
    exit(1);
}

$userId = (int) $user['id'];
$userName = trim((string) $user['fullname']);
$department = trim((string) ($user['department'] ?: 'administration'));
$station = trim((string) ($user['organization'] ?: 'rdo_mdabulo'));
$year = rdomms_current_year();
$now = date('Y-m-d H:i:s');

echo "Seeding for user #{$userId} — {$userName} ({$targetEmail})\n";

$beneficiaryRows = [
    ['Grace Mwinuka', 'female', 14, 'primary-school', 'Mafinga', 'Iringa', 'Mafinga Ward', '0712345001'],
    ['John Massawe', 'male', 17, 'secondary-school', 'Mdabulo', 'Iringa', 'Mdabulo Ward', '0712345002'],
    ['Neema Joseph', 'female', 22, 'college', 'Ibwanzi', 'Iringa', 'Ibwanzi Ward', '0712345003'],
    ['Peter Kileo', 'male', 65, 'elderly', 'Kilolo', 'Iringa', 'Kilolo Ward', '0712345004'],
    ['Amina Hassan', 'female', 34, 'women', 'Malangali', 'Iringa', 'Malangali Ward', '0712345005'],
    ['David Mushi', 'male', 12, 'children', 'Mafinga', 'Iringa', 'Mafinga Ward', '0712345006'],
    ['Rehema Ally', 'female', 28, 'vulnerable-households', 'Mdabulo', 'Iringa', 'Mdabulo Ward', '0712345007'],
    ['Samwel Ngonyani', 'male', 19, 'youth', 'Ibwanzi', 'Iringa', 'Ibwanzi Ward', '0712345008'],
    ['Fatuma Saidi', 'female', 8, 'kindergarten', 'Kilolo', 'Iringa', 'Kilolo Ward', '0712345009'],
    ['Michael Thomas', 'male', 31, 'persons-with-disabilities', 'Malangali', 'Iringa', 'Malangali Ward', '0712345010'],
    ['Lucy Mwakasege', 'female', 45, 'households', 'Mafinga', 'Iringa', 'Mafinga Ward', '0712345011'],
    ['Emmanuel Kavishe', 'male', 16, 'secondary-school', 'Mdabulo', 'Iringa', 'Mdabulo Ward', '0712345012'],
];

$beneficiaryInsert = $conn->prepare(
    'INSERT INTO beneficiaries
        (beneficiary_id, fullname, beneficiary_code, gender, age, category, group_count, status, phone, national_id, location, district, ward, household_size, registered_date, assigned_officer, services_needed, notes, registered_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$beneficiariesAdded = 0;
foreach ($beneficiaryRows as $index => $row) {
    [$name, $gender, $age, $category, $location, $district, $ward, $phone] = $row;
    $suffix = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
    $beneficiaryId = 'BEN' . time() . $suffix . rand(10, 99);
    $beneficiaryCode = 'BEN-' . $year . '-' . $suffix;

    $check = $conn->prepare('SELECT id FROM beneficiaries WHERE beneficiary_code = ? LIMIT 1');
    $check->execute([$beneficiaryCode]);
    if ($check->fetch()) {
        continue;
    }

    $beneficiaryInsert->execute([
        $beneficiaryId,
        $name,
        $beneficiaryCode,
        $gender,
        $age,
        $category,
        1,
        $index % 4 === 0 ? 'pending' : 'active',
        rdomms_encrypt_sensitive($phone),
        rdomms_encrypt_sensitive('ID-' . $year . '-' . $suffix),
        $location,
        $district,
        $ward,
        rand(3, 8),
        date('Y-m-d', strtotime('-' . rand(1, 120) . ' days')),
        $userName,
        'Education support, monitoring, and follow-up visits',
        'Registered under ' . $userName . ' for RDO Mdabulo outreach programme.',
        $userId,
    ]);
    $beneficiariesAdded++;
    usleep(10000);
}

$monthlyPlans = [
    ['June', 'pending', 1850000],
    ['July', 'approved', 2100000],
];

$quarterlyPlans = [
    ['Q2', 'pending', 6200000],
    ['Q3', 'approved', 7400000],
];

$annualPlans = [
    [(string) $year, 'approved', 28500000],
];

$monthlyInsert = $conn->prepare(
    'INSERT INTO monthly_plans
        (plan_id, month, year, staff_name, department, station, objective, notes, activities, total_budget, approval_status, created_by, submitted_at, saved_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$plansAdded = 0;
foreach ($monthlyPlans as [$month, $status, $budget]) {
    $planId = "{$year}-{$month}-U{$userId}";
    $check = $conn->prepare('SELECT plan_id FROM monthly_plans WHERE plan_id = ? LIMIT 1');
    $check->execute([$planId]);
    if ($check->fetch()) {
        continue;
    }

    $activities = json_encode([
        [
            'week' => 'Week 1',
            'activity' => 'Beneficiary registration and home visits',
            'output' => '12 households assessed',
            'responsible' => $userName,
            'budget' => (string) round($budget * 0.35),
            'status' => 'planned',
        ],
        [
            'week' => 'Week 2',
            'activity' => 'Community training sessions',
            'output' => '2 workshops delivered',
            'responsible' => $userName,
            'budget' => (string) round($budget * 0.65),
            'status' => 'planned',
        ],
    ], JSON_UNESCAPED_UNICODE);

    $monthlyInsert->execute([
        $planId,
        $month,
        $year,
        $userName,
        $department,
        $station,
        "Monthly operational plan for {$month} {$year} — {$userName}",
        'Seeded plan linked to beneficiary outreach activities.',
        $activities,
        $budget,
        $status,
        $userId,
        $status === 'draft' ? null : $now,
        $now,
    ]);
    $plansAdded++;
}

$quarterlyInsert = $conn->prepare(
    'INSERT INTO quarterly_plans
        (plan_id, quarter, year, staff_name, department, station, objective, notes, activities, total_budget, approval_status, created_by, submitted_at, saved_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

foreach ($quarterlyPlans as [$quarter, $status, $budget]) {
    $planId = "{$year}-{$quarter}-U{$userId}";
    $check = $conn->prepare('SELECT plan_id FROM quarterly_plans WHERE plan_id = ? LIMIT 1');
    $check->execute([$planId]);
    if ($check->fetch()) {
        continue;
    }

    $activities = json_encode([
        [
            'month' => 'Month 1',
            'activities' => 'Beneficiary enrolment and baseline survey',
            'equipment' => 'Survey forms, tablets',
            'participants' => $userName,
            'budget' => (string) round($budget * 0.3),
        ],
        [
            'month' => 'Month 2',
            'activities' => 'Service delivery and field monitoring',
            'equipment' => 'Transport, training kits',
            'participants' => $userName,
            'budget' => (string) round($budget * 0.45),
        ],
        [
            'month' => 'Month 3',
            'activities' => 'Quarterly review and reporting',
            'equipment' => 'Reporting templates',
            'participants' => $userName,
            'budget' => (string) round($budget * 0.25),
        ],
    ], JSON_UNESCAPED_UNICODE);

    $quarterlyInsert->execute([
        $planId,
        $quarter,
        $year,
        $userName,
        $department,
        $station,
        "Quarterly plan {$quarter} {$year} for center outreach",
        'Seeded quarterly plan with beneficiary-linked activities.',
        $activities,
        $budget,
        $status,
        $userId,
        $status === 'draft' ? null : $now,
        $now,
    ]);
    $plansAdded++;
}

$annualInsert = $conn->prepare(
    'INSERT INTO annual_plans
        (plan_id, year, staff_name, department, station, objective, notes, activities, total_budget, approval_status, created_by, submitted_at, saved_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

foreach ($annualPlans as [$planYear, $status, $budget]) {
    $planId = "{$planYear}-U{$userId}";
    $check = $conn->prepare('SELECT plan_id FROM annual_plans WHERE plan_id = ? LIMIT 1');
    $check->execute([$planId]);
    if ($check->fetch()) {
        continue;
    }

    $activities = json_encode([
        ['quarter' => 'Q1', 'theme' => 'Beneficiary mobilization', 'budget' => (string) round($budget * 0.22)],
        ['quarter' => 'Q2', 'theme' => 'Service delivery scale-up', 'budget' => (string) round($budget * 0.28)],
        ['quarter' => 'Q3', 'theme' => 'Monitoring and evaluation', 'budget' => (string) round($budget * 0.25)],
        ['quarter' => 'Q4', 'theme' => 'Annual reporting', 'budget' => (string) round($budget * 0.25)],
    ], JSON_UNESCAPED_UNICODE);

    $annualInsert->execute([
        $planId,
        (int) $planYear,
        $userName,
        $department,
        $station,
        "Annual strategic plan {$planYear} — {$userName}",
        'Seeded annual plan covering beneficiary support across all quarters.',
        $activities,
        $budget,
        $status,
        $userId,
        $now,
        $now,
    ]);
    $plansAdded++;
}

$beneficiaryTotal = (int) $conn->query("SELECT COUNT(*) FROM beneficiaries WHERE registered_by = {$userId}")->fetchColumn();
$monthlyTotal = (int) $conn->query("SELECT COUNT(*) FROM monthly_plans WHERE created_by = {$userId}")->fetchColumn();
$quarterlyTotal = (int) $conn->query("SELECT COUNT(*) FROM quarterly_plans WHERE created_by = {$userId}")->fetchColumn();
$annualTotal = (int) $conn->query("SELECT COUNT(*) FROM annual_plans WHERE created_by = {$userId}")->fetchColumn();

echo json_encode([
    'user' => [
        'id' => $userId,
        'email' => $targetEmail,
        'fullname' => $userName,
    ],
    'inserted_now' => [
        'beneficiaries' => $beneficiariesAdded,
        'plans' => $plansAdded,
    ],
    'totals_for_user' => [
        'beneficiaries' => $beneficiaryTotal,
        'monthly_plans' => $monthlyTotal,
        'quarterly_plans' => $quarterlyTotal,
        'annual_plans' => $annualTotal,
        'all_plans' => $monthlyTotal + $quarterlyTotal + $annualTotal,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
