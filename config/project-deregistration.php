<?php
declare(strict_types=1);

require_once __DIR__ . '/project-budget.php';

function ensureProjectDeregistrationSchema(PDO $conn): void
{
    ensureProjectBudgetSchema($conn);

    $columns = [
        'deregistration_status' => "ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none'",
        'deregistration_requested_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'deregistration_requested_by' => 'INT NULL DEFAULT NULL',
        'deregistration_reason' => 'TEXT NULL DEFAULT NULL',
        'deregistration_approved_by' => 'INT NULL DEFAULT NULL',
        'deregistration_approved_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'deregistration_rejected_by' => 'INT NULL DEFAULT NULL',
        'deregistration_reject_reason' => 'TEXT NULL DEFAULT NULL',
    ];

    foreach ($columns as $name => $definition) {
        try {
            $stmt = $conn->prepare('SHOW COLUMNS FROM projects LIKE ?');
            $stmt->execute([$name]);
            if (!$stmt->fetch()) {
                $conn->exec("ALTER TABLE projects ADD COLUMN {$name} {$definition}");
            }
        } catch (Throwable $e) {
            error_log('ensureProjectDeregistrationSchema: ' . $e->getMessage());
        }
    }
}

function rdomms_project_deregistration_status(array $project): string
{
    $status = strtolower(trim((string) ($project['deregistration_status'] ?? 'none')));
    return in_array($status, ['none', 'pending', 'approved', 'rejected'], true) ? $status : 'none';
}

function rdomms_user_can_approve_project_deregistration(array $user, array $project, ?PDO $conn = null): bool
{
    return rdomms_user_can_approve_project_budget($user, $project, $conn);
}

function rdomms_enrich_project_deregistration_fields(array $project): array
{
    $status = rdomms_project_deregistration_status($project);
    $project['deregistration_status'] = $status;
    $project['deregistrationStatus'] = $status;
    $project['deregistration_reason'] = (string) ($project['deregistration_reason'] ?? '');
    $project['deregistrationReason'] = $project['deregistration_reason'];
    $project['deregistration_reject_reason'] = (string) ($project['deregistration_reject_reason'] ?? '');
    $project['deregistrationRejectReason'] = $project['deregistration_reject_reason'];
    $project['deregistration_pending'] = $status === 'pending';
    $project['deregistrationPending'] = $project['deregistration_pending'];

    return $project;
}

function rdomms_guard_project_deregistration_pending(array $project): void
{
    if (rdomms_project_deregistration_status($project) === 'pending') {
        sendJSONResponse([
            'status' => 'error',
            'message' => 'This project has a pending removal request. Wait for approval or cancel the request before making changes.',
            'code' => 'deregistration_pending',
        ], 403);
    }
}

function rdomms_notify_project_deregistration_event(
    PDO $conn,
    string $event,
    array $project,
    ?array $actor = null,
    ?string $reason = null
): void {
    $projectName = (string) ($project['name'] ?? 'Project');
    $detail = $reason ? (' Reason: ' . $reason) : '';

    if ($event === 'requested') {
        rdomms_dispatch_notification(
            $conn,
            'project_deregistration',
            'Project removal requested',
            sprintf('Removal was requested for project "%s".%s', $projectName, $detail),
            'Projects',
            'warning'
        );
        return;
    }

    $creatorId = (int) ($project['created_by'] ?? 0);
    if ($creatorId <= 0) {
        return;
    }

    if ($event === 'approved') {
        rdomms_notify_user_account(
            $conn,
            'project_deregistration',
            'Project removed',
            sprintf('Your project "%s" and its linked budget/expenses were removed after approval.', $projectName),
            'Projects',
            'info',
            $creatorId
        );
        return;
    }

    if ($event === 'rejected') {
        rdomms_notify_user_account(
            $conn,
            'project_deregistration',
            'Project removal rejected',
            sprintf('The removal request for project "%s" was rejected.%s', $projectName, $detail),
            'Projects',
            'warning',
            $creatorId
        );
    }
}

