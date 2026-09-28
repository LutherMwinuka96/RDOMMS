<?php
/**
 * Mail configuration for RDOMMS.
 * Set smtp.enabled to true and fill SMTP credentials to send real emails (Gmail, Outlook, etc.).
 */
return [
    'from_email' => 'noreply@rdomms.org',
    'from_name' => 'RDO|MMS',
    'reply_to' => 'support@rdomms.org',
    // Optional local file copy: config/mail.local.php (see mail.local.php.example)
    'app_base_url' => 'http://localhost/RDOMMS',
    // Only saves locally when SMTP/mail() fail — does NOT deliver to inbox
    'fallback_outbox' => true,
    'smtp' => [
        // Set enabled => true and add credentials to send real emails (Gmail, Outlook, etc.)
        'enabled' => false,
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => '',
        'password' => '',
    ],
];
