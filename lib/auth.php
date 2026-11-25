<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: ' . (users_exist() ? 'login.php' : 'setup.php'));
        exit;
    }
}

function users_exist(): bool
{
    return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(?string $token): bool
{
    return is_string($token) && hash_equals(csrf_token(), $token);
}

function log_in(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int) $user['id'], 'username' => $user['username']];
    unset($_SESSION['csrf']);
}

/** Pequeña pausa creciente tras intentos fallidos, para frenar ataques de fuerza bruta. */
function login_throttle(): void
{
    $fails = (int) ($_SESSION['login_fails'] ?? 0);
    if ($fails > 0) {
        sleep(min($fails, 5));
    }
}
