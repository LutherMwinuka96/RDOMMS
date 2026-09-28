<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/system-settings.php';
require_once __DIR__ . '/notification-tables.php';

function rdomms_notification_preferences_defaults(): array
{
    return [
        'channels' => [
            'email' => true,
            'in_app' => true,
            'sms' => false,
        ],
        'alerts' => [
            'project_status' => true,
            'project_budget_request' => true,
            'budget_threshold' => true,
            'user_role' => true,
            'system_maintenance' => true,
            'approval_requests' => true,
            'system_alerts' => true,
        ],
        'digest' => [
            'enabled' => false,
            'frequency' => 'daily',
        ],
    ];
}

function rdomms_ensure_notification_preferences_storage(PDO $conn): void
{
    rdomms_ensure_system_settings_table($conn);

    $defaults = json_encode(rdomms_notification_preferences_defaults());
    $stmt = $conn->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, setting_type)
         VALUES (?, ?, "json")
         ON DUPLICATE KEY UPDATE setting_key = setting_key'
    );
    $stmt->execute(['notification_preferences', $defaults]);

    try {
        $conn->exec(
            'CREATE TABLE IF NOT EXISTS user_notification_preferences (
                user_id INT PRIMARY KEY,
                preferences JSON NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_notification_preferences_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (PDOException $e) {
        error_log('user_notification_preferences table ensure skipped: ' . $e->getMessage());
    }
}

function rdomms_merge_notification_preferences(array $base, array $overrides): array
{
    $defaults = rdomms_notification_preferences_defaults();
    $merged = array_replace_recursive($defaults, $base);

    if ($overrides) {
        $merged = array_replace_recursive($merged, $overrides);
    }

    return $merged;
}

function rdomms_normalize_notification_preferences_input(array $input): array
{
    $defaults = rdomms_notification_preferences_defaults();
    $normalized = rdomms_notification_preferences_defaults();

    $channelMap = [
        'email' => 'email',
        'push' => 'in_app',
        'in_app' => 'in_app',
        'sms' => 'sms',
    ];

    foreach ($channelMap as $raw => $key) {
        if (array_key_exists($raw, $input)) {
            $normalized['channels'][$key] = rdomms_is_truthy($input[$raw]);
        }
    }

    if (isset($input['channels']) && is_array($input['channels'])) {
        foreach ($input['channels'] as $key => $value) {
            if (array_key_exists($key, $normalized['channels'])) {
                $normalized['channels'][$key] = rdomms_is_truthy($value);
            }
        }
    }

    $alertMap = [
        'projectStatus' => 'project_status',
        'project_status' => 'project_status',
        'projectBudgetRequest' => 'project_budget_request',
        'project_budget_request' => 'project_budget_request',
        'budgetAlert' => 'budget_threshold',
        'budget_threshold' => 'budget_threshold',
        'userRole' => 'user_role',
        'user_role' => 'user_role',
        'systemMaintenance' => 'system_maintenance',
        'system_maintenance' => 'system_maintenance',
        'approvalRequests' => 'approval_requests',
        'approval_requests' => 'approval_requests',
        'systemAlerts' => 'system_alerts',
        'system_alerts' => 'system_alerts',
    ];

    foreach ($alertMap as $raw => $key) {
        if (array_key_exists($raw, $input)) {
            $normalized['alerts'][$key] = rdomms_is_truthy($input[$raw]);
        }
    }

    if (isset($input['alerts']) && is_array($input['alerts'])) {
        foreach ($input['alerts'] as $key => $value) {
            if (array_key_exists($key, $normalized['alerts'])) {
                $normalized['alerts'][$key] = rdomms_is_truthy($value);
            }
        }
    }

    if (isset($input['digest']) && is_array($input['digest'])) {
        if (array_key_exists('enabled', $input['digest'])) {
            $normalized['digest']['enabled'] = rdomms_is_truthy($input['digest']['enabled']);
        }
        if (!empty($input['digest']['frequency']) && in_array($input['digest']['frequency'], ['daily', 'weekly'], true)) {
            $normalized['digest']['frequency'] = $input['digest']['frequency'];
        }
    }

    if (array_key_exists('digestEnabled', $input)) {
        $normalized['digest']['enabled'] = rdomms_is_truthy($input['digestEnabled']);
    }
    if (!empty($input['digestFrequency']) && in_array($input['digestFrequency'], ['daily', 'weekly'], true)) {
        $normalized['digest']['frequency'] = $input['digestFrequency'];
    }

    return rdomms_merge_notification_preferences($defaults, $normalized);
}

function rdomms_load_system_notification_preferences(PDO $conn): array
{
    rdomms_ensure_notification_preferences_storage($conn);

    $stmt = $conn->prepare(
        'SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1'
    );
    $stmt->execute(['notification_preferences']);
    $row = $stmt->fetch();
    $decoded = [];

    if ($row && !empty($row['setting_value'])) {
        $parsed = json_decode((string) $row['setting_value'], true);
        if (is_array($parsed)) {
            $decoded = $parsed;
        }
    }

    return rdomms_merge_notification_preferences([], $decoded);
}

function rdomms_save_system_notification_preferences(PDO $conn, array $input): array
{
    rdomms_ensure_notification_preferences_storage($conn);
    $preferences = rdomms_normalize_notification_preferences_input($input);

    $stmt = $conn->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, setting_type)
         VALUES (?, ?, "json")
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute(['notification_preferences', json_encode($preferences)]);

    return $preferences;
}

