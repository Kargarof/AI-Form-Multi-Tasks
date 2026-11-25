<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth_page.php';

if (!users_exist()) {
    header('Location: setup.php');
    exit;
}
if (current_user()) {
    header('Location: ./');
    exit;
}

$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!check_csrf($_POST['csrf'] ?? null)) {
        $error = 'La sesión ha caducado. Vuelve a intentarlo.';
    } else {
        login_throttle();
        $stmt = db()->prepare('SELECT id, username, password_hash FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            unset($_SESSION['login_fails']);
            log_in($user);
            header('Location: ./');
            exit;
        }
        $_SESSION['login_fails'] = (int) ($_SESSION['login_fails'] ?? 0) + 1;
        $error = 'Usuario o contraseña incorrectos.';
    }
}

$form = '<form method="post" class="auth-form" autocomplete="on">
  <input type="hidden" name="csrf" value="' . e(csrf_token()) . '">
  <label>Usuario<input name="username" autocomplete="username" required value="' . e($username) . '" autofocus></label>
  <label>Contraseña<input type="password" name="password" autocomplete="current-password" required></label>
  <button class="btn btn-primary" type="submit">Entrar</button>
</form>';

auth_page('AI Form Consulting', 'Gestor de tareas', $form, $error);
