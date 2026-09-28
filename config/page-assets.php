<?php
declare(strict_types=1);

function rdomms_assets_relative_prefix(string $fromDirectory): string
{
    $root = realpath(dirname(__DIR__));
    $from = realpath($fromDirectory);

    if ($root === false || $from === false || !str_starts_with($from, $root)) {
        return '../assets/';
    }

    $relative = trim(str_replace('\\', '/', substr($from, strlen($root))), '/');
    $depth = $relative === '' ? 0 : substr_count($relative, '/') + 1;

    return str_repeat('../', $depth) . 'assets/';
}

function rdomms_offline_icon_stylesheet_tag(string $assetsPrefix): string
{
    return '<link rel="stylesheet" href="' . htmlspecialchars($assetsPrefix, ENT_QUOTES, 'UTF-8') . 'css/rdomms-icons-offline.css">';
}

function rdomms_inject_offline_icon_assets(string $html, string $assetsPrefix): string
{
    if (stripos($html, 'rdomms-icons-offline.css') !== false) {
        return $html;
    }

    $offlineLink = rdomms_offline_icon_stylesheet_tag($assetsPrefix);

    if (preg_match('/(<link[^>]+fontawesome\/css\/all\.min\.css[^>]*>)/i', $html, $matches)) {
        return str_replace($matches[1], $matches[1] . "\n" . $offlineLink, $html);
    }

    if (preg_match('/(<link[^>]+financial-reports-icons\.css[^>]*>)/i', $html, $matches)) {
        return str_replace($matches[1], $matches[1] . "\n" . $offlineLink, $html);
    }

    if (stripos($html, '</head>') !== false) {
        return str_ireplace('</head>', $offlineLink . "\n</head>", $html);
    }

    return $html;
}

function rdomms_session_countdown_stylesheet_tag(string $assetsPrefix): string
{
    return '<link rel="stylesheet" href="' . htmlspecialchars($assetsPrefix, ENT_QUOTES, 'UTF-8') . 'css/rdomms-session-countdown.css?v=4">';
}

function rdomms_inject_session_countdown_assets(string $html, string $assetsPrefix): string
{
    if (stripos($html, 'rdomms-session-countdown.css') !== false) {
        return $html;
    }

    if (!preg_match('/localStorage-service\.js/i', $html)) {
        return $html;
    }

    $countdownLink = rdomms_session_countdown_stylesheet_tag($assetsPrefix);

    if (preg_match('/(<link[^>]+rdomms-icons-offline\.css[^>]*>)/i', $html, $matches)) {
        return str_replace($matches[1], $matches[1] . "\n" . $countdownLink, $html);
    }

    if (preg_match('/(<link[^>]+fontawesome\/css\/all\.min\.css[^>]*>)/i', $html, $matches)) {
        return str_replace($matches[1], $matches[1] . "\n" . $countdownLink, $html);
    }

    if (stripos($html, '</head>') !== false) {
        return str_ireplace('</head>', $countdownLink . "\n</head>", $html);
    }

    return $html;
}

function rdomms_responsive_stylesheet_tag(string $assetsPrefix): string
{
    return '<link rel="stylesheet" href="' . htmlspecialchars($assetsPrefix, ENT_QUOTES, 'UTF-8') . 'css/rdomms-responsive.css">';
}

function rdomms_inject_responsive_assets(string $html, string $assetsPrefix): string
{
    if (stripos($html, 'rdomms-responsive.css') !== false) {
        return $html;
    }

    $responsiveLink = rdomms_responsive_stylesheet_tag($assetsPrefix);

    if (preg_match('/(<meta[^>]+viewport[^>]*>)/i', $html, $matches)) {
        return str_replace($matches[1], $matches[1] . "\n" . $responsiveLink, $html);
    }

    if (stripos($html, '</head>') !== false) {
        return str_ireplace('</head>', $responsiveLink . "\n</head>", $html);
    }

    return $html;
}

function rdomms_security_script_tag(string $assetsPrefix): string
{
    return '<script src="' . htmlspecialchars($assetsPrefix, ENT_QUOTES, 'UTF-8') . 'js/rdomms-security.js"></script>';
}

function rdomms_inject_security_assets(string $html, string $assetsPrefix): string
{
    if (stripos($html, 'rdomms-security.js') !== false) {
        return $html;
    }

    $securityScript = rdomms_security_script_tag($assetsPrefix);

    if (preg_match('/<script[^>]+src=["\'][^"\']*localStorage-service\.js[^"\']*["\'][^>]*>\s*<\/script>/i', $html)) {
        return preg_replace(
            '/<script([^>]+src=["\'][^"\']*localStorage-service\.js[^"\']*["\'][^>]*)\s*>\s*<\/script>/i',
            $securityScript . "\n" . '<script$1></script>',
            $html,
            1
        );
    }

    if (preg_match('/<head([^>]*)>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
        $replacement = '<head' . $matches[1][0] . '>' . "\n" . $securityScript;
        return substr_replace($html, $replacement, $matches[0][1], strlen($matches[0][0]));
    }

    if (stripos($html, '</head>') !== false) {
        return str_ireplace('</head>', $securityScript . "\n</head>", $html);
    }

    return $html;
}

function rdomms_load_page_html(string $templatePath): string
{
    if (!is_readable($templatePath)) {
        http_response_code(500);
        echo 'Page template not found.';
        exit;
    }

    $html = file_get_contents($templatePath);
    if ($html === false) {
        http_response_code(500);
        echo 'Page template not found.';
        exit;
    }

    $assetsPrefix = rdomms_assets_relative_prefix(dirname($templatePath));
    $html = rdomms_inject_offline_icon_assets($html, $assetsPrefix);

    return rdomms_inject_security_assets(
        rdomms_inject_session_countdown_assets(
            rdomms_inject_responsive_assets($html, $assetsPrefix),
            $assetsPrefix
        ),
        $assetsPrefix
    );
}