function rdomms_notification_preferences_for_client(array $preferences): array
{
    return [
        'email' => !empty($preferences['channels']['email']),
        'push' => !empty($preferences['channels']['in_app']),
        'in_app' => !empty($preferences['channels']['in_app']),
        'sms' => !empty($preferences['channels']['sms']),
        'channels' => $preferences['channels'] ?? [],
        'projectStatus' => !empty($preferences['alerts']['project_status']),
        'project_status' => !empty($preferences['alerts']['project_status']),
        'budgetAlert' => !empty($preferences['alerts']['budget_threshold']),
        'budget_threshold' => !empty($preferences['alerts']['budget_threshold']),
        'userRole' => !empty($preferences['alerts']['user_role']),
        'user_role' => !empty($preferences['alerts']['user_role']),
        'systemMaintenance' => !empty($preferences['alerts']['system_maintenance']),
        'system_maintenance' => !empty($preferences['alerts']['system_maintenance']),
        'approvalRequests' => !empty($preferences['alerts']['approval_requests']),
        'approval_requests' => !empty($preferences['alerts']['approval_requests']),
        'systemAlerts' => !empty($preferences['alerts']['system_alerts']),
        'system_alerts' => !empty($preferences['alerts']['system_alerts']),
        'alerts' => $preferences['alerts'] ?? [],
        'digestEnabled' => !empty($preferences['digest']['enabled']),
        'digestFrequency' => $preferences['digest']['frequency'] ?? 'daily',
        'digest' => $preferences['digest'] ?? [],
    ];
}

function rdomms_is_notification_channel_enabled(PDO $conn, string $channel): bool
{
    $preferences = rdomms_load_system_notification_preferences($conn);
    return !empty($preferences['channels'][$channel]);
}

function rdomms_is_notification_alert_enabled(PDO $conn, string $alertType): bool
{
    $preferences = rdomms_load_system_notification_preferences($conn);
    return !empty($preferences['alerts'][$alertType]);
}

function rdomms_create_system_notification_if_enabled(
    PDO $conn,
    string $alertType,
    string $title,
    string $message = '',
    string $module = 'System',
    string $severity = 'info',
    ?int $targetUserId = null
): ?string {
    if (!rdomms_is_notification_alert_enabled($conn, $alertType)) {
        return null;
    }

    if (!rdomms_is_notification_channel_enabled($conn, 'in_app')) {
        return null;
    }

    rdomms_ensure_notification_tables($conn);

    $notificationId = 'ALR' . time() . rand(100, 999);
    $stmt = $conn->prepare(
        'INSERT INTO notifications (notification_id, title, message, severity, module, category, user_id, is_read)
         VALUES (?, ?, ?, ?, ?, "system_alert", ?, 0)'
    );
    $stmt->execute([
        $notificationId,
        $title,
        $message,
        rdomms_map_alert_severity_to_db($severity),
        $module,
        $targetUserId,
    ]);

    return $notificationId;
}

/** Notify every active administrator in-app (and email when enabled). */
function rdomms_notify_admin_users(
    PDO $conn,
    string $alertType,
    string $title,
    string $message,
    string $module = 'Users',
    string $severity = 'warning'
): void {
    $stmt = $conn->query(
        "SELECT id FROM users WHERE LOWER(role) = 'admin' AND LOWER(COALESCE(status, 'active')) = 'active'"
    );
    if (!$stmt) {
        return;
    }

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adminId = (int) ($row['id'] ?? 0);
        if ($adminId > 0) {
            rdomms_notify_user_account($conn, $alertType, $title, $message, $module, $severity, $adminId);
        }
    }
}

function rdomms_notify_user_account(
    PDO $conn,
    string $alertType,
    string $title,
    string $message,
    string $module = 'System',
    string $severity = 'info',
    ?int $targetUserId = null
): ?string {
    if (!$targetUserId) {
        return null;
    }

    return rdomms_create_system_notification_if_enabled(
        $conn,
        $alertType,
        $title,
        $message,
        $module,
        $severity,
        $targetUserId
    );
}