function rdomms_count_project_linked_records(PDO $conn, array $project): array
{
    require_once __DIR__ . '/finance-links.php';
    ensureFinanceLinkSchema($conn);

    $publicId = (string) ($project['project_id'] ?? $project['id'] ?? '');
    $projectName = (string) ($project['name'] ?? '');

    $counts = [
        'allocations' => 0,
        'expenses' => 0,
        'progress_updates' => 0,
        'documents' => 0,
    ];

    if ($publicId !== '') {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM budget_allocations WHERE linked_type = 'project' AND linked_id = ?");
        $stmt->execute([$publicId]);
        $counts['allocations'] = (int) ($stmt->fetch()['c'] ?? 0);

        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM expenses WHERE linked_type = 'project' AND linked_id = ?");
        $stmt->execute([$publicId]);
        $counts['expenses'] = (int) ($stmt->fetch()['c'] ?? 0);
    }

    if ($projectName !== '') {
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM progress_updates WHERE project_name = ?');
        $stmt->execute([$projectName]);
        $counts['progress_updates'] = (int) ($stmt->fetch()['c'] ?? 0);

        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM project_documents WHERE project_name = ?');
        $stmt->execute([$projectName]);
        $counts['documents'] = (int) ($stmt->fetch()['c'] ?? 0);
    }

    return $counts;
}

function rdomms_execute_project_deregistration(PDO $conn, array $project, int $actorId): array
{
    require_once __DIR__ . '/finance-links.php';
    ensureFinanceLinkSchema($conn);

    $publicId = (string) ($project['project_id'] ?? $project['id'] ?? '');
    $projectName = (string) ($project['name'] ?? '');
    if ($publicId === '') {
        sendJSONResponse(['status' => 'error', 'message' => 'Project identifier missing.'], 400);
    }

    $deleted = rdomms_count_project_linked_records($conn, $project);

    if ($publicId !== '') {
        $stmt = $conn->prepare("DELETE FROM budget_allocations WHERE linked_type = 'project' AND linked_id = ?");
        $stmt->execute([$publicId]);

        $stmt = $conn->prepare("DELETE FROM expenses WHERE linked_type = 'project' AND linked_id = ?");
        $stmt->execute([$publicId]);
    }

    if ($projectName !== '') {
        $stmt = $conn->prepare('DELETE FROM progress_updates WHERE project_name = ?');
        $stmt->execute([$projectName]);

        $stmt = $conn->prepare('DELETE FROM project_documents WHERE project_name = ?');
        $stmt->execute([$projectName]);
    }

    $stmt = $conn->prepare('DELETE FROM projects WHERE project_id = ? OR id = ?');
    $stmt->execute([$publicId, $publicId]);

    if ($stmt->rowCount() <= 0) {
        sendJSONResponse(['status' => 'error', 'message' => 'Project not found or already removed.'], 404);
    }

    logActivity(
        $actorId,
        'Deregistered project',
        'Projects',
        'warning',
        sprintf(
            'Removed project %s (%s) with %d allocation(s), %d expense(s), %d progress update(s), %d document(s).',
            $projectName !== '' ? $projectName : $publicId,
            $publicId,
            $deleted['allocations'],
            $deleted['expenses'],
            $deleted['progress_updates'],
            $deleted['documents']
        )
    );

    return $deleted;
}

function rdomms_request_project_deregistration(PDO $conn, array $user, string $projectId, string $reason): array
{
    $project = rdomms_fetch_project_row($conn, $projectId);
    if (!$project) {
        sendJSONResponse(['status' => 'error', 'message' => 'Project not found'], 404);
    }

    $reason = trim($reason);
    if ($reason === '') {
        sendJSONResponse(['status' => 'error', 'message' => 'A reason is required to request project removal.'], 400);
    }

    $status = rdomms_project_deregistration_status($project);
    if ($status === 'pending') {
        sendJSONResponse(['status' => 'error', 'message' => 'A removal request is already pending for this project.'], 400);
    }

    $requesterId = (int) ($user['id'] ?? 0);
    $stmt = $conn->prepare(
        'UPDATE projects
         SET deregistration_status = \'pending\',
             deregistration_requested_at = NOW(),
             deregistration_requested_by = ?,
             deregistration_reason = ?,
             deregistration_approved_by = NULL,
             deregistration_approved_at = NULL,
             deregistration_rejected_by = NULL,
             deregistration_reject_reason = NULL
         WHERE project_id = ? OR id = ?'
    );
    $stmt->execute([$requesterId, $reason, $projectId, $projectId]);

    $updated = rdomms_fetch_project_row($conn, $projectId);
    rdomms_notify_project_deregistration_event($conn, 'requested', $updated ?: $project, $user, $reason);

    return $updated ?: $project;
}

