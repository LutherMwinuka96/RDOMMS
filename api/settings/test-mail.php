<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mailer.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSONResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

$email = strtolower(trim((string) ($data['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendJSONResponse(['success' => false, 'message' => 'A valid email address is required'], 400);
}

$config = getMailConfig();
$smtpReady = !empty($config['smtp']['enabled'])
    && !empty($config['smtp']['username'])
    && !empty($config['smtp']['password']);

if (!$smtpReady) {
    sendJSONResponse([
        'success' => false,
        'message' => 'SMTP is not configured. Save your Gmail SMTP settings first at settings/email-smtp.html',
    ], 400);
}

$subject = 'RDO|MMS — SMTP test email';
$htmlBody = '<p>If you received this, SMTP is working correctly for <strong>RDO|MMS</strong>.</p>';
$textBody = 'If you received this, SMTP is working correctly for RDO|MMS.';

try {
    sendSmtpMail($config, $email, $subject, $htmlBody, $textBody);
    sendJSONResponse([
        'success' => true,
        'message' => 'Test email sent to ' . $email . ' via SMTP.',
        'data' => ['delivery_method' => 'smtp'],
    ]);
} catch (Throwable $e) {
    error_log('Test mail failed: ' . $e->getMessage());
    sendJSONResponse([
        'success' => false,
        'message' => formatSmtpErrorMessage($e->getMessage()),
    ], 500);
}
