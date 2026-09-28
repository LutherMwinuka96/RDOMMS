<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/system-settings.php';

function rdomms_backup_settings_defaults(): array
{
    return [
        'auto_enabled' => false,
        'auto_frequency' => 'weekly',
        'retention_count' => 10,
        'last_auto_backup' => null,
    ];
}

function rdomms_backup_restore_order(): array
{
    return [
        'organizations',
        'departments',
        'roles',
        'users',
        'system_settings',
        'projects',
        'budget_allocations',
        'expenses',
        'beneficiaries',
        'service_tracking',
        'monthly_plans',
        'quarterly_plans',
        'annual_plans',
        'targets',
        'progress_updates',
        'project_documents',
        'planning_evidence',
        'notifications',
        'approval_requests',
        'notification_threads',
        'notification_messages',
        'activity_logs',
        'scheduled_reports',
        'report_history',
        'user_notification_preferences',
    ];
}

function rdomms_backup_excluded_tables(): array
{
    return [
        'user_sessions',
        'password_reset_tokens',
        'system_backups',
    ];
}

function rdomms_backup_storage_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/backups';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create backup storage directory.');
    }
    return $dir;
}

function rdomms_ensure_backup_storage(PDO $conn): void
{
    rdomms_ensure_system_settings_table($conn);
    rdomms_backup_storage_dir();

    $conn->exec(
        'CREATE TABLE IF NOT EXISTS system_backups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            backup_id VARCHAR(50) UNIQUE NOT NULL,
            filename VARCHAR(255) NOT NULL,
            file_size BIGINT DEFAULT 0,
            backup_type VARCHAR(20) DEFAULT "manual",
            tables_count INT DEFAULT 0,
            record_count INT DEFAULT 0,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $defaults = json_encode(rdomms_backup_settings_defaults());
    $stmt = $conn->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, setting_type)
         VALUES (?, ?, "json")
         ON DUPLICATE KEY UPDATE setting_key = setting_key'
    );
    $stmt->execute(['backup_settings', $defaults]);
}

function rdomms_table_exists(PDO $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (int) (($stmt->fetch()['total'] ?? 0)) > 0;
}

function rdomms_discover_backup_tables(PDO $conn): array
{
    $preferred = rdomms_backup_restore_order();
    $excluded = array_flip(rdomms_backup_excluded_tables());
    $available = [];

    foreach ($preferred as $table) {
        if (!isset($excluded[$table]) && rdomms_table_exists($conn, $table)) {
            $available[] = $table;
        }
    }

    $stmt = $conn->query('SHOW TABLES');
    $allTables = [];
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $allTables[] = (string) $row[0];
    }

    foreach ($allTables as $table) {
        if (isset($excluded[$table]) || in_array($table, $available, true)) {
            continue;
        }
        if (str_starts_with($table, 'system_backups')) {
            continue;
        }
        $available[] = $table;
    }

    return $available;
}

function rdomms_load_backup_settings(PDO $conn): array
{
    rdomms_ensure_backup_storage($conn);

    $stmt = $conn->prepare(
        'SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1'
    );
    $stmt->execute(['backup_settings']);
    $row = $stmt->fetch();
    $decoded = [];

    if ($row && !empty($row['setting_value'])) {
        $parsed = json_decode((string) $row['setting_value'], true);
        if (is_array($parsed)) {
            $decoded = $parsed;
        }
    }

    return array_replace_recursive(rdomms_backup_settings_defaults(), $decoded);
}

function rdomms_save_backup_settings(PDO $conn, array $input): array
{
    rdomms_ensure_backup_storage($conn);
    $settings = rdomms_load_backup_settings($conn);

    if (array_key_exists('auto_enabled', $input) || array_key_exists('autoEnabled', $input)) {
        $settings['auto_enabled'] = rdomms_is_truthy($input['auto_enabled'] ?? $input['autoEnabled'] ?? false);
    }

    $frequency = $input['auto_frequency'] ?? $input['autoFrequency'] ?? null;
    if ($frequency && in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
        $settings['auto_frequency'] = $frequency;
    }

    if (isset($input['retention_count']) || isset($input['retentionCount'])) {
        $settings['retention_count'] = max(1, min(50, (int) ($input['retention_count'] ?? $input['retentionCount'] ?? 10)));
    }

    $stmt = $conn->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, setting_type)
         VALUES (?, ?, "json")
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute(['backup_settings', json_encode($settings)]);

    return $settings;
}

function rdomms_backup_settings_for_client(array $settings): array
{
    return [
        'auto_enabled' => !empty($settings['auto_enabled']),
        'autoEnabled' => !empty($settings['auto_enabled']),
        'auto_frequency' => $settings['auto_frequency'] ?? 'weekly',
        'autoFrequency' => $settings['auto_frequency'] ?? 'weekly',
        'retention_count' => (int) ($settings['retention_count'] ?? 10),
        'retentionCount' => (int) ($settings['retention_count'] ?? 10),
        'last_auto_backup' => $settings['last_auto_backup'] ?? null,
        'lastAutoBackup' => $settings['last_auto_backup'] ?? null,
    ];
}