function rdomms_cancel_project_deregistration(PDO $conn, array $user, string $projectId): array
{
    $project = rdomms_fetch_project_row($conn, $projectId);
    if (!$project) {
        sendJSONResponse(['status' => 'error', 'message' => 'Project not found'], 404);
    }

    if (rdomms_project_deregistration_status($project) !== 'pending') {
        sendJSONResponse(['status' => 'error', 'message' => 'No pending removal request to cancel.'], 400);
    }

    $stmt = $conn->prepare(
        'UPDATE projects
         SET deregistration_status = \'none\',
             deregistration_requested_at = NULL,
             deregistration_requested_by = NULL,
             deregistration_reason = NULL,
             deregistration_rejected_by = NULL,
             deregistration_reject_reason = NULL
         WHERE project_id = ? OR id = ?'
    );
    $stmt->execute([$projectId, $projectId]);

    return rdomms_fetch_project_row($conn, $projectId) ?: $project;
}

function rdomms_approve_project_deregistration(PDO $conn, array $user, string $projectId): array
{
    $project = rdomms_fetch_project_row($conn, $projectId);
    if (!$project) {
        sendJSONResponse(['status' => 'error', 'message' => 'Project not found'], 404);
    }

    if (!rdomms_user_can_approve_project_deregistration($user, $project, $conn)) {
        sendJSONResponse(['status' => 'error', 'message' => 'You are not allowed to approve project removal.'], 403);
    }

    if (rdomms_project_deregistration_status($project) !== 'pending') {
        sendJSONResponse(['status' => 'error', 'message' => 'Only pending removal requests can be approved.'], 400);
    }

    $approverId = (int) ($user['id'] ?? 0);
    $deleted = rdomms_execute_project_deregistration($conn, $project, $approverId);
    rdomms_notify_project_deregistration_event($conn, 'approved', $project, $user);

    return [
        'project_id' => (string) ($project['project_id'] ?? $projectId),
        'project_name' => (string) ($project['name'] ?? ''),
        'deleted' => $deleted,
    ];
}

function rdomms_reject_project_deregistration(PDO $conn, array $user, string $projectId, string $reason): array
{
    $project = rdomms_fetch_project_row($conn, $projectId);
    if (!$project) {
        sendJSONResponse(['status' => 'error', 'message' => 'Project not found'], 404);
    }

    if (!rdomms_user_can_approve_project_deregistration($user, $project, $conn)) {
        sendJSONResponse(['status' => 'error', 'message' => 'You are not allowed to reject project removal.'], 403);
    }

    if (rdomms_project_deregistration_status($project) !== 'pending') {
        sendJSONResponse(['status' => 'error', 'message' => 'Only pending removal requests can be rejected.'], 400);
    }

    $reason = trim($reason);
    if ($reason === '') {
        sendJSONResponse(['status' => 'error', 'message' => 'A rejection reason is required.'], 400);
    }

    $rejecterId = (int) ($user['id'] ?? 0);
    $stmt = $conn->prepare(
        'UPDATE projects
         SET deregistration_status = \'rejected\',
             deregistration_rejected_by = ?,
             deregistration_reject_reason = ?
         WHERE project_id = ? OR id = ?'
    );
    $stmt->execute([$rejecterId, $reason, $projectId, $projectId]);

    $updated = rdomms_fetch_project_row($conn, $projectId);
    rdomms_notify_project_deregistration_event($conn, 'rejected', $updated ?: $project, $user, $reason);

    return $updated ?: $project;
}

function rdomms_admin_deregister_project(PDO $conn, array $user, string $projectId, string $reason): array
{
    if (normalizeRoleName($user['role'] ?? '') !== 'admin') {
        sendJSONResponse(['status' => 'error', 'message' => 'Only administrators can remove a project immediately.'], 403);
    }

    $project = rdomms_fetch_project_row($conn, $projectId);
    if (!$project) {
        sendJSONResponse(['status' => 'error', 'message' => 'Project not found'], 404);
    }

    $reason = trim($reason);
    if ($reason === '') {
        sendJSONResponse(['status' => 'error', 'message' => 'A reason is required for immediate project removal.'], 400);
    }

    $deleted = rdomms_execute_project_deregistration($conn, $project, (int) ($user['id'] ?? 0));
    rdomms_notify_project_deregistration_event($conn, 'approved', $project, $user, $reason);

    return [
        'project_id' => (string) ($project['project_id'] ?? $projectId),
        'project_name' => (string) ($project['name'] ?? ''),
        'deleted' => $deleted,
    ];
}
