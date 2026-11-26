<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (isset($_GET['unirse']) && is_string($_GET['unirse']) && preg_match('/^[A-Za-z0-9]{6,32}$/', $_GET['unirse'])) {
    $_SESSION['pending_join'] = $_GET['unirse'];
}
$join = $_SESSION['pending_join'] ?? null;
$home = $join ? './?unirse=' . rawurlencode($join) : './';

if (current_user()) {
    redirect($home);
}

$error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
$showCode = false;
$name = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!check_csrf($_POST['csrf'] ?? null)) {
        $error = 'La página ha caducado. Vuelve a intentarlo.';
    } elseif ($action === 'start') {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')) ?? '');
        $userId = create_user(['display_name' => $name !== '' ? mb_substr($name, 0, 80) : null]);
        sign_in_device($userId);
        $_SESSION['flash'] = 'welcome';
        redirect($home);
    } elseif ($action === 'code') {
        $showCode = true;
        throttle('code');
        $user = user_by_recovery_code((string) ($_POST['code'] ?? ''));
        if ($user) {
            unset($_SESSION['fails']['code']);
            sign_in_device((int) $user['id']);
            redirect($home);
        }
        throttle_fail('code');
        $error = 'Ese código no es válido. Revisa que esté completo.';
    }
}

$csrf = csrf_token();
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Bienvenido · <?= e(app_name()) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<?php include __DIR__ . '/lib/pwa_head.php'; ?>
</head>
<body class="auth">
<main class="panel auth-card welcome">
  <div class="panel-head">
    <div class="icon-box"><img src="assets/icon.svg" alt="" width="34" height="34"></div>
    <div>
      <div class="eyebrow">AI Form Consulting</div>
      <h1><?= e(app_name()) ?></h1>
    </div>
  </div>

  <?php if ($join): ?>
    <p class="notice">Te han invitado a un proyecto compartido. Entra para unirte.</p>
  <?php endif; ?>
  <?php if ($error): ?><p class="form-error" role="alert"><?= e($error) ?></p><?php endif; ?>

  <p class="auth-intro">Tus proyectos y tareas, organizados en Pendiente, En curso y Completado. No hace falta registrarse.</p>

  <p class="install-first" id="installFirst" hidden>
    <b>En iPhone:</b> instala primero la app (<b>Compartir</b> → <b>Añadir a pantalla de inicio</b>) y ábrela desde su icono. Así tus tareas se quedan dentro de la app.
  </p>

  <form method="post" class="auth-form">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="start">
    <label>Tu nombre <span class="optional">(opcional, lo verán las personas con las que compartas proyectos)</span>
      <input name="name" maxlength="80" autocomplete="given-name" value="<?= e($name) ?>">
    </label>
    <button class="btn btn-primary" type="submit">Empezar</button>
  </form>

  <?php if (google_enabled()): ?>
    <div class="divider"><span>o</span></div>
    <a class="btn btn-google" href="oauth.php?start=1&amp;csrf=<?= e($csrf) ?>">
      <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
      Continuar con Google
    </a>
    <p class="hint">Con Google no pierdes tus tareas si cambias de teléfono y puedes compartir proyectos por Gmail.</p>
  <?php endif; ?>

  <details class="code-login"<?= $showCode ? ' open' : '' ?>>
    <summary>Tengo un código de acceso</summary>
    <form method="post" class="auth-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="code">
      <label>Código de acceso<input name="code" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX" required></label>
      <button class="btn" type="submit">Entrar con el código</button>
    </form>
  </details>
  <p class="legal-footer"><a href="privacidad.html">Privacidad</a> · <a href="condiciones.html">Condiciones</a></p>
</main>
<script>
  (function () {
    var ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (ios && !standalone) document.getElementById('installFirst').hidden = false;
  })();
</script>
</body>
</html>