function rdomms_export_database_backup(PDO $conn, ?int $createdBy = null, string $type = 'manual'): array
{
    rdomms_ensure_backup_storage($conn);

    $tables = rdomms_discover_backup_tables($conn);
    $payload = [
        'meta' => [
            'app' => 'RDOMMS',
            'version' => rdomms_load_general_settings($conn)['app_version'] ?? '2.0.0',
            'created_at' => gmdate('c'),
            'backup_type' => $type,
            'tables' => $tables,
        ],
        'data' => [],
    ];

    $recordCount = 0;
    foreach ($tables as $table) {
        $stmt = $conn->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $payload['data'][$table] = $rows;
        $recordCount += count($rows);
    }

    $backupId = 'BKP' . time() . rand(100, 999);
    $filename = $backupId . '.json';
    $path = rdomms_backup_storage_dir() . '/' . $filename;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($path, $json) === false) {
        throw new RuntimeException('Failed to write backup file.');
    }

    $fileSize = filesize($path) ?: 0;
    $stmt = $conn->prepare(
        'INSERT INTO system_backups (backup_id, filename, file_size, backup_type, tables_count, record_count, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$backupId, $filename, $fileSize, $type, count($tables), $recordCount, $createdBy]);

    rdomms_enforce_backup_retention($conn);

    return rdomms_get_backup_record($conn, $backupId);
}

function rdomms_get_backup_record(PDO $conn, string $backupId): array
{
    $stmt = $conn->prepare('SELECT * FROM system_backups WHERE backup_id = ? LIMIT 1');
    $stmt->execute([$backupId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Backup not found.');
    }

    return rdomms_backup_row_for_client($row);
}

function rdomms_backup_row_for_client(array $row): array
{
    return [
        'id' => $row['backup_id'],
        'backup_id' => $row['backup_id'],
        'filename' => $row['filename'],
        'file_size' => (int) ($row['file_size'] ?? 0),
        'fileSize' => (int) ($row['file_size'] ?? 0),
        'backup_type' => $row['backup_type'] ?? 'manual',
        'backupType' => $row['backup_type'] ?? 'manual',
        'tables_count' => (int) ($row['tables_count'] ?? 0),
        'tablesCount' => (int) ($row['tables_count'] ?? 0),
        'record_count' => (int) ($row['record_count'] ?? 0),
        'recordCount' => (int) ($row['record_count'] ?? 0),
        'created_at' => $row['created_at'] ?? null,
        'createdAt' => $row['created_at'] ?? null,
    ];
}

function rdomms_list_backups(PDO $conn, int $limit = 50): array
{
    rdomms_ensure_backup_storage($conn);
    $limit = max(1, min(100, $limit));
    $stmt = $conn->prepare('SELECT * FROM system_backups ORDER BY created_at DESC LIMIT ' . $limit);
    $stmt->execute();
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = rdomms_backup_row_for_client($row);
    }
    return $rows;
}