function rdomms_notification_recently_sent(
    PDO $conn,
    string $title,
    string $module,
    int $hours = 24
): bool {
    rdomms_ensure_notification_tables($conn);
    $stmt = $conn->prepare(
        "SELECT id FROM notifications
         WHERE title = ? AND module = ? AND category = 'system_alert'
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
         LIMIT 1"
    );
    $stmt->execute([$title, $module, $hours]);
    return (bool) $stmt->fetch();
}

function rdomms_get_admin_notification_recipients(PDO $conn): array
{
    $stmt = $conn->query(
        "SELECT email, fullname FROM users
         WHERE status = 'active'
           AND email IS NOT NULL
           AND email != ''
           AND LOWER(role) IN ('admin', 'head_of_organization_center')"
    );

    $rows = $stmt ? $stmt->fetchAll() : [];
    $recipients = [];

    foreach ($rows as $row) {
        $email = trim((string) ($row['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $recipients[$email] = trim((string) ($row['fullname'] ?? 'Administrator'));
        }
    }

    return $recipients;
}

function rdomms_send_notification_email(PDO $conn, string $subject, string $message): void
{
    if (!rdomms_is_notification_channel_enabled($conn, 'email')) {
        return;
    }

    require_once __DIR__ . '/mailer.php';
    $config = getMailConfig();
    $recipients = rdomms_get_admin_notification_recipients($conn);

    if (!$recipients) {
        return;
    }

    $htmlBody = '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#1a2e23;">'
        . '<h2 style="color:#2d5016;">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>'
        . '<p style="color:#6b8c7a;font-size:12px;">RDOMMS system notification</p>'
        . '</div>';
    $textBody = $subject . "\n\n" . $message . "\n\nRDOMMS system notification";

    foreach ($recipients as $email => $name) {
        try {
            if (!empty($config['smtp']['enabled'])) {
                sendSmtpMail($config, $email, $subject, $htmlBody, $textBody);
            } else {
                saveEmailToOutbox($email, $subject, $htmlBody, $textBody);
            }
        } catch (Throwable $e) {
            error_log('Notification email failed for ' . $email . ': ' . $e->getMessage());
        }
    }
}

function rdomms_dispatch_notification(
    PDO $conn,
    string $alertType,
    string $title,
    string $message = '',
    string $module = 'System',
    string $severity = 'info',
    ?int $targetUserId = null
): ?string {
    $notificationId = rdomms_create_system_notification_if_enabled(
        $conn,
        $alertType,
        $title,
        $message,
        $module,
        $severity,
        $targetUserId
    );

    if (rdomms_is_notification_alert_enabled($conn, $alertType)) {
        rdomms_send_notification_email($conn, $title, $message);
    }

    return $notificationId;
}

function rdomms_check_budget_threshold_and_notify(
    PDO $conn,
    string $department,
    string $quarter,
    string $fiscalYear
): void {
    if (!rdomms_is_notification_alert_enabled($conn, 'budget_threshold')) {
        return;
    }

    $allocationStmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS allocated
         FROM budget_allocations
         WHERE department = ?
           AND quarter = ?
           AND fiscal_year = ?
           AND status = 'approved'"
    );
    $allocationStmt->execute([$department, $quarter, $fiscalYear]);
    $allocated = (float) (($allocationStmt->fetch()['allocated'] ?? 0));

    if ($allocated <= 0) {
        return;
    }

    $expenseStmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS spent
         FROM expenses
         WHERE department = ?
           AND quarter = ?
           AND fiscal_year = ?
           AND status = 'approved'"
    );
    $expenseStmt->execute([$department, $quarter, $fiscalYear]);
    $spent = (float) (($expenseStmt->fetch()['spent'] ?? 0));
    $ratio = $spent / $allocated;

    if ($ratio >= 1) {
        $title = 'Budget limit exceeded';
        if (!rdomms_notification_recently_sent($conn, $title, 'Finance')) {
            rdomms_dispatch_notification(
                $conn,
                'budget_threshold',
                $title,
                sprintf(
                    '%s (%s %s) has spent %s against an approved budget of %s (%.0f%%).',
                    $department,
                    $quarter,
                    $fiscalYear,
                    number_format($spent, 2),
                    number_format($allocated, 2),
                    $ratio * 100
                ),
                'Finance',
                'danger'
            );
        }
        return;
    }

    if ($ratio >= 0.8) {
        $title = 'Budget threshold warning';
        if (!rdomms_notification_recently_sent($conn, $title, 'Finance')) {
            rdomms_dispatch_notification(
                $conn,
                'budget_threshold',
                $title,
                sprintf(
                    '%s (%s %s) has used %.0f%% of its approved budget (%s of %s).',
                    $department,
                    $quarter,
                    $fiscalYear,
                    $ratio * 100,
                    number_format($spent, 2),
                    number_format($allocated, 2)
                ),
                'Finance',
                'warning'
            );
        }
    }
}
