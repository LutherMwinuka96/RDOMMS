<?php
declare(strict_types=1);

if (!function_exists('rdomms_load_env')) {
    function rdomms_load_env(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }

        $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
        if (!is_readable($envPath)) {
            $loaded = true;
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            $loaded = true;
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key === '') {
                continue;
            }

            $value = rdomms_env_unquote($value);
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }

        $loaded = true;

        $timezone = trim((string) ($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'Africa/Dar_es_Salaam'));
        if ($timezone !== '') {
            @date_default_timezone_set($timezone);
        }
    }

    function rdomms_env_unquote(string $value): string
    {
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    function rdomms_env(string $key, ?string $default = null): ?string
    {
        rdomms_load_env();

        if (array_key_exists($key, $_ENV)) {
            return (string) $_ENV[$key];
        }

        $value = getenv($key);
        if ($value !== false) {
            return (string) $value;
        }

        return $default;
    }

    function rdomms_env_bool(string $key, bool $default = false): bool
    {
        $value = strtolower(trim((string) rdomms_env($key, $default ? '1' : '0')));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    function rdomms_env_int(string $key, int $default = 0): int
    {
        $value = rdomms_env($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }
}