function rdomms_get_backup_file_path(PDO $conn, string $backupId): string
{
    $stmt = $conn->prepare('SELECT filename FROM system_backups WHERE backup_id = ? LIMIT 1');
    $stmt->execute([$backupId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Backup not found.');
    }

    $path = rdomms_backup_storage_dir() . '/' . basename((string) $row['filename']);
    if (!is_readable($path)) {
        throw new RuntimeException('Backup file is missing on disk.');
    }

    return $path;
}

function rdomms_delete_backup(PDO $conn, string $backupId): void
{
    $path = rdomms_get_backup_file_path($conn, $backupId);
    @unlink($path);

    $stmt = $conn->prepare('DELETE FROM system_backups WHERE backup_id = ?');
    $stmt->execute([$backupId]);
}

function rdomms_enforce_backup_retention(PDO $conn): void
{
    $settings = rdomms_load_backup_settings($conn);
    $retention = max(1, (int) ($settings['retention_count'] ?? 10));

    $stmt = $conn->query('SELECT backup_id FROM system_backups ORDER BY created_at DESC');
    $rows = $stmt->fetchAll();
    if (count($rows) <= $retention) {
        return;
    }

    foreach (array_slice($rows, $retention) as $row) {
        try {
            rdomms_delete_backup($conn, (string) $row['backup_id']);
        } catch (Throwable $e) {
            error_log('Backup retention cleanup failed: ' . $e->getMessage());
        }
    }
}

function rdomms_restore_database_backup(PDO $conn, array $payload): array
{
    if (empty($payload['data']) || !is_array($payload['data'])) {
        throw new RuntimeException('Invalid backup payload.');
    }

    $preferred = rdomms_backup_restore_order();
    $tables = [];
    foreach ($preferred as $table) {
        if (isset($payload['data'][$table]) && rdomms_table_exists($conn, $table)) {
            $tables[] = $table;
        }
    }
    foreach (array_keys($payload['data']) as $table) {
        if (!in_array($table, $tables, true) && rdomms_table_exists($conn, $table)) {
            $tables[] = $table;
        }
    }

    $conn->exec('SET FOREIGN_KEY_CHECKS=0');
    $restoredTables = 0;
    $restoredRecords = 0;

    try {
        foreach ($tables as $table) {
            if (in_array($table, rdomms_backup_excluded_tables(), true)) {
                continue;
            }

            $rows = $payload['data'][$table] ?? [];
            if (!is_array($rows)) {
                continue;
            }

            $conn->exec('DELETE FROM `' . str_replace('`', '``', $table) . '`');

            if (!$rows) {
                $restoredTables++;
                continue;
            }

            $columns = array_keys($rows[0]);
            $columnSql = implode(', ', array_map(static fn ($col) => '`' . str_replace('`', '``', $col) . '`', $columns));
            $placeholderSql = implode(', ', array_fill(0, count($columns), '?'));
            $insertSql = 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . $columnSql . ') VALUES (' . $placeholderSql . ')';
            $insertStmt = $conn->prepare($insertSql);

            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $values[] = $row[$column] ?? null;
                }
                $insertStmt->execute($values);
                $restoredRecords++;
            }

            $restoredTables++;
        }
    } finally {
        $conn->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    require_once __DIR__ . '/departments.php';
    try {
        syncRdommsDepartmentsToDatabase($conn);
    } catch (Throwable $e) {
        error_log('Department resync after restore failed: ' . $e->getMessage());
    }

    return [
        'tables_restored' => $restoredTables,
        'records_restored' => $restoredRecords,
    ];
}

function rdomms_parse_backup_file(string $path): array
{
    if (!is_readable($path)) {
        throw new RuntimeException('Backup file is not readable.');
    }

    $raw = file_get_contents($path);
    $payload = json_decode((string) $raw, true);
    if (!is_array($payload) || empty($payload['data']) || !is_array($payload['data'])) {
        throw new RuntimeException('Invalid backup file format.');
    }

    return $payload;
}

function rdomms_backup_summary(PDO $conn): array
{
    rdomms_ensure_backup_storage($conn);
    $tables = rdomms_discover_backup_tables($conn);
    $totalRecords = 0;

    foreach ($tables as $table) {
        $stmt = $conn->query('SELECT COUNT(*) AS total FROM `' . str_replace('`', '``', $table) . '`');
        $totalRecords += (int) (($stmt->fetch()['total'] ?? 0));
    }

    $stmt = $conn->query('SELECT COUNT(*) AS total, COALESCE(SUM(file_size), 0) AS bytes FROM system_backups');
    $stats = $stmt->fetch() ?: [];

    return [
        'tables_available' => count($tables),
        'records_available' => $totalRecords,
        'stored_backups' => (int) ($stats['total'] ?? 0),
        'stored_bytes' => (int) ($stats['bytes'] ?? 0),
        'storage_path' => 'storage/backups',
    ];
}

function rdomms_auto_backup_is_due(array $settings): bool
{
    if (empty($settings['auto_enabled'])) {
        return false;
    }

    $last = $settings['last_auto_backup'] ?? null;
    if (!$last) {
        return true;
    }

    $lastTime = strtotime((string) $last);
    if (!$lastTime) {
        return true;
    }

    $frequency = $settings['auto_frequency'] ?? 'weekly';
    $intervals = [
        'daily' => 86400,
        'weekly' => 604800,
        'monthly' => 2592000,
    ];

    $seconds = $intervals[$frequency] ?? 604800;
    return (time() - $lastTime) >= $seconds;
}

function rdomms_mark_auto_backup_ran(PDO $conn): void
{
    $settings = rdomms_load_backup_settings($conn);
    $settings['last_auto_backup'] = gmdate('c');
    $stmt = $conn->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, setting_type)
         VALUES (?, ?, "json")
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute(['backup_settings', json_encode($settings)]);
}

function rdomms_maybe_run_scheduled_backup(PDO $conn, ?int $createdBy = null): ?array
{
    $settings = rdomms_load_backup_settings($conn);
    if (!rdomms_auto_backup_is_due($settings)) {
        return null;
    }

    $backup = rdomms_export_database_backup($conn, $createdBy, 'auto');
    rdomms_mark_auto_backup_ran($conn);
    return $backup;
}

function rdomms_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 2) . ' MB';
}
