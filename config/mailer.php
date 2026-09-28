<?php

function getMailConfig() {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = require __DIR__ . '/mail.php';
    $localFile = __DIR__ . '/mail.local.php';
    if (is_file($localFile)) {
        $local = require $localFile;
        if (is_array($local)) {
            $config = array_replace_recursive($config, $local);
        }
    }

    try {
        $db = new Database();
        $conn = $db->getConnection();
        if ($conn) {
            ensureMailSettingsTable($conn);
            $dbSettings = loadMailSettingsFromDatabase($conn);
            if ($dbSettings) {
                $config = mergeMailSettings($config, $dbSettings);
            }
        }
    } catch (Throwable $e) {
        error_log('Mail DB config load failed: ' . $e->getMessage());
    }

    if (!empty($config['smtp']['username']) && !empty($config['smtp']['password'])) {
        $config['smtp']['enabled'] = true;
    }

    return finalizeMailConfig($config);
}

function normalizeSmtpPassword($password) {
    return preg_replace('/\s+/', '', trim((string) $password));
}

function finalizeMailConfig(array $config) {
    if (!empty($config['smtp']['username'])) {
        $config['smtp']['username'] = strtolower(trim((string) $config['smtp']['username']));
    }
    if (!empty($config['smtp']['password'])) {
        $config['smtp']['password'] = normalizeSmtpPassword($config['smtp']['password']);
    }

    $username = (string) ($config['smtp']['username'] ?? '');
    $fromEmail = strtolower(trim((string) ($config['from_email'] ?? '')));
    $genericFroms = ['noreply@rdomms.org', 'support@rdomms.org', ''];

    if ($username !== '' && ($fromEmail === '' || in_array($fromEmail, $genericFroms, true))) {
        $config['from_email'] = $username;
    }

    if ($username !== '' && preg_match('/@gmail\.com$/i', $username)) {
        $config['smtp']['host'] = $config['smtp']['host'] ?: 'smtp.gmail.com';
        $config['smtp']['port'] = (int) ($config['smtp']['port'] ?: 587);
        $config['smtp']['encryption'] = $config['smtp']['encryption'] ?: 'tls';
        $config['from_email'] = $username;
    }

    if ($username !== '' && preg_match('/@yahoo\.(com|co\.\w+)$/i', $username)) {
        $config['smtp']['host'] = $config['smtp']['host'] ?: 'smtp.mail.yahoo.com';
        $config['smtp']['port'] = (int) ($config['smtp']['port'] ?: 587);
        $config['smtp']['encryption'] = $config['smtp']['encryption'] ?: 'tls';
        $config['from_email'] = $username;
    }

    return $config;
}

function formatSmtpErrorMessage($rawMessage) {
    $message = preg_replace('/^SMTP error:\s*/i', '', trim((string) $rawMessage));

    if (stripos($message, '535') !== false || stripos($message, 'BadCredentials') !== false) {
        return 'Gmail/Yahoo rejected the login (535). Do NOT use your normal email password. '
            . 'Create an App Password: Google Account → Security → 2-Step Verification ON → App Passwords. '
            . 'Paste the 16-character app password (no spaces). Set From Email to the same address as SMTP Username, then Save and test again.';
    }

    return $message;
}

function mergeMailSettings(array $config, array $dbSettings) {
    if (isset($dbSettings['smtp_enabled'])) {
        $config['smtp']['enabled'] = $dbSettings['smtp_enabled'] === '1';
    }
    if (!empty($dbSettings['smtp_host'])) {
        $config['smtp']['host'] = $dbSettings['smtp_host'];
    }
    if (!empty($dbSettings['smtp_port'])) {
        $config['smtp']['port'] = (int) $dbSettings['smtp_port'];
    }
    if (!empty($dbSettings['smtp_encryption'])) {
        $config['smtp']['encryption'] = $dbSettings['smtp_encryption'];
    }
    if (!empty($dbSettings['smtp_username'])) {
        $config['smtp']['username'] = $dbSettings['smtp_username'];
    }
    if (!empty($dbSettings['smtp_password'])) {
        $config['smtp']['password'] = $dbSettings['smtp_password'];
    }
    if (!empty($dbSettings['from_email'])) {
        $config['from_email'] = $dbSettings['from_email'];
    }
    if (!empty($dbSettings['from_name'])) {
        $config['from_name'] = $dbSettings['from_name'];
    }
    if (!empty($dbSettings['app_base_url'])) {
        $config['app_base_url'] = rtrim($dbSettings['app_base_url'], '/');
    }
    return $config;
}

