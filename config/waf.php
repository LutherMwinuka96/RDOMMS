<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/security.php';

function rdomms_waf_enabled(): bool
{
    return rdomms_env_bool('WAF_ENABLED', true);
}

function rdomms_waf_storage_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'security';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function rdomms_waf_blocked_ips_path(): string
{
    return rdomms_waf_storage_dir() . DIRECTORY_SEPARATOR . 'blocked-ips.json';
}

function rdomms_waf_load_blocked_ips(): array
{
    $path = rdomms_waf_blocked_ips_path();
    if (!is_readable($path)) {
        return [];
    }

    $payload = json_decode((string) file_get_contents($path), true);
    return is_array($payload) ? $payload : [];
}

function rdomms_waf_save_blocked_ips(array $ips): void
{
    file_put_contents(
        rdomms_waf_blocked_ips_path(),
        json_encode($ips, JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function rdomms_waf_is_ip_blocked(string $ip): bool
{
    $blocked = rdomms_waf_load_blocked_ips();
    if (!isset($blocked[$ip])) {
        return false;
    }

    $entry = $blocked[$ip];
    $until = (int) ($entry['blocked_until'] ?? 0);
    if ($until > time()) {
        return true;
    }

    unset($blocked[$ip]);
    rdomms_waf_save_blocked_ips($blocked);
    return false;
}

function rdomms_waf_block_ip(string $ip, int $minutes = 60): void
{
    $blocked = rdomms_waf_load_blocked_ips();
    $blocked[$ip] = [
        'blocked_until' => time() + ($minutes * 60),
        'blocked_at' => date('c'),
        'reason' => 'IDS threshold exceeded',
    ];
    rdomms_waf_save_blocked_ips($blocked);
}

function rdomms_waf_log_event(string $ip, array $threats, int $score): void
{
    $dir = rdomms_waf_storage_dir() . DIRECTORY_SEPARATOR . 'ids';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $entry = [
        'time' => date('c'),
        'ip' => $ip,
        'score' => $score,
        'threats' => $threats,
        'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
    ];

    $file = $dir . DIRECTORY_SEPARATOR . 'events-' . date('Y-m-d') . '.log';
    file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function rdomms_waf_record_violation(string $ip, array $threats, int $score): void
{
    rdomms_waf_log_event($ip, $threats, $score);

    $threshold = rdomms_env_int('WAF_IDS_BLOCK_SCORE', 120);
    $windowMinutes = rdomms_env_int('WAF_IDS_WINDOW_MINUTES', 15);
    $maxViolations = rdomms_env_int('WAF_IDS_MAX_VIOLATIONS', 5);

    $key = hash('sha256', $ip);
    $file = rdomms_waf_storage_dir() . DIRECTORY_SEPARATOR . 'ids' . DIRECTORY_SEPARATOR . 'violations-' . $key . '.json';
    $state = ['count' => 0, 'first_seen' => time(), 'last_seen' => time()];

    if (is_readable($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }

    if (($state['first_seen'] ?? time()) + ($windowMinutes * 60) < time()) {
        $state = ['count' => 0, 'first_seen' => time(), 'last_seen' => time()];
    }

    $state['count'] = (int) ($state['count'] ?? 0) + 1;
    $state['last_seen'] = time();
    $state['last_score'] = $score;
    $state['last_threats'] = $threats;

    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0775, true);
    }
    file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);

    if ($score >= $threshold || (int) $state['count'] >= $maxViolations) {
        rdomms_waf_block_ip($ip, rdomms_env_int('WAF_IDS_BLOCK_MINUTES', 60));
    }
}

function rdomms_waf_scan_string(string $payload): array
{
    $threats = [];
    $score = 0;

    if ($payload === '') {
        return ['score' => 0, 'threats' => []];
    }

    $rules = [
        ['id' => 'path_traversal', 'pattern' => '/(\.\.\/|\.\.\\\\|%2e%2e%2f|%2e%2e%5c)/i', 'score' => 90],
        ['id' => 'sql_union', 'pattern' => '/\bunion\s+(all\s+)?select\b/i', 'score' => 85],
        ['id' => 'sql_comment', 'pattern' => '/(--|#|\/\*)\s*$/m', 'score' => 40],
        ['id' => 'sql_sleep', 'pattern' => '/\b(sleep|benchmark|waitfor)\s*\(/i', 'score' => 90],
        ['id' => 'sql_or_auth', 'pattern' => '/(\'\s*or\s*\'1\'\s*=\s*\'1|"\s*or\s*"1"\s*=\s*"1)/i', 'score' => 85],
        ['id' => 'xss_script', 'pattern' => '/<\s*script\b/i', 'score' => 80],
        ['id' => 'xss_javascript', 'pattern' => '/javascript\s*:/i', 'score' => 70],
        ['id' => 'cmd_injection', 'pattern' => '/\b(cat\s+\/etc\/passwd|cmd\.exe|powershell\s+-)/i', 'score' => 90],
        ['id' => 'php_eval', 'pattern' => '/\b(eval|assert|shell_exec|passthru|proc_open)\s*\(/i', 'score' => 85],
        ['id' => 'file_include', 'pattern' => '/\b(include|require)(_once)?\s*\(/i', 'score' => 60],
        ['id' => 'scanner_probe', 'pattern' => '/(wp-admin|phpmyadmin|\.env|\.git\/|\/etc\/passwd)/i', 'score' => 75],
    ];

    foreach ($rules as $rule) {
        if (preg_match($rule['pattern'], $payload)) {
            $threats[] = $rule['id'];
            $score += (int) $rule['score'];
        }
    }

    return ['score' => $score, 'threats' => $threats];
}

function rdomms_waf_collect_request_payload(): string
{
    $chunks = [
        (string) ($_SERVER['REQUEST_URI'] ?? ''),
        (string) ($_SERVER['QUERY_STRING'] ?? ''),
    ];

    foreach ($_GET as $key => $value) {
        $chunks[] = (string) $key;
        if (is_scalar($value)) {
            $chunks[] = (string) $value;
        }
    }

    if (in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $raw = (string) file_get_contents('php://input');
        if ($raw !== '') {
            $chunks[] = $raw;
        }
        foreach ($_POST as $key => $value) {
            $chunks[] = (string) $key;
            if (is_scalar($value)) {
                $chunks[] = (string) $value;
            }
        }
    }

    return implode("\n", $chunks);
}

function rdomms_waf_protect(): void
{
    if (!rdomms_waf_enabled() || PHP_SAPI === 'cli') {
        return;
    }

    $ip = rdomms_client_ip();
    if (rdomms_waf_is_ip_blocked($ip)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'Access denied by security policy.',
        ]);
        exit;
    }

    $payload = rdomms_waf_collect_request_payload();
    $scan = rdomms_waf_scan_string($payload);
    $blockScore = rdomms_env_int('WAF_BLOCK_SCORE', 85);

    if ($scan['score'] >= $blockScore) {
        rdomms_waf_record_violation($ip, $scan['threats'], $scan['score']);
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'Request blocked by Web Application Firewall.',
        ]);
        exit;
    }

    if ($scan['score'] > 0) {
        rdomms_waf_record_violation($ip, $scan['threats'], $scan['score']);
    }
}
