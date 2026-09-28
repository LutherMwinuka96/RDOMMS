<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

const RDOMMS_ENC_PREFIX = 'enc1:';

function rdomms_encryption_enabled(): bool
{
    $key = trim((string) rdomms_env('DB_ENCRYPTION_KEY', ''));
    return $key !== '';
}

function rdomms_encryption_key(): ?string
{
    $raw = trim((string) rdomms_env('DB_ENCRYPTION_KEY', ''));
    if ($raw === '') {
        return null;
    }

    return hash('sha256', $raw, true);
}

function rdomms_encrypt_sensitive(?string $value): ?string
{
    if ($value === null || $value === '') {
        return $value;
    }

    if (str_starts_with($value, RDOMMS_ENC_PREFIX)) {
        return $value;
    }

    $key = rdomms_encryption_key();
    if ($key === null) {
        return $value;
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        return $value;
    }

    return RDOMMS_ENC_PREFIX . base64_encode($iv . $tag . $cipher);
}

function rdomms_decrypt_sensitive(?string $value): ?string
{
    if ($value === null || $value === '') {
        return $value;
    }

    if (!str_starts_with($value, RDOMMS_ENC_PREFIX)) {
        return $value;
    }

    $key = rdomms_encryption_key();
    if ($key === null) {
        return $value;
    }

    $encoded = substr($value, strlen(RDOMMS_ENC_PREFIX));
    $binary = base64_decode($encoded, true);
    if ($binary === false || strlen($binary) < 29) {
        return $value;
    }

    $iv = substr($binary, 0, 12);
    $tag = substr($binary, 12, 16);
    $cipher = substr($binary, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

    return $plain === false ? $value : $plain;
}

function rdomms_hydrate_user_sensitive_fields(array $row): array
{
    if (array_key_exists('phone', $row)) {
        $row['phone'] = rdomms_decrypt_sensitive($row['phone'] !== null ? (string) $row['phone'] : null);
    }
    if (array_key_exists('employee_id', $row)) {
        $row['employee_id'] = rdomms_decrypt_sensitive($row['employee_id'] !== null ? (string) $row['employee_id'] : null);
    }

    return $row;
}

function rdomms_hydrate_beneficiary_sensitive_fields(array $row): array
{
    if (array_key_exists('phone', $row)) {
        $row['phone'] = rdomms_decrypt_sensitive($row['phone'] !== null ? (string) $row['phone'] : null);
    }
    if (array_key_exists('national_id', $row)) {
        $row['national_id'] = rdomms_decrypt_sensitive($row['national_id'] !== null ? (string) $row['national_id'] : null);
    }

    return $row;
}

function ensureSensitiveDataSchema(PDO $conn): void
{
    $userColumns = [
        'phone' => 'VARCHAR(512) NULL',
        'employee_id' => 'VARCHAR(512) NULL',
    ];

    foreach ($userColumns as $column => $definition) {
        try {
            $stmt = $conn->prepare('SHOW COLUMNS FROM users LIKE ?');
            $stmt->execute([$column]);
            $existing = $stmt->fetch();
            if (!$existing) {
                continue;
            }

            $type = strtolower((string) ($existing['Type'] ?? ''));
            if (preg_match('/varchar\((\d+)\)/', $type, $matches) && (int) $matches[1] < 255) {
                $conn->exec("ALTER TABLE users MODIFY {$column} {$definition}");
            }
        } catch (Throwable $e) {
            error_log('ensureSensitiveDataSchema users.' . $column . ': ' . $e->getMessage());
        }
    }

    $beneficiaryColumns = [
        'phone' => 'VARCHAR(512) NULL',
        'national_id' => 'VARCHAR(512) NULL',
    ];

    foreach ($beneficiaryColumns as $column => $definition) {
        try {
            $stmt = $conn->prepare('SHOW COLUMNS FROM beneficiaries LIKE ?');
            $stmt->execute([$column]);
            $existing = $stmt->fetch();
            if (!$existing) {
                continue;
            }

            $type = strtolower((string) ($existing['Type'] ?? ''));
            if (preg_match('/varchar\((\d+)\)/', $type, $matches) && (int) $matches[1] < 255) {
                $conn->exec("ALTER TABLE beneficiaries MODIFY {$column} {$definition}");
            }
        } catch (Throwable $e) {
            error_log('ensureSensitiveDataSchema beneficiaries.' . $column . ': ' . $e->getMessage());
        }
    }
}

function rdomms_maybe_run_encryption_migration(PDO $conn): void
{
    static $attempted = false;
    if ($attempted || !rdomms_encryption_enabled()) {
        return;
    }

    $attempted = true;
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'security';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $flag = $dir . DIRECTORY_SEPARATOR . '.encryption-migrated';
    if (is_file($flag)) {
        return;
    }

    rdomms_migrate_plaintext_sensitive_fields($conn);
    file_put_contents($flag, date('c'));
}

function rdomms_migrate_plaintext_sensitive_fields(PDO $conn): void
{
    if (!rdomms_encryption_enabled()) {
        return;
    }

    ensureSensitiveDataSchema($conn);

    try {
        $stmt = $conn->query('SELECT id, phone, employee_id FROM users');
        $rows = $stmt ? $stmt->fetchAll() : [];
        foreach ($rows as $row) {
            $phone = $row['phone'] ?? null;
            $employeeId = $row['employee_id'] ?? null;
            $encryptedPhone = $phone !== null && $phone !== '' ? rdomms_encrypt_sensitive((string) $phone) : $phone;
            $encryptedEmployee = $employeeId !== null && $employeeId !== '' ? rdomms_encrypt_sensitive((string) $employeeId) : $employeeId;

            if ($encryptedPhone === $phone && $encryptedEmployee === $employeeId) {
                continue;
            }

            $update = $conn->prepare('UPDATE users SET phone = ?, employee_id = ? WHERE id = ?');
            $update->execute([$encryptedPhone, $encryptedEmployee, $row['id']]);
        }
    } catch (Throwable $e) {
        error_log('rdomms_migrate_plaintext_sensitive_fields users: ' . $e->getMessage());
    }

    try {
        $stmt = $conn->query('SELECT id, phone, national_id FROM beneficiaries');
        $rows = $stmt ? $stmt->fetchAll() : [];
        foreach ($rows as $row) {
            $phone = $row['phone'] ?? null;
            $nationalId = $row['national_id'] ?? null;
            $encryptedPhone = $phone !== null && $phone !== '' ? rdomms_encrypt_sensitive((string) $phone) : $phone;
            $encryptedNational = $nationalId !== null && $nationalId !== '' ? rdomms_encrypt_sensitive((string) $nationalId) : $nationalId;

            if ($encryptedPhone === $phone && $encryptedNational === $nationalId) {
                continue;
            }

            $update = $conn->prepare('UPDATE beneficiaries SET phone = ?, national_id = ? WHERE id = ?');
            $update->execute([$encryptedPhone, $encryptedNational, $row['id']]);
        }
    } catch (Throwable $e) {
        error_log('rdomms_migrate_plaintext_sensitive_fields beneficiaries: ' . $e->getMessage());
    }
}
