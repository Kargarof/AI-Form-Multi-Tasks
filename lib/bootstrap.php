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

$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
$isHttps  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('aitarea');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path'     => $basePath,
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
