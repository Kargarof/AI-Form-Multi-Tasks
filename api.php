<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $code = 400): never
{
    respond(['error' => $message], $code);
}

function int_param(array $src, string $key): int
{
    $v = $src[$key] ?? null;
    if (!is_numeric($v) || (int) $v <= 0) {
        fail("Falta o no es válido: $key");
    }
    return (int) $v;
}

function clean_text(mixed $v, int $max): string
{
    $s = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
    return mb_substr($s, 0, $max);
}

function clean_notes(mixed $v): ?string
{
    $s = trim(str_replace("\r\n", "\n", (string) ($v ?? '')));
    return $s === '' ? null : mb_substr($s, 0, 10000);
}

function clean_date(mixed $v): ?string
{
    $s = trim((string) ($v ?? ''));
    if ($s === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s) {
        fail('Fecha no válida.');
    }
    return $s;
}

function clean_status(mixed $v): string
{
    $s = (string) $v;
    if (!in_array($s, TASK_STATUSES, true)) {
        fail('Estado no válido.');
    }
    return $s;
}

/** Comprueba que el usuario es miembro del proyecto. Devuelve su rol. */
function membership(int $projectId, int $userId): string
{
    $stmt = db()->prepare('SELECT role FROM project_members WHERE project_id = ? AND user_id = ?');
    $stmt->execute([$projectId, $userId]);
    $role = $stmt->fetchColumn();
    if (!$role) {
        fail('Este proyecto no existe o ya no tienes acceso.', 404);
    }
    return (string) $role;
}

function require_owner(int $projectId, int $userId): void
{
    if (membership($projectId, $userId) !== 'owner') {
        fail('Solo la persona que creó el proyecto puede hacer esto.', 403);
    }
}

function load_task(int $id, int $userId): array
{
    $stmt = db()->prepare('SELECT t.*, u.display_name AS updated_by_name FROM tasks t LEFT JOIN users u ON u.id = t.updated_by WHERE t.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('La tarea ya no existe.', 404);
    }
    membership((int) $row['project_id'], $userId);
    return normalize_task($row);
}

function normalize_task(array $t): array
{
    return [
        'id'              => (int) $t['id'],
        'project_id'      => (int) $t['project_id'],
        'title'           => $t['title'],
        'notes'           => $t['notes'],
        'due_date'        => $t['due_date'] ? substr((string) $t['due_date'], 0, 10) : null,
        'status'          => $t['status'],
        'position'        => (int) $t['position'],
        'updated_at'      => $t['updated_at'],
        'updated_by'      => $t['updated_by'] !== null ? (int) $t['updated_by'] : null,
        'updated_by_name' => $t['updated_by_name'] ?? null,
    ];
}

function next_position(int $projectId, string $status): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM tasks WHERE project_id = ? AND status = ?');
    $stmt->execute([$projectId, $status]);
    return (int) $stmt->fetchColumn();
}

function random_code(int $len = 10): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < $len; $i++) {
        $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $s;
}

function sharing_state(int $projectId, int $userId): array
{
    $m = db()->prepare('SELECT u.id, u.display_name, u.email, u.avatar_url, pm.role
        FROM project_members pm JOIN users u ON u.id = pm.user_id
        WHERE pm.project_id = ? ORDER BY pm.role = \'owner\' DESC, pm.joined_at');
    $m->execute([$projectId]);
    $members = array_map(static fn ($r) => [
        'id'     => (int) $r['id'],
        'name'   => $r['display_name'] ?: null,
        'email'  => $r['email'] ?: null,
        'avatar' => $r['avatar_url'] ?: null,
        'role'   => $r['role'],
        'me'     => (int) $r['id'] === $userId,
    ], $m->fetchAll());

    $i = db()->prepare('SELECT id, kind, code, email FROM project_invites WHERE project_id = ? ORDER BY id');
    $i->execute([$projectId]);
    $link = null;
    $pending = [];
    foreach ($i->fetchAll() as $inv) {
        if ($inv['kind'] === 'link') {
            $link = $inv['code'];
        } else {
            $pending[] = ['id' => (int) $inv['id'], 'email' => $inv['email']];
        }
    }
    return ['members' => $members, 'pending' => $pending, 'link' => $link];
}

// ---------------------------------------------------------------------------

$user = current_user();
if (!$user) {
    fail('No hay ninguna cuenta en este dispositivo.', 401);
}
$uid = (int) $user['id'];

$method = $_SERVER['REQUEST_METHOD'];
$action = (string) ($_GET['action'] ?? '');
$in = [];

if ($method === 'POST') {
    if (!check_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        fail('La sesión ha caducado. Recarga la página.', 403);
    }
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        fail('Petición no válida.');
    }
}

