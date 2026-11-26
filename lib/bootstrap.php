<?php
declare(strict_types=1);

$configFile = __DIR__ . '/../config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Falta config.php. Copia config.sample.php como config.php y rellena los datos de la base de datos.');
}

$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'America/Denver');

define('BASE_PATH', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/');
define('IS_HTTPS', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'));

session_name('aiform_mt');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => BASE_PATH,
    'secure'   => IS_HTTPS,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

function app_name(): string
{
    global $config;
    return (string) ($config['app_name'] ?? 'AI Form Multi Tasks');
}

function google_enabled(): bool
{
    global $config;
    return !empty($config['google']['client_id']) && !empty($config['google']['client_secret']);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}
