<?php

function getPlanTableName($type) {
    $map = [
        'monthly' => 'monthly_plans',
        'quarterly' => 'quarterly_plans',
        'annual' => 'annual_plans',
    ];

    $type = strtolower(trim((string) $type));
    if (!isset($map[$type])) {
        sendJSONResponse(['status' => 'error', 'message' => 'Invalid plan type. Use monthly, quarterly, or annual.'], 400);
    }

    return $map[$type];
}

function normalizePlanTypeLabel($type) {
    $labels = [
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'annual' => 'Annual',
    ];

    return $labels[strtolower(trim((string) $type))] ?? 'Plan';
}

function decodeJsonColumn($value, $fallback = []) {
    if (is_array($value)) {
        return $value;
    }

    if ($value === null || $value === '') {
        return $fallback;
    }

    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function mapPlanRowToClient($row, $type) {
    $activities = decodeJsonColumn($row['activities'] ?? null);

    $payload = [
        'id' => $row['plan_id'],
        'plan_id' => $row['plan_id'],
        'year' => isset($row['year']) ? (int) $row['year'] : null,
        'staffName' => $row['staff_name'] ?? '',
        'staff_name' => $row['staff_name'] ?? '',
        'department' => $row['department'] ?? '',
        'station' => $row['station'] ?? '',
        'objective' => $row['objective'] ?? '',
        'notes' => $row['notes'] ?? '',
        'activities' => $activities,
        'total_budget' => isset($row['total_budget']) ? (float) $row['total_budget'] : 0,
        'approval_status' => $row['approval_status'] ?? 'draft',
        'approvalStatus' => $row['approval_status'] ?? 'draft',
        'status' => $row['approval_status'] ?? 'draft',
        'reject_reason' => $row['reject_reason'] ?? '',
        'rejectReason' => $row['reject_reason'] ?? '',
        'approved_by' => $row['approved_by'] ?? '',
        'approvedBy' => $row['approved_by'] ?? '',
        'rejected_by' => $row['rejected_by'] ?? '',
        'rejectedBy' => $row['rejected_by'] ?? '',
        'submitted_at' => $row['submitted_at'] ?? null,
        'submittedAt' => $row['submitted_at'] ?? null,
        'saved_at' => $row['saved_at'] ?? null,
        'savedAt' => $row['saved_at'] ?? null,
        'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
        'type' => normalizePlanTypeLabel($type),
        'storageKey' => strtolower(normalizePlanTypeLabel($type)) . 'Plans',
    ];

    if ($type === 'monthly') {
        $payload['month'] = $row['month'] ?? '';
    } elseif ($type === 'quarterly') {
        $payload['quarter'] = $row['quarter'] ?? '';
    }

    return $payload;
}

function buildPlanId($type, array $data) {
    if (!empty($data['id'])) {
        return (string) $data['id'];
    }
    if (!empty($data['plan_id'])) {
        return (string) $data['plan_id'];
    }

    $year = (int) ($data['year'] ?? date('Y'));

    if ($type === 'monthly') {
        $month = trim((string) ($data['month'] ?? ''));
        return $year . '-' . $month;
    }

    if ($type === 'quarterly') {
        $quarter = trim((string) ($data['quarter'] ?? 'Q1'));
        return $year . '-' . $quarter;
    }

    return (string) $year;
}

function normalizeApprovalStatus($value, $default = 'draft') {
    $status = strtolower(trim((string) $value));
    $allowed = ['draft', 'pending', 'approved', 'rejected'];
    return in_array($status, $allowed, true) ? $status : $default;
}

function extractPlanPayload(array $data, $type) {
    $activities = $data['activities'] ?? [];
    if (!is_array($activities)) {
        $activities = [];
    }

    $totalBudget = $data['total_budget'] ?? null;
    if ($totalBudget === null) {
        $totalBudget = 0;
        foreach ($activities as $activity) {
            if (!is_array($activity)) {
                continue;
            }
            $totalBudget += (float) ($activity['budget'] ?? 0);
        }
    }

    $approvalStatus = normalizeApprovalStatus(
        $data['approval_status'] ?? $data['approvalStatus'] ?? $data['status'] ?? 'draft'
    );

    $submittedAt = $data['submitted_at'] ?? $data['submittedAt'] ?? null;
    if ($approvalStatus === 'pending' && empty($submittedAt)) {
        $submittedAt = date('Y-m-d H:i:s');
    }

    return [
        'plan_id' => buildPlanId($type, $data),
        'staff_name' => trim((string) ($data['staffName'] ?? $data['staff_name'] ?? '')),
        'department' => trim((string) ($data['department'] ?? '')),
        'station' => trim((string) ($data['station'] ?? '')),
        'objective' => trim((string) ($data['objective'] ?? '')),
        'notes' => trim((string) ($data['notes'] ?? '')),
        'activities' => json_encode($activities, JSON_UNESCAPED_UNICODE),
        'total_budget' => (float) $totalBudget,
        'approval_status' => $approvalStatus,
        'submitted_at' => $submittedAt,
        'saved_at' => date('Y-m-d H:i:s'),
        'year' => (int) ($data['year'] ?? date('Y')),
        'month' => $type === 'monthly' ? trim((string) ($data['month'] ?? '')) : null,
        'quarter' => $type === 'quarterly' ? trim((string) ($data['quarter'] ?? 'Q1')) : null,
    ];
}

function mapTargetRowToClient($row) {
    $history = decodeJsonColumn($row['progress_history'] ?? null);

    return [
        'id' => $row['target_id'],
        'target_id' => $row['target_id'],
        'name' => $row['name'] ?? '',
        'category' => $row['category'] ?? '',
        'period' => $row['period'] ?? '',
        'targetValue' => isset($row['target_value']) ? (float) $row['target_value'] : 0,
        'target_value' => isset($row['target_value']) ? (float) $row['target_value'] : 0,
        'currentValue' => isset($row['current_value']) ? (float) $row['current_value'] : 0,
        'current_value' => isset($row['current_value']) ? (float) $row['current_value'] : 0,
        'unit' => $row['unit'] ?? '',
        'description' => $row['description'] ?? '',
        'status' => $row['status'] ?? 'not_started',
        'createdAt' => $row['created_at'] ?? null,
        'updatedAt' => $row['updated_at'] ?? null,
        'progressHistory' => $history,
    ];
}

function mapProgressRowToClient($row) {
    return [
        'id' => $row['update_id'],
        'update_id' => $row['update_id'],
        'projectName' => $row['project_name'] ?? '',
        'activityName' => $row['activity_name'] ?? '',
        'station' => $row['station'] ?? '',
        'department' => $row['department'] ?? '',
        'startDate' => $row['start_date'] ?? '',
        'endDate' => $row['end_date'] ?? '',
        'progress' => isset($row['progress']) ? (int) $row['progress'] : 0,
        'status' => $row['status'] ?? 'behind',
        'description' => $row['description'] ?? '',
        'challenges' => $row['challenges'] ?? '',
        'updatedAt' => $row['updated_at'] ?? $row['created_at'] ?? null,
    ];
}

function mapEvidenceRowToClient($row) {
    return [
        'id' => $row['evidence_id'],
        'evidence_id' => $row['evidence_id'],
        'title' => $row['title'] ?? '',
        'type' => $row['evidence_type'] ?? 'other',
        'relatedActivity' => $row['related_activity'] ?? '',
        'date' => $row['evidence_date'] ?? '',
        'location' => $row['location'] ?? '',
        'station' => $row['station'] ?? '',
        'department' => $row['department'] ?? '',
        'uploadedBy' => $row['uploaded_by'] ?? '',
        'description' => $row['description'] ?? '',
        'files' => decodeJsonColumn($row['files'] ?? null),
        'createdAt' => $row['created_at'] ?? null,
        'updatedAt' => $row['updated_at'] ?? null,
    ];
}

function planningTableExists($conn, $table) {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function planningColumnExists($conn, $table, $column) {
    if (!planningTableExists($conn, $table)) {
        return false;
    }

    $stmt = $conn->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}

/** Creates missing planning tables/columns on existing databases. */
function ensurePlanningSchema($conn) {
    static $ensured = false;
    if ($ensured || !$conn) {
        return;
    }

    $conn->exec(
        'CREATE TABLE IF NOT EXISTS progress_updates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            update_id VARCHAR(80) UNIQUE NOT NULL,
            project_name VARCHAR(200) NOT NULL,
            activity_name VARCHAR(200) NOT NULL,
            station VARCHAR(100),
            department VARCHAR(100),
            start_date DATE,
            end_date DATE,
            progress TINYINT UNSIGNED DEFAULT 0,
            status VARCHAR(50) DEFAULT \'behind\',
            description TEXT,
            challenges TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_created_by (created_by),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $conn->exec(
        'CREATE TABLE IF NOT EXISTS project_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id VARCHAR(80) UNIQUE NOT NULL,
            title VARCHAR(200) NOT NULL,
            category VARCHAR(50) NOT NULL DEFAULT \'project-evidence\',
            project_name VARCHAR(200),
            department VARCHAR(100),
            owner VARCHAR(100),
            document_date DATE,
            status VARCHAR(50) DEFAULT \'draft\',
            notes TEXT,
            files JSON,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_category (category),
            INDEX idx_status (status),
            INDEX idx_created_by (created_by),
            INDEX idx_document_date (document_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $conn->exec(
        'CREATE TABLE IF NOT EXISTS planning_evidence (
            id INT AUTO_INCREMENT PRIMARY KEY,
            evidence_id VARCHAR(80) UNIQUE NOT NULL,
            title VARCHAR(200) NOT NULL,
            evidence_type VARCHAR(50),
            related_activity VARCHAR(200),
            evidence_date DATE,
            location VARCHAR(200),
            station VARCHAR(100),
            department VARCHAR(100),
            uploaded_by VARCHAR(100),
            description TEXT,
            files JSON,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_created_by (created_by),
            INDEX idx_evidence_date (evidence_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    if (planningTableExists($conn, 'targets')) {
        if (!planningColumnExists($conn, 'targets', 'progress_history')) {
            $conn->exec('ALTER TABLE targets ADD COLUMN progress_history JSON NULL AFTER status');
        }
        if (!planningColumnExists($conn, 'targets', 'created_by')) {
            $conn->exec('ALTER TABLE targets ADD COLUMN created_by INT NULL AFTER progress_history');
        }
    }

    foreach (['monthly_plans', 'quarterly_plans', 'annual_plans'] as $planTable) {
        if (!planningTableExists($conn, $planTable)) {
            continue;
        }
        foreach ([
            'objective' => 'TEXT NULL',
            'notes' => 'TEXT NULL',
            'reject_reason' => 'TEXT NULL',
            'approved_by' => 'VARCHAR(100) NULL',
            'rejected_by' => 'VARCHAR(100) NULL',
            'created_by' => 'INT NULL',
            'saved_at' => 'TIMESTAMP NULL',
        ] as $column => $definition) {
            if (!planningColumnExists($conn, $planTable, $column)) {
                $conn->exec("ALTER TABLE `{$planTable}` ADD COLUMN `{$column}` {$definition}");
            }
        }
    }

    require_once __DIR__ . '/../config/planning-bootstrap.php';
    rdomms_ensure_planning_workspace_ready($conn);

    $ensured = true;
}

function mapProjectDocumentRowToClient($row) {
    $statusLabels = [
        'draft' => 'Draft',
        'review' => 'Pending Review',
        'ready' => 'Ready',
        'archived' => 'Archived',
    ];
    $categoryLabels = [
        'project-evidence' => 'Project Evidence',
        'department-plans' => 'Department Plans',
        'reports' => 'Reports',
        'contracts' => 'Contracts',
        'uploads' => 'Supporting Uploads',
        'archive' => 'Archive Intake',
    ];

    $status = strtolower(trim((string) ($row['status'] ?? 'draft')));
    $category = trim((string) ($row['category'] ?? 'project-evidence'));

    return [
        'id' => $row['document_id'],
        'document_id' => $row['document_id'],
        'title' => $row['title'] ?? '',
        'category' => $category,
        'categoryLabel' => $categoryLabels[$category] ?? $category,
        'project' => $row['project_name'] ?? '',
        'project_name' => $row['project_name'] ?? '',
        'department' => $row['department'] ?? '',
        'owner' => $row['owner'] ?? '',
        'updated' => $row['document_date'] ?? '',
        'document_date' => $row['document_date'] ?? '',
        'updatedLabel' => $row['updated_at'] ?? $row['created_at'] ?? '',
        'status' => $status,
        'statusLabel' => $statusLabels[$status] ?? ucfirst($status),
        'notes' => $row['notes'] ?? '',
        'files' => decodeJsonColumn($row['files'] ?? null),
        'createdAt' => $row['created_at'] ?? null,
        'updatedAt' => $row['updated_at'] ?? null,
    ];
}

function handlePlanningApiError(Throwable $e) {
    error_log('Planning API error: ' . $e->getMessage());
    $message = 'Database error. Please run api/install.php or database/migrations/planning_integration.sql.';
    if ($e instanceof PDOException) {
        $message = 'Database error: ' . $e->getMessage();
    }
    sendJSONResponse(['status' => 'error', 'message' => $message], 500);
}
