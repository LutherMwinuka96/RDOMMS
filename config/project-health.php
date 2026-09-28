<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/finance-budget.php';
require_once __DIR__ . '/finance-links.php';
require_once __DIR__ . '/funding-sources-catalog.php';
require_once __DIR__ . '/project-budget.php';

function rdomms_project_health_parse_team($team): array
{
    if (is_array($team)) {
        return $team;
    }
    if (is_string($team) && $team !== '') {
        $decoded = json_decode($team, true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

function rdomms_project_health_days_overdue(?string $endDate, string $status): int
{
    if ($endDate === null || $endDate === '' || strtolower($status) === 'completed') {
        return 0;
    }
    try {
        $end = new DateTimeImmutable($endDate);
        $today = new DateTimeImmutable('today');
        if ($end >= $today) {
            return 0;
        }
        return (int) $today->diff($end)->days;
    } catch (Throwable $e) {
        return 0;
    }
}

function rdomms_project_health_days_since(?string $date): int
{
    if ($date === null || $date === '') {
        return 0;
    }
    try {
        $start = new DateTimeImmutable($date);
        $today = new DateTimeImmutable('today');
        if ($start > $today) {
            return 0;
        }
        return (int) $today->diff($start)->days;
    } catch (Throwable $e) {
        return 0;
    }
}

function rdomms_fetch_project_document_counts(PDO $conn): array
{
    $counts = [];
    try {
        $stmt = $conn->query('SELECT project_name, COUNT(*) AS total FROM project_documents GROUP BY project_name');
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $name = strtolower(trim((string) ($row['project_name'] ?? '')));
            if ($name !== '') {
                $counts[$name] = (int) ($row['total'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return $counts;
}

function rdomms_project_health_resolve_level(array $alerts): string
{
    $keys = array_column($alerts, 'key');
    if (in_array('over_budget', $keys, true) || in_array('overdue', $keys, true)) {
        return 'critical';
    }
    if (
        in_array('near_budget', $keys, true)
        || in_array('no_expenses', $keys, true)
        || in_array('stalled', $keys, true)
        || in_array('missing_funding', $keys, true)
        || in_array('no_team', $keys, true)
    ) {
        return 'warning';
    }
    return 'good';
}

function rdomms_project_health_build_alerts(array $project, array $finance, int $documentCount): array
{
    $alerts = [];
    $status = strtolower((string) ($project['status'] ?? ''));
    $budget = (float) ($project['budget'] ?? 0);
    $progress = (int) ($project['progress'] ?? 0);
    $spent = (float) ($finance['spentCommitted'] ?? 0);
    $utilization = (float) ($finance['utilizationPct'] ?? 0);
    $linkedCount = (int) ($finance['linkedExpenseCount'] ?? 0);

    if ($budget > 0 && $spent > $budget) {
        $alerts[] = ['key' => 'over_budget', 'label' => 'Spending exceeds project budget', 'tone' => 'danger'];
    } elseif ($budget > 0 && $utilization >= 85) {
        $alerts[] = ['key' => 'near_budget', 'label' => 'Budget nearly exhausted', 'tone' => 'warning'];
    }

    $daysOverdue = rdomms_project_health_days_overdue(
        (string) ($project['end_date'] ?? $project['endDate'] ?? ''),
        $status
    );
    if ($daysOverdue > 0 && $status !== 'completed') {
        $alerts[] = [
            'key' => 'overdue',
            'label' => 'Past due date (' . $daysOverdue . ' days)',
            'tone' => 'danger',
        ];
    }

    if ($budget > 0 && in_array($status, ['ongoing', 'delayed'], true) && $spent <= 0 && $linkedCount === 0) {
        $alerts[] = [
            'key' => 'no_expenses',
            'label' => 'No expenses linked to this project',
            'tone' => 'warning',
        ];
    }

    $budgetApproval = rdomms_project_budget_status($project);
    if ($budget > 0 && $budgetApproval === 'pending') {
        $alerts[] = [
            'key' => 'budget_pending_approval',
            'label' => 'Budget awaiting approval',
            'tone' => 'warning',
        ];
    }
    if ($budget > 0 && $budgetApproval === 'rejected') {
        $alerts[] = [
            'key' => 'budget_rejected',
            'label' => 'Budget request was rejected',
            'tone' => 'danger',
        ];
    }

    $referenceDate = (string) ($project['start_date'] ?? $project['startDate'] ?? $project['created_at'] ?? '');
    $ageDays = rdomms_project_health_days_since($referenceDate);
    if ($status === 'ongoing' && $progress < 10 && $ageDays >= 30) {
        $alerts[] = [
            'key' => 'stalled',
            'label' => 'Low progress after ' . $ageDays . ' days',
            'tone' => 'warning',
        ];
    }

    $funding = trim((string) ($project['funding_source'] ?? $project['fundingSource'] ?? ''));
    if ($funding === '') {
        $alerts[] = ['key' => 'missing_funding', 'label' => 'Funding source not set', 'tone' => 'warning'];
    }

    $team = rdomms_project_health_parse_team($project['team'] ?? null);
    if (!$team) {
        $alerts[] = ['key' => 'no_team', 'label' => 'No team members assigned', 'tone' => 'info'];
    }

    if ($documentCount === 0 && in_array($status, ['ongoing', 'completed'], true)) {
        $alerts[] = ['key' => 'no_documents', 'label' => 'No project documents uploaded', 'tone' => 'info'];
    }

    return $alerts;
}

function rdomms_build_project_health_payload(PDO $conn, array $user, ?string $scopeMode = null): array
{
    ensureFinanceLinkSchema($conn);
    ensureProjectBudgetSchema($conn);

    $projectSql = 'SELECT * FROM projects WHERE 1=1';
    $projectParams = [];
    appendProjectListScopeFilter($projectSql, $projectParams, $user, $scopeMode);
    $projectSql .= ' ORDER BY name ASC LIMIT 500';

    $stmt = $conn->prepare($projectSql);
    $stmt->execute($projectParams);
    $projects = $stmt->fetchAll() ?: [];

    $publicIds = [];
    foreach ($projects as $project) {
        $publicIds[] = (string) ($project['project_id'] ?? $project['id'] ?? '');
    }
    $publicIds = array_values(array_filter($publicIds));

    $expenseTotals = rdomms_fetch_project_expense_totals($conn, $publicIds);
    $documentCounts = rdomms_fetch_project_document_counts($conn);

    $rows = [];
    $summary = [
        'total' => 0,
        'good' => 0,
        'warning' => 0,
        'critical' => 0,
        'totalBudget' => 0.0,
        'totalSpent' => 0.0,
        'alertCount' => 0,
    ];

    foreach ($projects as $project) {
        $projectId = (string) ($project['project_id'] ?? $project['id'] ?? '');
        $name = (string) ($project['name'] ?? 'Project');
        $budget = (float) ($project['budget'] ?? 0);
        $expenseRow = $expenseTotals[$projectId] ?? ['approved' => 0.0, 'pending' => 0.0, 'count' => 0];

        $finance = rdomms_finance_build_row_summary(
            $budget,
            (float) $expenseRow['approved'],
            (float) $expenseRow['pending']
        );
        $finance['linkedExpenseCount'] = (int) $expenseRow['count'];

        $docKey = strtolower(trim($name));
        $documentCount = (int) ($documentCounts[$docKey] ?? 0);
        $alerts = rdomms_project_health_build_alerts($project, $finance, $documentCount);
        $health = rdomms_project_health_resolve_level($alerts);
        $team = rdomms_project_health_parse_team($project['team'] ?? null);

        $fundingLabel = normalizeRdommsFundingSourceLabel(
            (string) ($project['funding_source'] ?? ''),
            (string) ($project['donor_name'] ?? '')
        );

        $rows[] = [
            'id' => $projectId,
            'projectId' => $projectId,
            'name' => $name,
            'status' => (string) ($project['status'] ?? 'unknown'),
            'progress' => (int) ($project['progress'] ?? 0),
            'budget' => round($budget, 2),
            'fundingSource' => $fundingLabel,
            'donorName' => (string) ($project['donor_name'] ?? ''),
            'location' => (string) ($project['location'] ?? $project['district'] ?? ''),
            'sector' => (string) ($project['sector'] ?? ''),
            'organization' => (string) ($project['organization'] ?? ''),
            'startDate' => (string) ($project['start_date'] ?? ''),
            'endDate' => (string) ($project['end_date'] ?? ''),
            'teamCount' => count($team),
            'documentCount' => $documentCount,
            'linkedExpenseCount' => (int) $expenseRow['count'],
            'spentApproved' => $finance['spentApproved'],
            'spentPending' => $finance['spentPending'],
            'spentCommitted' => $finance['spentCommitted'],
            'remaining' => $finance['remaining'],
            'utilizationPct' => $finance['utilizationPct'],
            'variance' => $finance['variance'],
            'budgetStatus' => $finance['status'],
            'budgetApprovalStatus' => rdomms_project_budget_status($project),
            'budgetCanOperate' => rdomms_project_can_operate($project),
            'budgetRejectReason' => (string) ($project['budget_reject_reason'] ?? ''),
            'health' => $health,
            'alerts' => $alerts,
            'daysOverdue' => rdomms_project_health_days_overdue(
                (string) ($project['end_date'] ?? ''),
                (string) ($project['status'] ?? '')
            ),
        ];

        $summary['total']++;
        $summary[$health]++;
        $summary['totalBudget'] += $budget;
        $summary['totalSpent'] += (float) $finance['spentCommitted'];
        $summary['alertCount'] += count($alerts);
    }

    $summary['totalBudget'] = round($summary['totalBudget'], 2);
    $summary['totalSpent'] = round($summary['totalSpent'], 2);
    $summary['totalRemaining'] = round($summary['totalBudget'] - $summary['totalSpent'], 2);

    usort($rows, static function (array $a, array $b): int {
        $rank = ['critical' => 0, 'warning' => 1, 'good' => 2];
        $aRank = $rank[$a['health']] ?? 3;
        $bRank = $rank[$b['health']] ?? 3;
        if ($aRank !== $bRank) {
            return $aRank <=> $bRank;
        }
        return strcasecmp($a['name'], $b['name']);
    });

    return [
        'summary' => $summary,
        'projects' => $rows,
        'generatedAt' => gmdate('c'),
    ];
}
