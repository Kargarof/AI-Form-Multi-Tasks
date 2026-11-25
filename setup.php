<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth_page.php';

// Solo se puede usar mientras no exista ningún usuario.
if (users_exist()) {
    header('Location: login.php');
    exit;
}

$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');

    if (!check_csrf($_POST['csrf'] ?? null)) {
        $error = 'La sesión ha caducado. Vuelve a intentarlo.';
    } elseif (!preg_match('/^[\p{L}\p{N}._-]{3,60}$/u', $username)) {
        $error = 'El usuario debe tener entre 3 y 60 caracteres (letras, números, punto, guion o guion bajo).';
    } elseif (mb_strlen($password) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($password !== $confirm) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $stmt = db()->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), now()]);
        log_in(['id' => db()->lastInsertId(), 'username' => $username]);
        header('Location: ./');
        exit;
    }
}

$form = '<p class="auth-intro">Primera vez: crea tu usuario de acceso. Esta pantalla se desactiva en cuanto exista un usuario.</p>
<form method="post" class="auth-form">
  <input type="hidden" name="csrf" value="' . e(csrf_token()) . '">
  <label>Usuario<input name="username" autocomplete="username" required value="' . e($username) . '" autofocus></label>
  <label>Contraseña<input type="password" name="password" autocomplete="new-password" minlength="8" required></label>
  <label>Repite la contraseña<input type="password" name="confirm" autocomplete="new-password" minlength="8" required></label>
  <button class="btn btn-primary" type="submit">Crear usuario y entrar</button>
</form>';

auth_page('Configuración inicial', 'Gestor de tareas', $form, $error);
