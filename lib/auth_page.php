<?php
declare(strict_types=1);

/** Pinta la pantalla de acceso (login y primera configuración). */
function auth_page(string $eyebrow, string $title, string $formHtml, ?string $error = null): void
{
    global $config;
    $appName = $config['app_name'] ?? 'AI Tarea';
    ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e($appName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
</head>
<body class="auth">
<main class="panel auth-card">
  <div class="panel-head">
    <div class="icon-box"><?php include __DIR__ . '/../assets/board-icon.svg'; ?></div>
    <div>
      <div class="eyebrow"><?= e($eyebrow) ?></div>
      <h1><?= e($title) ?></h1>
    </div>
  </div>
  <?php if ($error): ?><p class="form-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <?= $formHtml ?>
</main>
</body>
</html>
<?php
}
