<?php
declare(strict_types=1);

/*
 * Cuentas
 * - Cada dispositivo tiene su propia llave (cookie «mt_device», 400 días, se renueva al usarla).
 *   En la base de datos solo se guarda el hash de esa llave.
 * - Una cuenta puede tener varios dispositivos: se añaden entrando con Google o con el código de acceso.
 * - Sin registro: «Empezar» crea una cuenta anónima ligada solo a ese dispositivo.
 */

const DEVICE_COOKIE = 'mt_device';
const DEVICE_COOKIE_DAYS = 400;

function token_hash(string $token): string
{
    return hash('sha256', $token);
}

function set_device_cookie(string $token): void
{
    setcookie(DEVICE_COOKIE, $token, [
        'expires'  => time() + DEVICE_COOKIE_DAYS * 86400,
        'path'     => BASE_PATH,
        'secure'   => IS_HTTPS,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[DEVICE_COOKIE] = $token;
}

function clear_device_cookie(): void
{
    setcookie(DEVICE_COOKIE, '', ['expires' => time() - 3600, 'path' => BASE_PATH, 'secure' => IS_HTTPS, 'httponly' => true, 'samesite' => 'Lax']);
    unset($_COOKIE[DEVICE_COOKIE]);
}

/** Usuario de este dispositivo, o null si todavía no tiene cuenta. */
function current_user(bool $refresh = false): ?array
{
    static $cache = false;
    if ($cache !== false && !$refresh) {
        return $cache;
    }
    $token = $_COOKIE[DEVICE_COOKIE] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return $cache = null;
    }
    $stmt = db()->prepare('SELECT u.*, d.id AS device_id, d.last_seen_at AS device_seen
        FROM devices d JOIN users u ON u.id = d.user_id WHERE d.token_hash = ?');
    $stmt->execute([token_hash($token)]);
    $user = $stmt->fetch() ?: null;

    if ($user && strtotime((string) $user['device_seen']) < time() - 3600) {
        db()->prepare('UPDATE devices SET last_seen_at = ? WHERE id = ?')->execute([now(), $user['device_id']]);
        db()->prepare('UPDATE users SET last_seen_at = ? WHERE id = ?')->execute([now(), $user['id']]);
        set_device_cookie($token); // renueva la caducidad
    }
    return $cache = $user;
}

function public_user(array $u): array
{
    return [
        'id'      => (int) $u['id'],
        'name'    => $u['display_name'] ?: null,
        'email'   => $u['email'] ?: null,
        'avatar'  => $u['avatar_url'] ?: null,
        'google'  => !empty($u['google_sub']),
        'hasCode' => !empty($u['recovery_hash']),
        'plan'    => $u['plan'],
    ];
}

function create_user(array $fields = []): int
{
    $stmt = db()->prepare('INSERT INTO users (display_name, google_sub, email, avatar_url, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $fields['display_name'] ?? null,
        $fields['google_sub'] ?? null,
        $fields['email'] ?? null,
        $fields['avatar_url'] ?? null,
        now(), now(),
    ]);
    return (int) db()->lastInsertId();
}

/** Da de alta este dispositivo para la cuenta indicada y deja la sesión iniciada. */
function sign_in_device(int $userId): void
{
    $old = $_COOKIE[DEVICE_COOKIE] ?? null;
    if (is_string($old) && $old !== '') {
        db()->prepare('DELETE FROM devices WHERE token_hash = ?')->execute([token_hash($old)]);
    }
    $token = bin2hex(random_bytes(32));
    $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    db()->prepare('INSERT INTO devices (user_id, token_hash, user_agent, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, token_hash($token), $ua, now(), now()]);
    set_device_cookie($token);
    session_regenerate_id(true);
    unset($_SESSION['csrf']);
    current_user(true);
}

function sign_out_device(): void
{
    $token = $_COOKIE[DEVICE_COOKIE] ?? null;
    if (is_string($token) && $token !== '') {
        db()->prepare('DELETE FROM devices WHERE token_hash = ?')->execute([token_hash($token)]);
    }
    clear_device_cookie();
    $_SESSION = [];
    session_regenerate_id(true);
}

// ---------------------------------------------------------------------------
// Código de acceso (para quien no usa Google): se muestra una sola vez.

function normalize_code(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

function new_recovery_code(int $userId): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sin 0/O ni 1/I para evitar confusiones
    $raw = '';
    for ($i = 0; $i < 24; $i++) {
        $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    db()->prepare('UPDATE users SET recovery_hash = ? WHERE id = ?')->execute([token_hash($raw), $userId]);
    return implode('-', str_split($raw, 4));
}

function user_by_recovery_code(string $code): ?array
{
    $norm = normalize_code($code);
    if (strlen($norm) !== 24) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE recovery_hash = ?');
    $stmt->execute([token_hash($norm)]);
    return $stmt->fetch() ?: null;
}

// ---------------------------------------------------------------------------
// Google

/**
 * Entra con una cuenta de Google ya verificada.
 * - Si este dispositivo tenía una cuenta sin Google, sus proyectos pasan a la cuenta de Google.
 * - Activa las invitaciones pendientes que había para ese Gmail.
 */
function sign_in_with_google(array $g): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $current = current_user();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE google_sub = ?');
        $stmt->execute([$g['sub']]);
        $existing = $stmt->fetch() ?: null;

        if ($existing) {
            $userId = (int) $existing['id'];
            if ($current && (int) $current['id'] !== $userId && empty($current['google_sub'])) {
                merge_accounts((int) $current['id'], $userId);
            }
        } elseif ($current && empty($current['google_sub'])) {
            $userId = (int) $current['id'];
            $pdo->prepare('UPDATE users SET google_sub = ? WHERE id = ?')->execute([$g['sub'], $userId]);
        } else {
            $userId = create_user(['google_sub' => $g['sub']]);
        }

        $pdo->prepare('UPDATE users SET email = ?, avatar_url = ?, display_name = COALESCE(display_name, ?), last_seen_at = ? WHERE id = ?')
            ->execute([$g['email'], $g['picture'] ?? null, $g['name'] ?? null, now(), $userId]);

        accept_email_invites($userId, (string) $g['email']);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    if (!$current || (int) $current['id'] !== $userId) {
        sign_in_device($userId);
    }
    return $userId;
}

/** Pasa todo lo de la cuenta $from a la cuenta $to y borra $from. */
function merge_accounts(int $from, int $to): void
{
    $pdo = db();
    $pdo->prepare('UPDATE projects SET owner_id = ? WHERE owner_id = ?')->execute([$to, $from]);

    // Proyectos en los que ya estaban las dos cuentas: se queda una sola membresía.
    $both = $pdo->prepare('SELECT project_id FROM project_members WHERE user_id = ?');
    $both->execute([$to]);
    $drop = $pdo->prepare('DELETE FROM project_members WHERE user_id = ? AND project_id = ?');
    foreach ($both->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $drop->execute([$from, $pid]);
    }
    $pdo->prepare('UPDATE project_members SET user_id = ? WHERE user_id = ?')->execute([$to, $from]);
    $pdo->prepare("UPDATE project_members SET role = 'owner' WHERE user_id = ? AND project_id IN (SELECT id FROM projects WHERE owner_id = ?)")->execute([$to, $to]);
    $pdo->prepare('UPDATE tasks SET created_by = ? WHERE created_by = ?')->execute([$to, $from]);
    $pdo->prepare('UPDATE tasks SET updated_by = ? WHERE updated_by = ?')->execute([$to, $from]);
    $pdo->prepare('UPDATE project_invites SET created_by = ? WHERE created_by = ?')->execute([$to, $from]);
    $name = $pdo->prepare('SELECT display_name FROM users WHERE id = ?');
    $name->execute([$from]);
    $pdo->prepare('UPDATE users SET display_name = COALESCE(display_name, ?) WHERE id = ?')->execute([$name->fetchColumn() ?: null, $to]);
    $pdo->prepare('DELETE FROM devices WHERE user_id = ?')->execute([$from]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$from]);
}

function accept_email_invites(int $userId, string $email): void
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return;
    }
    $stmt = db()->prepare("SELECT id, project_id FROM project_invites WHERE kind = 'email' AND email = ?");
    $stmt->execute([$email]);
    foreach ($stmt->fetchAll() as $inv) {
        add_member((int) $inv['project_id'], $userId, 'editor');
        db()->prepare('DELETE FROM project_invites WHERE id = ?')->execute([$inv['id']]);
    }
}

function add_member(int $projectId, int $userId, string $role): void
{
    $check = db()->prepare('SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ?');
    $check->execute([$projectId, $userId]);
    if ($check->fetchColumn()) {
        return;
    }
    $pos = db()->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM project_members WHERE user_id = ?');
    $pos->execute([$userId]);
    db()->prepare('INSERT INTO project_members (project_id, user_id, role, position, joined_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$projectId, $userId, $role, (int) $pos->fetchColumn(), now()]);
}

// ---------------------------------------------------------------------------
// CSRF

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

/** Pausa creciente tras intentos fallidos (códigos de acceso). */
function throttle(string $key): void
{
    $fails = (int) ($_SESSION['fails'][$key] ?? 0);
    if ($fails > 0) {
        sleep(min($fails, 5));
    }
}

function throttle_fail(string $key): void
{
    $_SESSION['fails'][$key] = (int) ($_SESSION['fails'][$key] ?? 0) + 1;
}
