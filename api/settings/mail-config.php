<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mailer.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['success' => false, 'message' => 'Database connection failed'], 500);
}

ensureMailSettingsTable($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $settings = loadMailSettingsFromDatabase($conn);
    sendJSONResponse([
        'success' => true,
        'data' => [
            'smtp_enabled' => !empty($settings['smtp_enabled']),
            'smtp_host' => $settings['smtp_host'] ?? 'smtp.gmail.com',
            'smtp_port' => (int) ($settings['smtp_port'] ?? 587),
            'smtp_encryption' => $settings['smtp_encryption'] ?? 'tls',
            'smtp_username' => $settings['smtp_username'] ?? '',
            'smtp_password_set' => !empty($settings['smtp_password']),
            'from_email' => $settings['from_email'] ?? 'noreply@rdomms.org',
            'from_name' => $settings['from_name'] ?? 'RDO|MMS',
            'app_base_url' => $settings['app_base_url'] ?? getAppBaseUrl(),
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSONResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

$smtpUsername = strtolower(trim((string) ($data['smtp_username'] ?? '')));
$fromEmail = strtolower(trim((string) ($data['from_email'] ?? '')));
$genericFroms = ['', 'noreply@rdomms.org', 'support@rdomms.org'];

if ($smtpUsername !== '' && in_array($fromEmail, $genericFroms, true)) {
    $fromEmail = $smtpUsername;
}

$settings = [
    'smtp_enabled' => !empty($data['smtp_enabled']) ? '1' : '0',
    'smtp_host' => trim((string) ($data['smtp_host'] ?? 'smtp.gmail.com')),
    'smtp_port' => (string) ((int) ($data['smtp_port'] ?? 587)),
    'smtp_encryption' => trim((string) ($data['smtp_encryption'] ?? 'tls')),
    'smtp_username' => $smtpUsername,
    'from_email' => $fromEmail !== '' ? $fromEmail : 'noreply@rdomms.org',
    'from_name' => trim((string) ($data['from_name'] ?? 'RDO|MMS')),
    'app_base_url' => normalizeAppBaseUrl((string) ($data['app_base_url'] ?? getAppBaseUrl())),
];

if (!empty($data['smtp_password'])) {
    $settings['smtp_password'] = normalizeSmtpPassword($data['smtp_password']);
}

saveMailSettingsToDatabase($conn, $settings);

sendJSONResponse([
    'success' => true,
    'message' => 'Mail settings saved successfully.',
]);
