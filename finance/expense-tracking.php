<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/dates.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/organization-centers.php';
require_once __DIR__ . '/../config/departments.php';

$currentUser = getAuthenticatedUser();
if (!$currentUser || !userCanManageFinanceCrud($currentUser)) {
    header('Location: financial-reports.php');
    exit;
}

$userDefaults = null;
if ($currentUser) {
    $userDefaults = [
        'fullname' => (string) ($currentUser['fullname'] ?? ''),
        'organization' => normalizeRdommsOrganizationCenter((string) ($currentUser['organization'] ?? ''))
            ?? (string) ($currentUser['organization'] ?? ''),
        'department' => normalizeRdommsDepartment((string) ($currentUser['department'] ?? ''))
            ?? (string) ($currentUser['department'] ?? ''),
    ];
}

$pageConfig = [
    'apiExpenses' => '../api/expenses.php',
    'apiBudget' => '../api/budget.php',
    'currentYear' => rdomms_current_year(),
    'currentMonth' => rdomms_current_month_name(),
    'currentQuarter' => rdomms_current_quarter_slug(),
    'years' => rdomms_year_range(),
    'yearMin' => RDOMMS_YEAR_MIN,
    'yearMax' => RDOMMS_YEAR_MAX,
    'userDefaults' => $userDefaults,
    'links' => [
        'budgetAllocation' => 'budget-allocation.php',
        'varianceAnalysis' => '../reports/variance-analysis.php',
        'financialReports' => 'financial-reports.php',
    ],
];

$htmlPath = __DIR__ . '/expense-tracking.html';
if (!is_readable($htmlPath)) {
    http_response_code(500);
    echo 'Expense tracking page is unavailable.';
    exit;
}

require_once __DIR__ . '/../config/page-assets.php';
$html = rdomms_load_page_html($htmlPath);
$configScript = '<script>window.RDOMMS_EXPENSE_PAGE='
    . json_encode($pageConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
    . ';</script>';

if (strpos($html, '</head>') !== false) {
    $html = str_replace('</head>', $configScript . "\n</head>", $html);
} else {
    $html = $configScript . $html;
}

echo $html;