function ensureMailSettingsTable(PDO $conn) {
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

function loadMailSettingsFromDatabase(PDO $conn) {
    $keys = [
        'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption',
        'smtp_username', 'smtp_password', 'from_email', 'from_name', 'app_base_url',
    ];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($keys);
    $rows = $stmt->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function saveMailSettingsToDatabase(PDO $conn, array $settings) {
    $stmt = $conn->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, setting_type)
         VALUES (?, ?, "text")
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
    );
    foreach ($settings as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

function detectAppBaseUrlFromRequest(): ?string {
    if (PHP_SAPI === 'cli') {
        return null;
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return null;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $appRoot = '';

    if (preg_match('#^(.*?)/api/#i', $scriptName, $matches)) {
        $appRoot = rtrim($matches[1], '/');
    } elseif (preg_match('#^(.*?)/auth/#i', $scriptName, $matches)) {
        $appRoot = rtrim($matches[1], '/');
    } elseif (preg_match('#^(.*?)/(?:settings|finance|projects|reports|users)/#i', $scriptName, $matches)) {
        $appRoot = rtrim($matches[1], '/');
    } else {
        $appRoot = rtrim(dirname($scriptName), '/');
        if ($appRoot === '.' || $appRoot === '/') {
            $appRoot = '';
        }
    }

    return $scheme . '://' . $host . $appRoot;
}

function normalizeAppBaseUrl(?string $url): string {
    $value = rtrim(trim((string) $url), '/');
    if ($value === '') {
        return 'http://localhost/RDOMMS';
    }

    if (!preg_match('#^https?://#i', $value)) {
        $value = 'http://' . ltrim($value, '/');
    }

    $parts = parse_url($value);
    if ($parts === false || empty($parts['host'])) {
        return 'http://localhost/RDOMMS';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    if (!in_array($scheme, ['http', 'https'], true)) {
        $scheme = 'http';
    }

    $host = (string) $parts['host'];
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = (string) ($parts['path'] ?? '');

    if ($path === '' || $path === '/') {
        $path = '/RDOMMS';
    }

    // Normalize common WAMP folder casing on Windows deployments.
    $path = preg_replace('#/rdomms(?=/|$)#i', '/RDOMMS', $path) ?? $path;

    return $scheme . '://' . $host . $port . rtrim($path, '/');
}

function resolveAppBaseUrl(?string $override = null): string {
    $override = trim((string) $override);
    if ($override !== '') {
        $normalizedOverride = normalizeAppBaseUrl($override);
        if (filter_var($normalizedOverride, FILTER_VALIDATE_URL)) {
            return $normalizedOverride;
        }
    }

    $config = getMailConfig();
    $configured = rtrim(trim((string) ($config['app_base_url'] ?? '')), '/');
    if ($configured !== '') {
        return normalizeAppBaseUrl($configured);
    }

    $detected = detectAppBaseUrlFromRequest();
    if ($detected !== null && $detected !== '') {
        return normalizeAppBaseUrl($detected);
    }

    return normalizeAppBaseUrl('http://localhost/RDOMMS');
}

function getAppBaseUrl(): string {
    return resolveAppBaseUrl();
}

function buildPasswordResetUrl(string $token, ?string $baseUrlOverride = null): string {
    $base = resolveAppBaseUrl($baseUrlOverride);
    return $base . '/auth/reset_password.php?token=' . rawurlencode($token);
}

function buildPasswordResetEmailHtml($fullname, $resetLink, $expiresMinutes = 60) {
    $safeName = htmlspecialchars($fullname ?: 'User', ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f0fdf4;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;">
    <tr>
      <td align="center">
        <table width="100%" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #d1fae5;">
          <tr>
            <td style="background:linear-gradient(135deg,#062f17,#064e3b);padding:28px 32px;color:#ffffff;">
              <h1 style="margin:0;font-size:22px;">RDO|MMS</h1>
              <p style="margin:8px 0 0;opacity:0.85;font-size:14px;">Password reset request</p>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;color:#0f172a;">
              <p style="margin:0 0 16px;font-size:16px;">Hello <strong>{$safeName}</strong>,</p>
              <p style="margin:0 0 20px;line-height:1.6;color:#475569;">
                We received a request to reset your password. Click the button below to choose a new password.
                This link expires in {$expiresMinutes} minutes.
              </p>
              <p style="margin:0 0 24px;text-align:center;">
                <a href="{$safeLink}" style="display:inline-block;background:#16a34a;color:#ffffff;text-decoration:none;padding:14px 28px;border-radius:10px;font-weight:700;">
                  Reset Password
                </a>
              </p>
              <p style="margin:0 0 12px;font-size:13px;color:#64748b;line-height:1.5;">
                If the button does not work, copy and paste this link into your browser:
              </p>
              <p style="margin:0;font-size:12px;word-break:break-all;color:#16a34a;">{$safeLink}</p>
              <p style="margin:24px 0 0;font-size:13px;color:#94a3b8;">
                If you did not request this, you can safely ignore this email.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

class RdommsSmtpClient {
    private $socket;
    private $host;
    private $port;
    private $encryption;
    private $username;
    private $password;
    private $timeout = 30;

    public function __construct(array $smtp) {
        $this->host = (string) ($smtp['host'] ?? 'smtp.gmail.com');
        $this->port = (int) ($smtp['port'] ?? 587);
        $this->encryption = strtolower((string) ($smtp['encryption'] ?? 'tls'));
        $this->username = strtolower(trim((string) ($smtp['username'] ?? '')));
        $this->password = normalizeSmtpPassword($smtp['password'] ?? '');
    }

    public function send($fromEmail, $fromName, $to, $subject, $htmlBody, $textBody, $replyTo = null) {
        $this->connect();
        $this->expect(220);
        $this->command('EHLO rdomms.local', 250);

        if ($this->encryption === 'tls') {
            $this->command('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP TLS handshake failed');
            }
            $this->command('EHLO rdomms.local', 250);
        }

        $this->authenticate();

        $this->command('MAIL FROM:<' . $fromEmail . '>', 250);
        $this->command('RCPT TO:<' . $to . '>', [250, 251]);
        $this->command('DATA', 354);

        $message = $this->buildMessage($fromEmail, $fromName, $to, $subject, $htmlBody, $textBody, $replyTo);
        $this->write($message);
        $this->write('.');
        $this->expect(250);
        $this->command('QUIT', 221);
        fclose($this->socket);
        return true;
    }

    private function authenticate() {
        $this->command('AUTH LOGIN', 334);
        $this->command(base64_encode($this->username), 334);
        $this->command(base64_encode($this->password), 235);
    }

    private function connect() {
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $this->socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT);
        if (!$this->socket) {
            throw new RuntimeException("SMTP connect failed ({$errno}): {$errstr}");
        }
        stream_set_timeout($this->socket, $this->timeout);
    }

    private function buildMessage($fromEmail, $fromName, $to, $subject, $htmlBody, $textBody, $replyTo) {
        $boundary = 'b_' . bin2hex(random_bytes(8));
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $lines = [
            'Date: ' . date('r'),
            'From: ' . $this->encodeAddress($fromName, $fromEmail),
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        if ($replyTo) {
            $lines[] = 'Reply-To: ' . $replyTo;
        }

        $body = implode("\r\n", $lines) . "\r\n\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $this->dotStuff($textBody) . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $this->dotStuff($htmlBody) . "\r\n";
        $body .= '--' . $boundary . '--';
        return $body;
    }

    private function encodeAddress($name, $email) {
        $safeName = str_replace(['"', "\r", "\n"], '', $name);
        return '"' . $safeName . '" <' . $email . '>';
    }

    private function dotStuff($text) {
        return preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $text));
    }

    private function command($command, $expectedCodes) {
        $this->write($command);
        $this->expect($expectedCodes);
    }

    private function write($data) {
        fwrite($this->socket, $data . "\r\n");
    }

    private function expect($expectedCodes) {
        $response = '';
        while ($line = fgets($this->socket, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr(trim($response), 0, 3);
        $expected = (array) $expectedCodes;
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP error: ' . trim($response));
        }
        return $response;
    }
}

function sendSmtpMail(array $config, $to, $subject, $htmlBody, $textBody) {
    $config = finalizeMailConfig($config);
    $client = new RdommsSmtpClient($config['smtp']);
    return $client->send(
        $config['from_email'],
        $config['from_name'],
        $to,
        $subject,
        $htmlBody,
        $textBody,
        $config['reply_to'] ?? null
    );
}

function saveEmailToOutbox($to, $subject, $htmlBody, $textBody) {
    $outboxDir = dirname(__DIR__) . '/storage/mail-outbox';
    if (!is_dir($outboxDir) && !mkdir($outboxDir, 0775, true) && !is_dir($outboxDir)) {
        return false;
    }

    $safeEmail = preg_replace('/[^a-zA-Z0-9._-]/', '_', $to);
    $filename = date('Ymd_His') . '_' . $safeEmail . '.html';
    $path = $outboxDir . '/' . $filename;

    $content = "<!-- To: {$to} | Subject: {$subject} -->\n";
    $content .= "<!-- Text version:\n" . htmlspecialchars($textBody, ENT_QUOTES, 'UTF-8') . "\n-->\n";
    $content .= $htmlBody;

    return file_put_contents($path, $content) !== false;
}

function sendPasswordResetEmail($to, $fullname, $resetLink, $expiresMinutes = 60) {
    $config = getMailConfig();
    $subject = 'Reset your RDO|MMS password';
    $htmlBody = buildPasswordResetEmailHtml($fullname, $resetLink, $expiresMinutes);
    $textBody = "Hello " . ($fullname ?: 'User') . ",\n\n";
    $textBody .= "Reset your password using this link (expires in {$expiresMinutes} minutes):\n";
    $textBody .= $resetLink . "\n\n";
    $textBody .= "If you did not request this, ignore this email.\n";

    $smtpReady = !empty($config['smtp']['enabled'])
        && !empty($config['smtp']['username'])
        && !empty($config['smtp']['password']);

    if ($smtpReady) {
        try {
            sendSmtpMail($config, $to, $subject, $htmlBody, $textBody);
            return ['success' => true, 'method' => 'smtp', 'message' => 'Email sent via SMTP'];
        } catch (Throwable $e) {
            error_log('SMTP send failed for ' . $to . ': ' . $e->getMessage());
            return ['success' => false, 'method' => 'smtp', 'message' => formatSmtpErrorMessage($e->getMessage())];
        }
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ' . sprintf('"%s" <%s>', $config['from_name'], $config['from_email']),
    ];
    if (!empty($config['reply_to'])) {
        $headers[] = 'Reply-To: ' . $config['reply_to'];
    }

    if (@mail($to, $subject, $htmlBody, implode("\r\n", $headers))) {
        return ['success' => true, 'method' => 'mail', 'message' => 'Email sent via PHP mail()'];
    }

    if (!empty($config['fallback_outbox']) && saveEmailToOutbox($to, $subject, $htmlBody, $textBody)) {
        return [
            'success' => true,
            'method' => 'outbox',
            'message' => 'Email saved to local outbox (storage/mail-outbox). Configure SMTP for real delivery.',
        ];
    }

    return [
        'success' => false,
        'method' => 'none',
        'message' => 'SMTP is not configured. Open settings/email-smtp.html and add your Gmail SMTP credentials.',
    ];
}

function ensurePasswordResetTokensTable(PDO $conn) {
    $conn->exec(
        'CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            email VARCHAR(100) NOT NULL,
            token VARCHAR(128) UNIQUE NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_expires (expires_at),
            INDEX idx_user (user_id)
        )'
    );
}
