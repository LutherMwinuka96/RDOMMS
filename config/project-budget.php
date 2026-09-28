<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/dates.php';
require_once __DIR__ . '/departments.php';
require_once __DIR__ . '/notification-preferences.php';

function ensureProjectBudgetSchema(PDO $conn): void
{
    $columns = [
        'budget_approval_status' => "ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft'",
        'budget_submitted_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'budget_approved_by' => 'INT NULL DEFAULT NULL',
        'budget_approved_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'budget_rejected_by' => 'INT NULL DEFAULT NULL',
        'budget_reject_reason' => 'TEXT NULL DEFAULT NULL',
    ];

    foreach ($columns as $name => $definition) {
        try {
            $stmt = $conn->prepare('SHOW COLUMNS FROM projects LIKE ?');
            $stmt->execute([$name]);
            if (!$stmt->fetch()) {
                $conn->exec("ALTER TABLE projects ADD COLUMN {$name} {$definition}");
            }
        } catch (Throwable $e) {
            error_log('ensureProjectBudgetSchema: ' . $e->getMessage());
        }
    }

    try {
        $conn->exec(
            "UPDATE projects
             SET budget_approval_status = 'pending'
             WHERE budget > 0
               AND budget_submitted_at IS NOT NULL
               AND budget_approval_status = 'draft'"
        );
    } catch (Throwable $e) {
        error_log('ensureProjectBudgetSchema pending sync: ' . $e->getMessage());
    }

    rdomms_run_legacy_project_budget_backfill($conn);
}

/** SQL condition matching projects whose budget still needs approval. */
function rdomms_sql_projects_awaiting_budget_approval(): string
{
    return "(
        budget > 0
        AND (
            budget_approval_status = 'pending'
            OR (
                budget_approval_status = 'draft'
                AND budget_submitted_at IS NOT NULL
            )
            OR EXISTS (
                SELECT 1 FROM budget_allocations ba
                WHERE ba.linked_type = 'project'
                  AND ba.status = 'pending'
                  AND ba.linked_id COLLATE utf8mb4_unicode_ci = projects.project_id COLLATE utf8mb4_unicode_ci
            )
        )
    )";
}

/** One-time: legacy rows with budget but no submission timestamp were treated as pre-approved. */
function rdomms_run_legacy_project_budget_backfill(PDO $conn): void
{
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS app_storage (
                id INT AUTO_INCREMENT PRIMARY KEY,
                storage_key VARCHAR(190) NOT NULL UNIQUE,
                payload LONGTEXT,
                updated_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )"
        );
        $flagStmt = $conn->prepare('SELECT id FROM app_storage WHERE storage_key = ? LIMIT 1');
        $flagStmt->execute(['project_budget_legacy_backfill_v1']);
        if ($flagStmt->fetch()) {
            return;
        }

        $conn->exec(
            "UPDATE projects
             SET budget_approval_status = 'approved'
             WHERE budget > 0
               AND budget_approval_status = 'draft'
               AND budget_submitted_at IS NULL"
        );

        $insertFlag = $conn->prepare(
            'INSERT INTO app_storage (storage_key, payload, updated_by) VALUES (?, ?, NULL)'
        );
        $insertFlag->execute(['project_budget_legacy_backfill_v1', '"done"']);
    } catch (Throwable $e) {
        error_log('ensureProjectBudgetSchema backfill: ' . $e->getMessage());
    }
}

