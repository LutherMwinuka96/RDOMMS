<?php
declare(strict_types=1);

function rdomms_ensure_notification_tables(PDO $conn): void
{
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS approval_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(50) UNIQUE NOT NULL,
            title VARCHAR(255) NOT NULL,
            request_type VARCHAR(100) DEFAULT NULL,
            module VARCHAR(100) DEFAULT NULL,
            priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
            requester VARCHAR(150) NOT NULL,
            description TEXT,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            reject_reason TEXT,
            created_by INT DEFAULT NULL,
            resolved_by INT DEFAULT NULL,
            resolved_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_module (module),
            INDEX idx_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS notification_threads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            thread_id VARCHAR(50) UNIQUE NOT NULL,
            participant_name VARCHAR(150) NOT NULL,
            participant_role VARCHAR(100) DEFAULT NULL,
            participant_status ENUM('online', 'away', 'offline') DEFAULT 'offline',
            owner_user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_owner (owner_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS notification_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id VARCHAR(50) UNIQUE NOT NULL,
            thread_id VARCHAR(50) NOT NULL,
            sender ENUM('me', 'them') NOT NULL DEFAULT 'me',
            body TEXT NOT NULL,
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_thread (thread_id),
            INDEX idx_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    try {
        $conn->exec("ALTER TABLE notifications ADD COLUMN category VARCHAR(50) DEFAULT 'system_alert' AFTER module");
    } catch (PDOException $e) {
        // Column may already exist.
    }
}

function rdomms_map_alert_severity_to_db(string $severity): string
{
    $value = strtolower(trim($severity));
    if ($value === 'critical') {
        return 'danger';
    }
    if (in_array($value, ['info', 'success', 'warning', 'danger'], true)) {
        return $value;
    }
    return 'info';
}

function rdomms_map_alert_severity_from_db(string $severity): string
{
    $value = strtolower(trim($severity));
    return $value === 'danger' ? 'critical' : $value;
}

/**
 * Admin sees every system alert; other users only see rows tied to their account.
 */
function rdomms_can_view_all_system_alerts(?array $user): bool
{
    return is_array($user) && userCanViewAllData($user);
}

function rdomms_system_alert_scope_sql(?array $user): array
{
    $where = "category = 'system_alert'";
    $params = [];

    if (!rdomms_can_view_all_system_alerts($user)) {
        $where .= ' AND user_id = ?';
        $params[] = (int) ($user['id'] ?? 0);
    }

    return ['where' => $where, 'params' => $params];
}

function rdomms_system_alert_visible_to_user(?array $user, ?int $rowUserId): bool
{
    if (rdomms_can_view_all_system_alerts($user)) {
        return true;
    }

    return (int) ($user['id'] ?? 0) > 0 && (int) ($user['id'] ?? 0) === (int) $rowUserId;
}

function rdomms_format_system_alert_row(array $row): array
{
    return [
        'id' => $row['notification_id'] ?? $row['id'] ?? '',
        'notification_id' => $row['notification_id'] ?? $row['id'] ?? '',
        'title' => $row['title'] ?? '',
        'message' => $row['message'] ?? '',
        'severity' => rdomms_map_alert_severity_from_db((string) ($row['severity'] ?? 'info')),
        'module' => $row['module'] ?? 'System',
        'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
        'is_read' => !empty($row['is_read']),
        'read' => !empty($row['is_read']),
        'created_at' => $row['created_at'] ?? '',
        'createdAt' => $row['created_at'] ?? '',
        'scope' => isset($row['user_id']) && $row['user_id'] !== null ? 'account' : 'system',
    ];
}
