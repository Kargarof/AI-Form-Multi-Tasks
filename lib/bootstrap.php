<?php
declare(strict_types=1);

$configFile = __DIR__ . '/../config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Falta config.php. Copia config.sample.php como config.php y rellena los datos de la base de datos.');
}

$config = require $configFile;

// Los errores se guardan en data/php-errors.log (carpeta bloqueada desde la web) y nunca se muestran al visitante.
$dataDir = __DIR__ . '/../data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0700, true);
}
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $dataDir . '/php-errors.log');
set_exception_handler(static function (Throwable $ex): void {
    error_log(sprintf('%s: %s en %s:%d', get_class($ex), $ex->getMessage(), $ex->getFile(), $ex->getLine()));
    if (!headers_sent()) {
        http_response_code(500);
    }
    $api = str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', 'api.php');
    if ($api) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Error del servidor. Inténtalo de nuevo en un momento.']);
    } else {
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Error</title><body style="font-family:system-ui;background:#111827;color:#e5e7eb;padding:2rem">'
            . '<h1 style="color:#C7AA89">Algo ha fallado</h1><p>Vuelve a intentarlo en un momento.</p><p><a style="color:#C7AA89" href="./">Volver</a></p>';
    }
});
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
