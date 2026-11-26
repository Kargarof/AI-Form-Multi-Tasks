<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/google.php';

// Inicio: ?start=1 (con token CSRF) manda al usuario a Google.
// Vuelta: Google redirige aquí con ?code=…&state=…

if (!google_enabled()) {
    redirect('./');
}

if (isset($_GET['start'])) {
    if (!check_csrf($_GET['csrf'] ?? null)) {
        redirect('./');
    }
    header('Cache-Control: no-store');
    redirect(google_auth_url());
}

try {
    sign_in_with_google(google_handle_callback($_GET));
} catch (Throwable $ex) {
    error_log('[AI Form Multi Tasks] Google: ' . $ex->getMessage());
    $_SESSION['flash_error'] = $ex instanceof RuntimeException ? $ex->getMessage() : 'Error al iniciar sesión con Google.';
    redirect(current_user() ? './' : 'welcome.php');
}

$_SESSION['flash'] = 'google_ok';
$join = $_SESSION['pending_join'] ?? null;
redirect($join ? './?unirse=' . rawurlencode($join) : './');