function rdomms_fetch_project_row(PDO $conn, string $projectId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM projects WHERE project_id = ? OR id = ? LIMIT 1');
    $stmt->execute([$projectId, $projectId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function rdomms_project_budget_status(array $project): string
{
    $status = strtolower(trim((string) ($project['budget_approval_status'] ?? 'draft')));
    if (
        $status === 'draft'
        && (float) ($project['budget'] ?? 0) > 0
        && !empty($project['budget_submitted_at'])
    ) {
        return 'pending';
    }

    return in_array($status, ['draft', 'pending', 'approved', 'rejected'], true) ? $status : 'draft';
}

function rdomms_project_budget_requires_approval(array $project): bool
{
    return (float) ($project['budget'] ?? 0) > 0;
}

function rdomms_project_budget_is_approved(array $project): bool
{
    if (!rdomms_project_budget_requires_approval($project)) {
        return true;
    }

    return rdomms_project_budget_status($project) === 'approved';
}

function rdomms_project_can_operate(array $project): bool
{
    return rdomms_project_budget_is_approved($project);
}

function rdomms_project_quarter_from_date(?string $date): string
{
    if ($date) {
        try {
            $month = (int) (new DateTimeImmutable($date))->format('n');
            return 'Q' . (int) ceil($month / 3);
        } catch (Throwable $e) {
            // fall through
        }
    }

    return rdomms_current_quarter_label();
}

function rdomms_project_department_from_row(array $project): string
{
    $sector = trim((string) ($project['sector'] ?? ''));
    if ($sector !== '') {
        $normalized = normalizeRdommsDepartment($sector);
        if ($normalized) {
            return $normalized;
        }
        return $sector;
    }

    return 'Administration';
}

function rdomms_find_pending_project_allocation(PDO $conn, string $projectPublicId): ?array
{
    require_once __DIR__ . '/finance-links.php';
    ensureFinanceLinkSchema($conn);
    $stmt = $conn->prepare(
        "SELECT * FROM budget_allocations
         WHERE linked_type = 'project'
           AND linked_id = ?
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $stmt->execute([$projectPublicId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function rdomms_create_project_budget_allocation(PDO $conn, array $project, int $createdBy): ?string
{
    require_once __DIR__ . '/finance-links.php';
    ensureFinanceLinkSchema($conn);

    $projectPublicId = (string) ($project['project_id'] ?? $project['id'] ?? '');
    $amount = (float) ($project['budget'] ?? 0);
    if ($projectPublicId === '' || $amount <= 0) {
        return null;
    }

    $existing = rdomms_find_pending_project_allocation($conn, $projectPublicId);
    if ($existing) {
        $stmt = $conn->prepare(
            'UPDATE budget_allocations
             SET amount = ?, department = ?, quarter = ?, fiscal_year = ?, station = ?, description = ?, status = \'pending\', linked_label = ?
             WHERE allocation_id = ? OR id = ?'
        );
        $stmt->execute([
            $amount,
            rdomms_project_department_from_row($project),
            rdomms_project_quarter_from_date((string) ($project['start_date'] ?? '')),
            (string) rdomms_current_year(),
            $project['organization'] ?? null,
            'Project budget request: ' . ($project['name'] ?? $projectPublicId),
            $project['name'] ?? $projectPublicId,
            $existing['allocation_id'] ?? $existing['id'],
            $existing['allocation_id'] ?? $existing['id'],
        ]);
        return (string) ($existing['allocation_id'] ?? $existing['id']);
    }

    $allocationId = 'ALLOC' . time() . rand(100, 999);
    $stmt = $conn->prepare(
        'INSERT INTO budget_allocations (allocation_id, department, quarter, fiscal_year, amount, station, description, status, created_by, linked_type, linked_id, linked_label)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $allocationId,
        rdomms_project_department_from_row($project),
        rdomms_project_quarter_from_date((string) ($project['start_date'] ?? '')),
        (string) rdomms_current_year(),
        $amount,
        $project['organization'] ?? null,
        'Project budget request: ' . ($project['name'] ?? $projectPublicId),
        'pending',
        $createdBy > 0 ? $createdBy : null,
        'project',
        $projectPublicId,
        (string) ($project['name'] ?? $projectPublicId),
    ]);

    return $allocationId;
}

function rdomms_user_can_approve_project_budget(array $user, array $project, ?PDO $conn = null): bool
{
    $role = normalizeRoleName($user['role'] ?? '');
    if (in_array($role, ['admin', 'accountant'], true)) {
        return true;
    }

    if ($role === 'head_of_organization_center') {
        return organizationsMatch($user['organization'] ?? '', $project['organization'] ?? '');
    }

    if ($role === 'head_of_department') {
        $userDept = trim((string) ($user['department'] ?? ''));
        $projectDept = rdomms_project_department_from_row($project);
        if (departmentsMatch($userDept, $projectDept)) {
            return organizationsMatch($user['organization'] ?? '', $project['organization'] ?? '');
        }

        if ($conn && (int) ($project['created_by'] ?? 0) > 0) {
            $creatorStmt = $conn->prepare('SELECT department, organization FROM users WHERE id = ? LIMIT 1');
            $creatorStmt->execute([(int) $project['created_by']]);
            $creator = $creatorStmt->fetch() ?: [];
            if (
                departmentsMatch($userDept, (string) ($creator['department'] ?? ''))
                && organizationsMatch($user['organization'] ?? '', (string) ($creator['organization'] ?? ''))
            ) {
                return true;
            }
        }
    }

    return false;
}

function rdomms_notify_project_budget_approvers(
    PDO $conn,
    array $project,
    string $title,
    string $message
): void {
    $stmt = $conn->query(
        "SELECT id, role, organization, department FROM users WHERE status = 'active'"
    );
    $rows = $stmt ? $stmt->fetchAll() : [];

    foreach ($rows as $row) {
        $approver = [
            'id' => (int) ($row['id'] ?? 0),
            'role' => $row['role'] ?? '',
            'organization' => $row['organization'] ?? '',
            'department' => $row['department'] ?? '',
        ];
        if ($approver['id'] <= 0 || !rdomms_user_can_approve_project_budget($approver, $project, $conn)) {
            continue;
        }

        rdomms_notify_user_account(
            $conn,
            'project_budget_request',
            $title,
            $message,
            'Projects',
            'warning',
            $approver['id']
        );
    }
}

function rdomms_notify_project_budget_event(
    PDO $conn,
    string $event,
    array $project,
    ?array $actor = null,
    ?string $reason = null
): void {
    $projectName = (string) ($project['name'] ?? 'Project');
    $projectId = (string) ($project['project_id'] ?? $project['id'] ?? '');
    $amount = (float) ($project['budget'] ?? 0);

    if ($event === 'submitted') {
        $detail = sprintf(
            'Project "%s" requested budget %s and is awaiting approval.',
            $projectName,
            number_format($amount, 0)
        );
        rdomms_notify_project_budget_approvers(
            $conn,
            $project,
            'Project budget pending approval',
            $detail
        );
        rdomms_dispatch_notification(
            $conn,
            'project_budget_request',
            'Project budget pending approval',
            $detail,
            'Projects',
            'warning'
        );
        return;
    }

    if ($event === 'approved') {
        $creatorId = (int) ($project['created_by'] ?? 0);
        if ($creatorId > 0) {
            rdomms_notify_user_account(
                $conn,
                'project_budget_request',
                'Project budget approved',
                sprintf('Your project "%s" budget of %s has been approved. You can now start the project and record expenses.', $projectName, number_format($amount, 0)),
                'Projects',
                'success',
                $creatorId
            );
        }
        return;
    }

    if ($event === 'rejected') {
        $creatorId = (int) ($project['created_by'] ?? 0);
        $detail = $reason ? (' Reason: ' . $reason) : '';
        if ($creatorId > 0) {
            rdomms_notify_user_account(
                $conn,
                'project_budget_request',
                'Project budget rejected',
                sprintf('Your project "%s" budget request was rejected.%s', $projectName, $detail),
                'Projects',
                'warning',
                $creatorId
            );
        }
    }
}

function rdomms_submit_project_budget_request(PDO $conn, array $project, int $userId): array
{
    $projectId = (string) ($project['project_id'] ?? $project['id'] ?? '');
    $amount = (float) ($project['budget'] ?? 0);
    if ($amount <= 0) {
        sendJSONResponse(['status' => 'error', 'message' => 'Set a budget amount before submitting for approval.'], 400);
    }

    $stmt = $conn->prepare(
        'UPDATE projects
         SET budget_approval_status = \'pending\',
             budget_submitted_at = NOW(),
             budget_rejected_by = NULL,
             budget_reject_reason = NULL,
             budget_approved_by = NULL,
             budget_approved_at = NULL
         WHERE project_id = ? OR id = ?'
    );
    $stmt->execute([$projectId, $projectId]);

    $updated = rdomms_fetch_project_row($conn, $projectId);
    if (!$updated) {
        sendJSONResponse(['status' => 'error', 'message' => 'Project not found'], 404);
    }

    rdomms_create_project_budget_allocation($conn, $updated, $userId);
    rdomms_notify_project_budget_event($conn, 'submitted', $updated);

    return $updated;
}

function rdomms_approve_linked_project_allocation(PDO $conn, string $projectPublicId, int $approverId): void
{
    require_once __DIR__ . '/finance-links.php';
    ensureFinanceLinkSchema($conn);
    $find = $conn->prepare(
        "SELECT allocation_id, id
         FROM budget_allocations
         WHERE linked_type = 'project'
           AND linked_id = ?
           AND status = 'pending'
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $find->execute([$projectPublicId]);
    $row = $find->fetch();
    if (!$row) {
        return;
    }

    $allocationKey = $row['allocation_id'] ?? $row['id'];
    $stmt = $conn->prepare(
        "UPDATE budget_allocations
         SET status = 'approved', approved_by = ?, approved_at = NOW()
         WHERE allocation_id = ? OR id = ?"
    );
    $stmt->execute([$approverId, $allocationKey, $allocationKey]);
}

function rdomms_approve_project_budget(PDO $conn, array $user, string $projectId): array
{
    $project = rdomms_fetch_project_row($conn, $projectId);
    if (!$project) {
        sendJSONResponse(['status' => 'error', 'message' => 'Project not found'], 404);
    }

    if (!rdomms_user_can_approve_project_budget($user, $project, $conn)) {
        sendJSONResponse(['status' => 'error', 'message' => 'You are not allowed to approve this project budget.'], 403);
    }

    if (rdomms_project_budget_status($project) === 'approved') {
        sendJSONResponse(['status' => 'success', 'message' => 'Project budget is already approved.', 'data' => $project]);
    }

    if (rdomms_project_budget_status($project) !== 'pending') {
        sendJSONResponse(['status' => 'error', 'message' => 'Only pending budget requests can be approved.'], 400);
    }

    $approverId = (int) ($user['id'] ?? 0);
    $stmt = $conn->prepare(
        'UPDATE projects
         SET budget_approval_status = \'approved\',
             budget_approved_by = ?,
             budget_approved_at = NOW(),
             budget_rejected_by = NULL,
             budget_reject_reason = NULL
         WHERE project_id = ? OR id = ?'
    );
    $stmt->execute([$approverId, $projectId, $projectId]);

    $publicId = (string) ($project['project_id'] ?? $projectId);
    rdomms_approve_linked_project_allocation($conn, $publicId, $approverId);

    $updated = rdomms_fetch_project_row($conn, $projectId);
    logActivity($approverId, 'Approved project budget', 'Projects', 'success', 'Approved budget for project ' . ($project['name'] ?? $projectId));
    rdomms_notify_project_budget_event($conn, 'approved', $updated ?: $project, $user);

    return $updated ?: $project;
}

function rdomms_reject_project_budget(PDO $conn, array $user, string $projectId, string $reason): array
{
    $project = rdomms_fetch_project_row($conn, $projectId);
    if (!$project) {
        sendJSONResponse(['status' => 'error', 'message' => 'Project not found'], 404);
    }

    if (!rdomms_user_can_approve_project_budget($user, $project, $conn)) {
        sendJSONResponse(['status' => 'error', 'message' => 'You are not allowed to reject this project budget.'], 403);
    }

    if (rdomms_project_budget_status($project) !== 'pending') {
        sendJSONResponse(['status' => 'error', 'message' => 'Only pending budget requests can be rejected.'], 400);
    }

    $reason = trim($reason);
    if ($reason === '') {
        sendJSONResponse(['status' => 'error', 'message' => 'A rejection reason is required.'], 400);
    }

    $rejecterId = (int) ($user['id'] ?? 0);
    $stmt = $conn->prepare(
        'UPDATE projects
         SET budget_approval_status = \'rejected\',
             budget_rejected_by = ?,
             budget_reject_reason = ?,
             budget_approved_by = NULL,
             budget_approved_at = NULL
         WHERE project_id = ? OR id = ?'
    );
    $stmt->execute([$rejecterId, $reason, $projectId, $projectId]);

    $publicId = (string) ($project['project_id'] ?? $projectId);
    $rejectAlloc = $conn->prepare(
        "UPDATE budget_allocations
         SET status = 'rejected'
         WHERE linked_type = 'project'
           AND linked_id = ?
           AND status = 'pending'"
    );
    $rejectAlloc->execute([$publicId]);

    $updated = rdomms_fetch_project_row($conn, $projectId);
    logActivity($rejecterId, 'Rejected project budget', 'Projects', 'warning', 'Rejected budget for project ' . ($project['name'] ?? $projectId));
    rdomms_notify_project_budget_event($conn, 'rejected', $updated ?: $project, $user, $reason);

    return $updated ?: $project;
}

function rdomms_guard_project_operational_update(array $current, array $incoming): void
{
    $currentStatus = strtolower((string) ($current['status'] ?? 'not-started'));
    $newStatus = array_key_exists('status', $incoming)
        ? strtolower((string) $incoming['status'])
        : $currentStatus;
    $currentProgress = (int) ($current['progress'] ?? 0);
    $newProgress = array_key_exists('progress', $incoming)
        ? (int) $incoming['progress']
        : $currentProgress;

    $starting = in_array($currentStatus, ['not-started', 'delayed'], true)
        && in_array($newStatus, ['ongoing', 'completed'], true);
    $progressing = $newProgress > $currentProgress;

    if (($starting || $progressing) && !rdomms_project_can_operate($current)) {
        $budgetStatus = rdomms_project_budget_status($current);
        $message = match ($budgetStatus) {
            'pending' => 'This project is waiting for budget approval. You cannot start or update progress until the budget is approved.',
            'rejected' => 'This project budget was rejected. Revise the budget and resubmit for approval before continuing.',
            default => 'Project budget must be approved before you can start or update progress.',
        };
        sendJSONResponse(['status' => 'error', 'message' => $message, 'code' => 'budget_not_approved'], 403);
    }
}

function rdomms_guard_project_linked_expense(PDO $conn, ?string $linkedType, ?string $linkedId): void
{
    $type = strtolower(trim((string) $linkedType));
    $id = trim((string) $linkedId);
    if ($type !== 'project' || $id === '') {
        return;
    }

    $project = rdomms_fetch_project_row($conn, $id);
    if (!$project) {
        sendJSONResponse(['status' => 'error', 'message' => 'Linked project was not found.'], 400);
    }

    if (!rdomms_project_can_operate($project)) {
        sendJSONResponse([
            'status' => 'error',
            'message' => 'Cannot record expenses for this project until its budget has been approved.',
            'code' => 'budget_not_approved',
        ], 403);
    }
}

function rdomms_map_project_to_client(array $project): array
{
    if (!empty($project['team']) && is_string($project['team'])) {
        $decoded = json_decode($project['team'], true);
        $project['team'] = is_array($decoded) ? $decoded : [];
    }

    $project['budget_approval_status'] = rdomms_project_budget_status($project);
    $project['budgetApprovalStatus'] = $project['budget_approval_status'];
    $project['budget_can_operate'] = rdomms_project_can_operate($project);
    $project['budgetCanOperate'] = $project['budget_can_operate'];

    if (function_exists('rdomms_enrich_project_deregistration_fields')) {
        $project = rdomms_enrich_project_deregistration_fields($project);
    }

    return $project;
}
