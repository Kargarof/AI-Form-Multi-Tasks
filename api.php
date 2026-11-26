<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/sync.php';

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

function random_code(int $len = 12): string
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
    $m = db()->prepare("SELECT u.id, u.display_name, u.email, u.avatar_url, pm.role
        FROM project_members pm JOIN users u ON u.id = pm.user_id
        WHERE pm.project_id = ? ORDER BY pm.role = 'owner' DESC, pm.joined_at");
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
    $mode = db()->prepare('SELECT conflict_mode FROM projects WHERE id = ?');
    $mode->execute([$projectId]);
    return ['members' => $members, 'pending' => $pending, 'link' => $link, 'conflict_mode' => (string) $mode->fetchColumn()];
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
            respond(['user' => public_user($user), 'google' => google_enabled(), 'csrf' => csrf_token()]);

        case 'POST me_update':
            $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')) ?? ''), 0, 80);
            $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$name !== '' ? $name : null, $uid]);
            respond(['user' => public_user(current_user(true))]);

        case 'POST recovery_code':
            respond(['code' => new_recovery_code($uid)]);

        case 'POST sign_out':
            sign_out_device();
            respond(['ok' => true]);

        // ---------- Sincronización (todas las altas y cambios de tareas y proyectos) ----------
        case 'POST sync':
            $ops = $in['ops'] ?? [];
            if (!is_array($ops) || count($ops) > 100) {
                fail('Petición no válida.');
            }
            $results = [];
            foreach ($ops as $op) {
                $results[] = is_array($op) ? process_op($op, $uid) : ['status' => 'rejected', 'error' => 'Operación no válida.'];
            }
            sync_housekeeping();
            respond(['results' => $results]);

        // ---------- Proyectos ----------
        case 'GET projects':
            $stmt = $pdo->prepare(
                "SELECT p.id, p.client_id, p.name, p.conflict_mode, pm.role, pm.position, o.display_name AS owner_name, o.email AS owner_email,
                    (SELECT COUNT(*) FROM project_members x WHERE x.project_id = p.id) AS members,
                    (SELECT COUNT(*) FROM task_conflicts c WHERE c.project_id = p.id) AS conflicts,
                    (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.deleted_at IS NULL AND t.status = 'pendiente') AS c_p,
                    (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.deleted_at IS NULL AND t.status = 'en_curso') AS c_e,
                    (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.deleted_at IS NULL AND t.status = 'completado') AS c_c
                 FROM project_members pm
                 JOIN projects p ON p.id = pm.project_id
                 JOIN users o ON o.id = p.owner_id
                 WHERE pm.user_id = ?
                 ORDER BY pm.position, p.id"
            );
            $stmt->execute([$uid]);
            respond(['projects' => array_map(static fn ($p) => [
                'id'            => (int) $p['id'],
                'client_id'     => $p['client_id'],
                'name'          => $p['name'],
                'role'          => $p['role'],
                'members'       => (int) $p['members'],
                'conflict_mode' => $p['conflict_mode'],
                'conflicts'     => $p['role'] === 'owner' ? (int) $p['conflicts'] : 0,
                'owner'         => $p['role'] === 'owner' ? null : ($p['owner_name'] ?: ($p['owner_email'] ?: 'Otra persona')),
                'counts'        => ['pendiente' => (int) $p['c_p'], 'en_curso' => (int) $p['c_e'], 'completado' => (int) $p['c_c']],
            ], $stmt->fetchAll())]);

        case 'POST project_delete':
            $id = int_param($in, 'id');
            require_owner($id, $uid);
            $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
            respond(['ok' => true]);

        case 'POST project_leave':
            $id = int_param($in, 'id');
            if (require_member($id, $uid) === 'owner') {
                fail('Eres quien creó el proyecto: puedes eliminarlo, pero no salir de él.');
            }
            $pdo->prepare('DELETE FROM project_members WHERE project_id = ? AND user_id = ?')->execute([$id, $uid]);
            respond(['ok' => true]);

        case 'POST project_settings':
            $id = int_param($in, 'id');
            require_owner($id, $uid);
            $mode = (string) ($in['conflict_mode'] ?? '');
            if (!in_array($mode, CONFLICT_MODES, true)) {
                fail('Opción no válida.');
            }
            $pdo->prepare('UPDATE projects SET conflict_mode = ? WHERE id = ?')->execute([$mode, $id]);
            respond(['ok' => true, 'conflict_mode' => $mode]);

        // ---------- Conflictos ----------
        case 'GET conflicts':
            $id = int_param($_GET, 'project_id');
            require_owner($id, $uid);
            respond(['conflicts' => conflicts_for_project($id)]);

        case 'POST conflict_resolve':
            $choice = (string) ($in['choice'] ?? '');
            if (!in_array($choice, ['current', 'incoming'], true)) {
                fail('Opción no válida.');
            }
            resolve_conflict(int_param($in, 'id'), $choice, $uid);
            respond(['ok' => true]);

        // ---------- Compartir ----------
        case 'GET sharing':
            $id = int_param($_GET, 'project_id');
            $role = require_member($id, $uid);
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
            respond(['project' => [
                'id'     => (int) $p['id'],
                'name'   => $p['name'],
                'owner'  => $p['owner_name'] ?: ($p['owner_email'] ?: 'Otra persona'),
                'member' => member_role((int) $p['id'], $uid) !== null,
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
            require_member($pid, $uid);
            $stmt = $pdo->prepare('SELECT t.*, u.display_name AS updated_by_name FROM tasks t LEFT JOIN users u ON u.id = t.updated_by
                WHERE t.project_id = ? AND t.deleted_at IS NULL ORDER BY t.position, t.id');
            $stmt->execute([$pid]);
            respond(['tasks' => array_map('normalize_task', $stmt->fetchAll())]);

        default:
            fail('Acción desconocida.', 404);
    }
} catch (SyncReject $ex) {
    fail($ex->getMessage(), in_array($ex->getCode(), [403, 404], true) ? $ex->getCode() : 400);
} catch (Throwable $ex) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[AI Form Multi Tasks] ' . $ex->getMessage());
    fail('Error del servidor. Inténtalo de nuevo.', 500);
}
