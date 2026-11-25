<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $code = 400): never
{
    respond($code, ['error' => $message]);
}

function int_param(array $src, string $key): int
{
    $v = $src[$key] ?? null;
    if (!is_numeric($v) || (int) $v <= 0) {
        fail("Falta o no es válido: $key");
    }
    return (int) $v;
}

function project_exists(int $id): bool
{
    $stmt = db()->prepare('SELECT 1 FROM projects WHERE id = ?');
    $stmt->execute([$id]);
    return (bool) $stmt->fetchColumn();
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

function task_row(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM tasks WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('La tarea no existe.', 404);
    }
    return normalize_task($row);
}

function normalize_task(array $t): array
{
    return [
        'id'         => (int) $t['id'],
        'project_id' => (int) $t['project_id'],
        'title'      => $t['title'],
        'notes'      => $t['notes'],
        'due_date'   => $t['due_date'] ? substr((string) $t['due_date'], 0, 10) : null,
        'status'     => $t['status'],
        'position'   => (int) $t['position'],
        'updated_at' => $t['updated_at'],
    ];
}

function next_position(int $projectId, string $status): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM tasks WHERE project_id = ? AND status = ?');
    $stmt->execute([$projectId, $status]);
    return (int) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------------

if (!current_user()) {
    fail('Sesión caducada. Vuelve a entrar.', 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = (string) ($_GET['action'] ?? '');
$input  = [];

if ($method === 'POST') {
    if (!check_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        fail('Sesión caducada. Recarga la página.', 403);
    }
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        fail('Petición no válida.');
    }
}

try {
    $pdo = db();

    switch ("$method $action") {
        case 'GET projects':
            $rows = $pdo->query(
                "SELECT p.id, p.name, p.position,
                    SUM(CASE WHEN t.status = 'pendiente' THEN 1 ELSE 0 END) AS pendiente,
                    SUM(CASE WHEN t.status = 'en_curso' THEN 1 ELSE 0 END) AS en_curso,
                    SUM(CASE WHEN t.status = 'completado' THEN 1 ELSE 0 END) AS completado
                 FROM projects p LEFT JOIN tasks t ON t.project_id = p.id
                 GROUP BY p.id, p.name, p.position
                 ORDER BY p.position, p.id"
            )->fetchAll();
            respond(200, ['projects' => array_map(static fn ($p) => [
                'id'     => (int) $p['id'],
                'name'   => $p['name'],
                'counts' => [
                    'pendiente'  => (int) $p['pendiente'],
                    'en_curso'   => (int) $p['en_curso'],
                    'completado' => (int) $p['completado'],
                ],
            ], $rows)]);

        case 'GET tasks':
            $projectId = int_param($_GET, 'project_id');
            $stmt = $pdo->prepare('SELECT * FROM tasks WHERE project_id = ? ORDER BY position, id');
            $stmt->execute([$projectId]);
            respond(200, ['tasks' => array_map('normalize_task', $stmt->fetchAll())]);

        case 'POST project_save':
            $name = clean_text($input['name'] ?? '', 120);
            if ($name === '') {
                fail('El proyecto necesita un nombre.');
            }
            if (!empty($input['id'])) {
                $id = int_param($input, 'id');
                $pdo->prepare('UPDATE projects SET name = ? WHERE id = ?')->execute([$name, $id]);
            } else {
                $pos = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM projects')->fetchColumn();
                $pdo->prepare('INSERT INTO projects (name, position, created_at) VALUES (?, ?, ?)')
                    ->execute([$name, $pos, now()]);
                $id = (int) $pdo->lastInsertId();
            }
            respond(200, ['project' => ['id' => $id, 'name' => $name]]);

        case 'POST project_delete':
            $id = int_param($input, 'id');
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM tasks WHERE project_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
            $pdo->commit();
            respond(200, ['ok' => true]);

        case 'POST task_save':
            $title = clean_text($input['title'] ?? '', 255);
            if ($title === '') {
                fail('La tarea necesita un título.');
            }
            $projectId = int_param($input, 'project_id');
            if (!project_exists($projectId)) {
                fail('El proyecto no existe.', 404);
            }
            $notes  = clean_notes($input['notes'] ?? null);
            $due    = clean_date($input['due_date'] ?? null);
            $status = clean_status($input['status'] ?? 'pendiente');

            if (!empty($input['id'])) {
                $id = int_param($input, 'id');
                $old = task_row($id);
                $pos = ($old['status'] !== $status || $old['project_id'] !== $projectId)
                    ? next_position($projectId, $status)
                    : $old['position'];
                $pdo->prepare('UPDATE tasks SET project_id = ?, title = ?, notes = ?, due_date = ?, status = ?, position = ?, updated_at = ? WHERE id = ?')
                    ->execute([$projectId, $title, $notes, $due, $status, $pos, now(), $id]);
            } else {
                $pdo->prepare('INSERT INTO tasks (project_id, title, notes, due_date, status, position, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$projectId, $title, $notes, $due, $status, next_position($projectId, $status), now(), now()]);
                $id = (int) $pdo->lastInsertId();
            }
            respond(200, ['task' => task_row($id)]);

        case 'POST task_move':
            $id     = int_param($input, 'id');
            $status = clean_status($input['status'] ?? '');
            $order  = $input['order'] ?? [];
            if (!is_array($order)) {
                fail('Orden no válido.');
            }
            $task = task_row($id);
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE tasks SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, now(), $id]);
            $upd = $pdo->prepare('UPDATE tasks SET position = ? WHERE id = ? AND project_id = ? AND status = ?');
            foreach (array_values($order) as $i => $tid) {
                if (is_numeric($tid)) {
                    $upd->execute([$i + 1, (int) $tid, $task['project_id'], $status]);
                }
            }
            $pdo->commit();
            respond(200, ['task' => task_row($id)]);

        case 'POST task_delete':
            $id = int_param($input, 'id');
            $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
            respond(200, ['ok' => true]);

        default:
            fail('Acción desconocida.', 404);
    }
} catch (Throwable $ex) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[AI Tarea] ' . $ex->getMessage());
    fail('Error del servidor. Inténtalo de nuevo.', 500);
}