try {
    $pdo = db();

    switch ("$method $action") {
        // ---------- Cuenta ----------
        case 'GET me':
            respond(['user' => public_user($user), 'google' => google_enabled()]);

        case 'POST me_update':
            $name = clean_text($in['name'] ?? '', 80);
            $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$name !== '' ? $name : null, $uid]);
            respond(['user' => public_user(current_user(true))]);

        case 'POST recovery_code':
            respond(['code' => new_recovery_code($uid)]);

        case 'POST sign_out':
            sign_out_device();
            respond(['ok' => true]);

        // ---------- Proyectos ----------
        case 'GET projects':
            $stmt = $pdo->prepare(
                "SELECT p.id, p.name, pm.role, pm.position, o.display_name AS owner_name, o.email AS owner_email,
                    (SELECT COUNT(*) FROM project_members x WHERE x.project_id = p.id) AS members,
                    (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status = 'pendiente') AS c_p,
                    (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status = 'en_curso') AS c_e,
                    (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status = 'completado') AS c_c
                 FROM project_members pm
                 JOIN projects p ON p.id = pm.project_id
                 JOIN users o ON o.id = p.owner_id
                 WHERE pm.user_id = ?
                 ORDER BY pm.position, p.id"
            );
            $stmt->execute([$uid]);
            respond(['projects' => array_map(static fn ($p) => [
                'id'      => (int) $p['id'],
                'name'    => $p['name'],
                'role'    => $p['role'],
                'members' => (int) $p['members'],
                'owner'   => $p['role'] === 'owner' ? null : ($p['owner_name'] ?: ($p['owner_email'] ?: 'Otra persona')),
                'counts'  => ['pendiente' => (int) $p['c_p'], 'en_curso' => (int) $p['c_e'], 'completado' => (int) $p['c_c']],
            ], $stmt->fetchAll())]);

        case 'POST project_save':
            $name = clean_text($in['name'] ?? '', 120);
            if ($name === '') {
                fail('El proyecto necesita un nombre.');
            }
            if (!empty($in['id'])) {
                $id = int_param($in, 'id');
                require_owner($id, $uid);
                $pdo->prepare('UPDATE projects SET name = ? WHERE id = ?')->execute([$name, $id]);
            } else {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO projects (owner_id, name, created_at) VALUES (?, ?, ?)')->execute([$uid, $name, now()]);
                $id = (int) $pdo->lastInsertId();
                add_member($id, $uid, 'owner');
                $pdo->commit();
            }
            respond(['project' => ['id' => $id, 'name' => $name]]);

        case 'POST project_delete':
            $id = int_param($in, 'id');
            require_owner($id, $uid);
            $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
            respond(['ok' => true]);

        case 'POST project_leave':
            $id = int_param($in, 'id');
            if (membership($id, $uid) === 'owner') {
                fail('Eres quien creó el proyecto: puedes eliminarlo, pero no salir de él.');
            }
            $pdo->prepare('DELETE FROM project_members WHERE project_id = ? AND user_id = ?')->execute([$id, $uid]);
            respond(['ok' => true]);

        // ---------- Compartir ----------
        case 'GET sharing':
            $id = int_param($_GET, 'project_id');
            $role = membership($id, $uid);
            respond(['role' => $role, 'google' => google_enabled()] + sharing_state($id, $uid));

        case 'POST invite_email':
            $id = int_param($in, 'project_id');
            require_owner($id, $uid);
            $email = strtolower(trim((string) ($in['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                fail('Escribe un email válido.');
            }
            if ($email === strtolower((string) $user['email'])) {
                fail('Ese es tu propio email.');
            }
            $u = $pdo->prepare('SELECT id FROM users WHERE email = ? AND google_sub IS NOT NULL');
            $u->execute([$email]);
            $existing = $u->fetchColumn();
            if ($existing) {
                add_member($id, (int) $existing, 'editor');
                $added = true;
            } else {
                $dup = $pdo->prepare("SELECT 1 FROM project_invites WHERE project_id = ? AND kind = 'email' AND email = ?");
                $dup->execute([$id, $email]);
                if (!$dup->fetchColumn()) {
                    $pdo->prepare("INSERT INTO project_invites (project_id, kind, email, created_by, created_at) VALUES (?, 'email', ?, ?, ?)")
                        ->execute([$id, $email, $uid, now()]);
                }
                $added = false;
            }
            respond(['added' => $added] + sharing_state($id, $uid));

        case 'POST invite_cancel':
            $id = int_param($in, 'project_id');
            require_owner($id, $uid);
            $pdo->prepare('DELETE FROM project_invites WHERE id = ? AND project_id = ?')->execute([int_param($in, 'invite_id'), $id]);
            respond(sharing_state($id, $uid));

        case 'POST invite_link':
            $id = int_param($in, 'project_id');
            require_owner($id, $uid);
            $pdo->prepare("DELETE FROM project_invites WHERE project_id = ? AND kind = 'link'")->execute([$id]);
            if (empty($in['disable'])) {
                $pdo->prepare("INSERT INTO project_invites (project_id, kind, code, created_by, created_at) VALUES (?, 'link', ?, ?, ?)")
                    ->execute([$id, random_code(12), $uid, now()]);
            }
            respond(sharing_state($id, $uid));

        case 'POST member_remove':
            $id = int_param($in, 'project_id');
            require_owner($id, $uid);
            $target = int_param($in, 'user_id');
            if ($target === $uid) {
                fail('No puedes quitarte a ti mismo.');
            }
            $pdo->prepare('DELETE FROM project_members WHERE project_id = ? AND user_id = ?')->execute([$id, $target]);
            respond(sharing_state($id, $uid));

        case 'GET invite_info':
            $code = (string) ($_GET['code'] ?? '');
            $stmt = $pdo->prepare("SELECT p.id, p.name, o.display_name AS owner_name, o.email AS owner_email
                FROM project_invites i JOIN projects p ON p.id = i.project_id JOIN users o ON o.id = p.owner_id
                WHERE i.kind = 'link' AND i.code = ?");
            $stmt->execute([$code]);
            $p = $stmt->fetch();
            if (!$p) {
                fail('Esta invitación ya no es válida. Pide un enlace nuevo.', 404);
            }
            $m = $pdo->prepare('SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ?');
            $m->execute([$p['id'], $uid]);
            respond(['project' => [
                'id'     => (int) $p['id'],
                'name'   => $p['name'],
                'owner'  => $p['owner_name'] ?: ($p['owner_email'] ?: 'Otra persona'),
                'member' => (bool) $m->fetchColumn(),
            ]]);

        case 'POST join':
            $stmt = $pdo->prepare("SELECT project_id FROM project_invites WHERE kind = 'link' AND code = ?");
            $stmt->execute([(string) ($in['code'] ?? '')]);
            $pid = $stmt->fetchColumn();
            if (!$pid) {
                fail('Esta invitación ya no es válida. Pide un enlace nuevo.', 404);
            }
            add_member((int) $pid, $uid, 'editor');
            unset($_SESSION['pending_join']);
            respond(['project_id' => (int) $pid]);

        // ---------- Tareas ----------
        case 'GET tasks':
            $pid = int_param($_GET, 'project_id');
            membership($pid, $uid);
            $stmt = $pdo->prepare('SELECT t.*, u.display_name AS updated_by_name FROM tasks t LEFT JOIN users u ON u.id = t.updated_by WHERE t.project_id = ? ORDER BY t.position, t.id');
            $stmt->execute([$pid]);
            respond(['tasks' => array_map('normalize_task', $stmt->fetchAll())]);

        case 'POST task_save':
            $title = clean_text($in['title'] ?? '', 255);
            if ($title === '') {
                fail('La tarea necesita un título.');
            }
            $pid = int_param($in, 'project_id');
            membership($pid, $uid);
            $notes  = clean_notes($in['notes'] ?? null);
            $due    = clean_date($in['due_date'] ?? null);
            $status = clean_status($in['status'] ?? 'pendiente');

            if (!empty($in['id'])) {
                $id = int_param($in, 'id');
                $old = load_task($id, $uid);
                $pos = ($old['status'] !== $status || $old['project_id'] !== $pid) ? next_position($pid, $status) : $old['position'];
                $pdo->prepare('UPDATE tasks SET project_id = ?, title = ?, notes = ?, due_date = ?, status = ?, position = ?, updated_by = ?, updated_at = ? WHERE id = ?')
                    ->execute([$pid, $title, $notes, $due, $status, $pos, $uid, now(), $id]);
            } else {
                $pdo->prepare('INSERT INTO tasks (project_id, title, notes, due_date, status, position, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$pid, $title, $notes, $due, $status, next_position($pid, $status), $uid, $uid, now(), now()]);
                $id = (int) $pdo->lastInsertId();
            }
            respond(['task' => load_task($id, $uid)]);

        case 'POST task_move':
            $id = int_param($in, 'id');
            $status = clean_status($in['status'] ?? '');
            $order = $in['order'] ?? [];
            if (!is_array($order)) {
                fail('Orden no válido.');
            }
            $task = load_task($id, $uid);
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE tasks SET status = ?, updated_by = ?, updated_at = ? WHERE id = ?')->execute([$status, $uid, now(), $id]);
            $upd = $pdo->prepare('UPDATE tasks SET position = ? WHERE id = ? AND project_id = ? AND status = ?');
            foreach (array_values($order) as $i => $tid) {
                if (is_numeric($tid)) {
                    $upd->execute([$i + 1, (int) $tid, $task['project_id'], $status]);
                }
            }
            $pdo->commit();
            respond(['task' => load_task($id, $uid)]);

        case 'POST task_delete':
            $id = int_param($in, 'id');
            load_task($id, $uid);
            $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
            respond(['ok' => true]);

        default:
            fail('Acción desconocida.', 404);
    }
} catch (Throwable $ex) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[AI Form Multi Tasks] ' . $ex->getMessage());
    fail('Error del servidor. Inténtalo de nuevo.', 500);
}
