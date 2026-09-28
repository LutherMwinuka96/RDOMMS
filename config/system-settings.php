<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function rdomms_general_settings_defaults(): array
{
    return [
        'app_name' => 'RDOMMS',
        'app_version' => '2.0.0',
        'default_language' => 'en',
        'date_format' => 'DD/MM/YYYY',
        'timezone' => 'EAT',
        'items_per_page' => '15',
        'theme' => 'light',
        'maintenance_mode' => '0',
        'allow_registration' => '1',
    ];
}

function rdomms_is_truthy($value): bool
{
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function rdomms_ensure_system_settings_table(PDO $conn): void
{
    $conn->exec(
        'CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            setting_type VARCHAR(50) DEFAULT "text",
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )'
    );
}

function rdomms_ensure_general_settings(PDO $conn): void
{
    rdomms_ensure_system_settings_table($conn);

    $stmt = $conn->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, setting_type)
         VALUES (?, ?, "text")
         ON DUPLICATE KEY UPDATE setting_key = setting_key'
    );

    foreach (rdomms_general_settings_defaults() as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

function rdomms_load_general_settings(?PDO $conn = null): array
{
    $defaults = rdomms_general_settings_defaults();

    if (!$conn) {
        $db = new Database();
        $conn = $db->getConnection();
    }

    if (!$conn) {
        return $defaults;
    }

    rdomms_ensure_general_settings($conn);

    $keys = array_keys($defaults);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($keys);

    $settings = $defaults;
    foreach ($stmt->fetchAll() as $row) {
        $key = (string) ($row['setting_key'] ?? '');
        if ($key !== '' && array_key_exists($key, $settings)) {
            $settings[$key] = (string) ($row['setting_value'] ?? $settings[$key]);
        }
    }

    return $settings;
}

function rdomms_save_general_settings(PDO $conn, array $input): array
{
    rdomms_ensure_general_settings($conn);

    $allowed = rdomms_general_settings_defaults();
    unset($allowed['app_version']);

    $map = [
        'language' => 'default_language',
        'default_language' => 'default_language',
        'dateFormat' => 'date_format',
        'date_format' => 'date_format',
        'timezone' => 'timezone',
        'itemsPerPage' => 'items_per_page',
        'items_per_page' => 'items_per_page',
        'theme' => 'theme',
        'app_name' => 'app_name',
        'maintenance_mode' => 'maintenance_mode',
        'allow_registration' => 'allow_registration',
        'maintenanceMode' => 'maintenance_mode',
        'allowRegistration' => 'allow_registration',
    ];

    $updates = [];
    foreach ($input as $rawKey => $rawValue) {
        $key = $map[$rawKey] ?? $rawKey;
        if (!array_key_exists($key, $allowed)) {
            continue;
        }

        if (in_array($key, ['maintenance_mode', 'allow_registration'], true)) {
            $updates[$key] = rdomms_is_truthy($rawValue) ? '1' : '0';
            continue;
        }

        if ($key === 'items_per_page') {
            $updates[$key] = (string) max(5, min(100, (int) $rawValue));
            continue;
        }

        if ($key === 'theme' && !in_array($rawValue, ['light', 'dark'], true)) {
            continue;
        }

        if ($key === 'default_language' && !in_array($rawValue, ['en', 'fr'], true)) {
            continue;
        }

        if ($key === 'date_format' && !in_array($rawValue, ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'], true)) {
            continue;
        }

        if ($key === 'timezone' && !in_array($rawValue, ['EAT', 'UTC', 'GMT'], true)) {
            continue;
        }

        $updates[$key] = trim((string) $rawValue);
    }

    if (!$updates) {
        return rdomms_load_general_settings($conn);
    }

    $stmt = $conn->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, setting_type)
         VALUES (?, ?, "text")
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
    );

    foreach ($updates as $key => $value) {
        $stmt->execute([$key, $value]);
    }

    return rdomms_load_general_settings($conn);
}

function rdomms_general_settings_for_client(array $settings): array
{
    return [
        'app_name' => $settings['app_name'] ?? 'RDOMMS',
        'app_version' => $settings['app_version'] ?? '2.0.0',
        'language' => $settings['default_language'] ?? 'en',
        'default_language' => $settings['default_language'] ?? 'en',
        'date_format' => $settings['date_format'] ?? 'DD/MM/YYYY',
        'dateFormat' => $settings['date_format'] ?? 'DD/MM/YYYY',
        'timezone' => $settings['timezone'] ?? 'EAT',
        'items_per_page' => (int) ($settings['items_per_page'] ?? 15),
        'itemsPerPage' => (int) ($settings['items_per_page'] ?? 15),
        'theme' => $settings['theme'] ?? 'light',
        'maintenance_mode' => rdomms_is_truthy($settings['maintenance_mode'] ?? '0'),
        'allow_registration' => rdomms_is_truthy($settings['allow_registration'] ?? '1'),
    ];
}

function rdomms_public_settings_for_client(array $settings): array
{
    return [
        'app_name' => $settings['app_name'] ?? 'RDOMMS',
        'maintenance_mode' => rdomms_is_truthy($settings['maintenance_mode'] ?? '0'),
        'allow_registration' => rdomms_is_truthy($settings['allow_registration'] ?? '1'),
    ];
}

function rdomms_can_bypass_maintenance(?array $user): bool
{
    if (!$user) {
        return false;
    }

    return normalizeRoleName($user['role'] ?? '') === 'admin';
}

function rdomms_enforce_maintenance_access(?array $user): void
{
    $db = new Database();
    $conn = $db->getConnection();
    if (!$conn) {
        return;
    }

    $settings = rdomms_load_general_settings($conn);
    if (!rdomms_is_truthy($settings['maintenance_mode'] ?? '0')) {
        return;
    }

    if (rdomms_can_bypass_maintenance($user)) {
        return;
    }

    sendJSONResponse([
        'status' => 'error',
        'message' => 'System is under maintenance. Only administrators can access the system right now.',
    ], 503);
}
