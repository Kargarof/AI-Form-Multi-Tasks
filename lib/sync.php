<?php
declare(strict_types=1);

/*
 * Sincronización (trabajo sin conexión)
 * ------------------------------------
 * La app guarda cada cambio en una cola en el teléfono y la envía cuando hay conexión.
 * Cada cambio lleva los valores que tenía la tarea cuando se editó («base»), así el
 * servidor sabe si otra persona tocó ese mismo campo mientras tanto:
 *   - Campo no tocado por nadie más  → se aplica.
 *   - Campos distintos cambiados por personas distintas → se juntan los dos cambios.
 *   - El mismo campo cambiado por dos personas = conflicto, y según el proyecto:
 *       'owner'  (por defecto) → se guarda el cambio y decide quien creó el proyecto.
 *       'latest'               → gana el cambio hecho más tarde.
 *   - Si el conflicto es entre dos dispositivos de la misma persona, gana el más reciente.
 * Cada operación lleva un op_id: si se reenvía (conexión cortada), no se aplica dos veces.
 */

const TASK_FIELDS = ['title', 'notes', 'due_date', 'status', 'project_id'];

final class SyncReject extends RuntimeException {}

// ---------- Validación ----------

function v_title(mixed $v): string
{
    $s = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v) ?? ''), 0, 255);
    if ($s === '') {
        throw new SyncReject('La tarea necesita un título.');
    }
    return $s;
}

function v_notes(mixed $v): ?string
{
    $s = trim(str_replace("\r\n", "\n", (string) ($v ?? '')));
    return $s === '' ? null : mb_substr($s, 0, 10000);
}

function v_date(mixed $v): ?string
{
    $s = trim((string) ($v ?? ''));
    if ($s === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s) {
        throw new SyncReject('Fecha no válida.');
    }
    return $s;
}

function v_status(mixed $v): string
{
    if (!in_array($v, TASK_STATUSES, true)) {
        throw new SyncReject('Estado no válido.');
    }
    return (string) $v;
}

function v_project_name(mixed $v): string
{
    $s = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v) ?? ''), 0, 120);
    if ($s === '') {
        throw new SyncReject('El proyecto necesita un nombre.');
    }
    return $s;
}

function v_client_id(mixed $v): string
{
    $s = (string) $v;
    if (!preg_match('/^[A-Za-z0-9-]{8,40}$/', $s)) {
        throw new SyncReject('Identificador no válido.');
    }
    return $s;
}

/** Hora del cambio según el teléfono, acotada (un reloj mal puesto no puede ir al futuro). */
function v_changed_at(mixed $v): string
{
    $ts = is_string($v) ? strtotime($v) : false;
    if (!$ts) {
        $ts = time();
    }
    $ts = max(time() - 60 * 86400, min($ts, time()));
    return date('Y-m-d H:i:s', $ts);
}

// ---------- Referencias (id del servidor o «c:<id del teléfono>» si se creó sin conexión) ----------

function project_id_from_ref(mixed $ref): int
{
    if (is_int($ref) || (is_string($ref) && ctype_digit($ref))) {
        return (int) $ref;
    }
    if (is_string($ref) && str_starts_with($ref, 'c:')) {
        $stmt = db()->prepare('SELECT id FROM projects WHERE client_id = ?');
        $stmt->execute([substr($ref, 2)]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
    }
    throw new SyncReject('Este proyecto no existe o ya no tienes acceso.');
}

function task_row_from_ref(mixed $ref): ?array
{
    if (is_int($ref) || (is_string($ref) && ctype_digit($ref))) {
        $stmt = db()->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([(int) $ref]);
    } elseif (is_string($ref) && str_starts_with($ref, 'c:')) {
        $stmt = db()->prepare('SELECT * FROM tasks WHERE client_id = ?');
        $stmt->execute([substr($ref, 2)]);
    } else {
        return null;
    }
    return $stmt->fetch() ?: null;
}

function member_role(int $projectId, int $userId): ?string
{
    $stmt = db()->prepare('SELECT role FROM project_members WHERE project_id = ? AND user_id = ?');
    $stmt->execute([$projectId, $userId]);
    $r = $stmt->fetchColumn();
    return $r === false ? null : (string) $r;
}

function require_member(int $projectId, int $userId): string
{
    $role = member_role($projectId, $userId);
    if ($role === null) {
        throw new SyncReject('Este proyecto no existe o ya no tienes acceso.', 404);
    }
    return $role;
}

function require_owner(int $projectId, int $userId): void
{
    if (require_member($projectId, $userId) !== 'owner') {
        throw new SyncReject('Solo quien creó el proyecto puede hacer esto.', 403);
    }
}

// ---------- Tareas ----------

function task_out(int $id): array
{
    $stmt = db()->prepare('SELECT t.*, u.display_name AS updated_by_name FROM tasks t LEFT JOIN users u ON u.id = t.updated_by WHERE t.id = ?');
    $stmt->execute([$id]);
    return normalize_task($stmt->fetch());
}

function normalize_task(array $t): array
{
    return [
        'id'              => (int) $t['id'],
        'client_id'       => $t['client_id'] ?? null,
        'project_id'      => (int) $t['project_id'],
        'title'           => $t['title'],
        'notes'           => $t['notes'],
        'due_date'        => $t['due_date'] ? substr((string) $t['due_date'], 0, 10) : null,
        'status'          => $t['status'],
        'position'        => (int) $t['position'],
        'version'         => (int) ($t['version'] ?? 1),
        'deleted'         => !empty($t['deleted_at']),
        'updated_at'      => $t['updated_at'],
        'updated_by'      => $t['updated_by'] !== null ? (int) $t['updated_by'] : null,
        'updated_by_name' => $t['updated_by_name'] ?? null,
    ];
}

function next_position(int $projectId, string $status): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM tasks WHERE project_id = ? AND status = ? AND deleted_at IS NULL');
    $stmt->execute([$projectId, $status]);
    return (int) $stmt->fetchColumn();
}

/** Compara dos valores de un campo de tarea ignorando diferencias de formato (null / "" / número). */
function same_value(string $field, mixed $a, mixed $b): bool
{
    $norm = static function ($v) use ($field) {
        if ($v === null || $v === '') {
            return '';
        }
        if ($field === 'due_date') {
            return substr((string) $v, 0, 10);
        }
        return (string) $v;
    };
    return $norm($a) === $norm($b);
}

function clean_field(string $field, mixed $value, int $uid): mixed
{
    return match ($field) {
        'title'      => v_title($value),
        'notes'      => v_notes($value),
        'due_date'   => v_date($value),
        'status'     => v_status($value),
        'project_id' => (function () use ($value, $uid) {
            $pid = project_id_from_ref($value);
            require_member($pid, $uid);
            return $pid;
        })(),
    };
}

function write_task_fields(int $taskId, array $fields, int $byUser, array $current): void
{
    $sets = [];
    $vals = [];
    foreach ($fields as $k => $v) {
        $sets[] = "$k = ?";
        $vals[] = $v;
    }
    $newProject = (int) ($fields['project_id'] ?? $current['project_id']);
    $newStatus  = (string) ($fields['status'] ?? $current['status']);
    if ($newProject !== (int) $current['project_id'] || $newStatus !== $current['status']) {
        $sets[] = 'position = ?';
        $vals[] = next_position($newProject, $newStatus);
    }
    $sets[] = 'version = version + 1';
    $sets[] = 'updated_by = ?';
    $vals[] = $byUser;
    $sets[] = 'updated_at = ?';
    $vals[] = now();
    $vals[] = $taskId;
    db()->prepare('UPDATE tasks SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
}

function apply_order(int $projectId, string $status, array $order): void
{
    $upd = db()->prepare('UPDATE tasks SET position = ? WHERE id = ? AND project_id = ? AND status = ? AND deleted_at IS NULL');
    $i = 0;
    foreach (array_slice($order, 0, 500) as $ref) {
        $row = task_row_from_ref($ref);
        if ($row) {
            $upd->execute([++$i, (int) $row['id'], $projectId, $status]);
        }
    }
}

function conflict_mode(int $projectId): string
{
    $stmt = db()->prepare('SELECT conflict_mode FROM projects WHERE id = ?');
    $stmt->execute([$projectId]);
    $m = (string) $stmt->fetchColumn();
    return in_array($m, CONFLICT_MODES, true) ? $m : 'owner';
}

function record_conflict(array $task, int $uid, string $kind, array $fields, array $base, string $changedAt): void
{
    db()->prepare('INSERT INTO task_conflicts (project_id, task_id, user_id, kind, fields, base, changed_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int) $task['project_id'], (int) $task['id'], $uid, $kind,
            json_encode($fields, JSON_UNESCAPED_UNICODE), json_encode($base, JSON_UNESCAPED_UNICODE), $changedAt, now()]);
}

// ---------- Operaciones ----------

function op_project_create(array $op, int $uid): array
{
    $cid = v_client_id($op['client_id'] ?? '');
    $name = v_project_name($op['name'] ?? '');
    $stmt = db()->prepare('SELECT id, owner_id, name FROM projects WHERE client_id = ?');
    $stmt->execute([$cid]);
    $existing = $stmt->fetch();
    if ($existing) {
        if ((int) $existing['owner_id'] !== $uid) {
            throw new SyncReject('Identificador repetido.');
        }
        return ['status' => 'applied', 'project' => ['id' => (int) $existing['id'], 'client_id' => $cid, 'name' => $existing['name']]];
    }
    db()->prepare('INSERT INTO projects (owner_id, name, client_id, created_at) VALUES (?, ?, ?, ?)')->execute([$uid, $name, $cid, now()]);
    $id = (int) db()->lastInsertId();
    add_member($id, $uid, 'owner');
    return ['status' => 'applied', 'project' => ['id' => $id, 'client_id' => $cid, 'name' => $name]];
}

function op_project_rename(array $op, int $uid): array
{
    $pid = project_id_from_ref($op['project'] ?? null);
    if (require_member($pid, $uid) !== 'owner') {
        throw new SyncReject('Solo quien creó el proyecto puede cambiarle el nombre.');
    }
    $name = v_project_name($op['name'] ?? '');
    db()->prepare('UPDATE projects SET name = ? WHERE id = ?')->execute([$name, $pid]);
    return ['status' => 'applied', 'project' => ['id' => $pid, 'name' => $name]];
}

function op_task_create(array $op, int $uid): array
{
    $cid = v_client_id($op['client_id'] ?? '');
    $existing = task_row_from_ref('c:' . $cid);
    if ($existing) {
        require_member((int) $existing['project_id'], $uid);
        return ['status' => 'applied', 'task' => task_out((int) $existing['id'])];
    }
    $pid = project_id_from_ref($op['project'] ?? null);
    require_member($pid, $uid);
    $title  = v_title($op['title'] ?? '');
    $notes  = v_notes($op['notes'] ?? null);
    $due    = v_date($op['due_date'] ?? null);
    $status = v_status($op['status'] ?? 'pendiente');
    $at     = v_changed_at($op['changed_at'] ?? null);

    db()->prepare('INSERT INTO tasks (project_id, client_id, title, notes, due_date, status, position, version, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)')
        ->execute([$pid, $cid, $title, $notes, $due, $status, next_position($pid, $status), $uid, $uid, $at, now()]);
    $id = (int) db()->lastInsertId();
    if (!empty($op['order']) && is_array($op['order'])) {
        apply_order($pid, $status, $op['order']);
    }
    return ['status' => 'applied', 'task' => task_out($id)];
}

/** Cambios en campos de una tarea (editar, mover de columna, pasar a otro proyecto). */
function op_task_update(array $op, int $uid): array
{
    $cur = task_row_from_ref($op['task'] ?? null);
    if (!$cur) {
        throw new SyncReject('La tarea ya no existe.');
    }
    require_member((int) $cur['project_id'], $uid);
    if (!empty($cur['deleted_at'])) {
        return ['status' => 'gone', 'task_id' => (int) $cur['id'], 'title' => $cur['title']];
    }

    $fields = is_array($op['fields'] ?? null) ? array_intersect_key($op['fields'], array_flip(TASK_FIELDS)) : [];
    $base   = is_array($op['base'] ?? null) ? $op['base'] : [];
    $at     = v_changed_at($op['changed_at'] ?? null);

    $apply = [];
    $clash = [];
    foreach ($fields as $k => $raw) {
        $new = clean_field($k, $raw, $uid);
        if (same_value($k, $new, $cur[$k])) {
            continue;                       // ya tiene ese valor
        }
        if (!array_key_exists($k, $base)) {
            $apply[$k] = $new;              // cambio hecho con conexión, sin base que comparar
            continue;
        }
        $baseVal = $k === 'project_id' && $base[$k] !== null ? project_id_from_ref($base[$k]) : $base[$k];
        if (same_value($k, $baseVal, $cur[$k])) {
            $apply[$k] = $new;              // nadie lo tocó mientras tanto
        } else {
            $clash[$k] = $new;              // otra persona cambió este mismo campo
        }
    }

    $result = 'applied';
    if ($clash) {
        $sameUser = (int) $cur['updated_by'] === $uid;
        $mode = conflict_mode((int) $cur['project_id']);
        if ($mode === 'latest' || $sameUser) {
            if (strtotime($at) >= strtotime((string) $cur['updated_at'])) {
                $apply += $clash;
            } else {
                $result = 'superseded';     // el otro cambio es más reciente
            }
        } else {
            $baseOut = array_intersect_key($base, $clash);
            record_conflict($cur, $uid, 'update', $clash, $baseOut, $at);
            $result = 'conflict';
        }
    }

    if ($apply) {
        write_task_fields((int) $cur['id'], $apply, $uid, $cur);
    }
    $task = task_out((int) $cur['id']);
    if (isset($op['order']) && is_array($op['order']) && (!$clash || isset($apply['status']))) {
        apply_order($task['project_id'], $task['status'], $op['order']);
        $task = task_out((int) $cur['id']);
    }
    return ['status' => $result, 'task' => $task, 'fields' => array_keys($clash)];
}

function op_task_delete(array $op, int $uid): array
{
    $cur = task_row_from_ref($op['task'] ?? null);
    if (!$cur || !empty($cur['deleted_at'])) {
        return ['status' => 'applied', 'task_id' => $cur ? (int) $cur['id'] : null];
    }
    require_member((int) $cur['project_id'], $uid);
    $base = is_array($op['base'] ?? null) ? $op['base'] : [];
    $at   = v_changed_at($op['changed_at'] ?? null);

    $changedSince = false;
    foreach (TASK_FIELDS as $k) {
        if (array_key_exists($k, $base)) {
            $bv = $k === 'project_id' && $base[$k] !== null ? project_id_from_ref($base[$k]) : $base[$k];
            if (!same_value($k, $bv, $cur[$k])) {
                $changedSince = true;
            }
        }
    }
    if ($changedSince && (int) $cur['updated_by'] !== $uid) {
        if (conflict_mode((int) $cur['project_id']) === 'latest') {
            if (strtotime($at) < strtotime((string) $cur['updated_at'])) {
                return ['status' => 'superseded', 'task' => task_out((int) $cur['id'])];
            }
        } else {
            record_conflict($cur, $uid, 'delete', [], $base, $at);
            return ['status' => 'conflict', 'task' => task_out((int) $cur['id']), 'fields' => ['delete']];
        }
    }
    soft_delete_task((int) $cur['id'], $uid);
    return ['status' => 'applied', 'task_id' => (int) $cur['id']];
}

function soft_delete_task(int $id, int $uid): void
{
    db()->prepare('UPDATE tasks SET deleted_at = ?, version = version + 1, updated_by = ?, updated_at = ? WHERE id = ?')
        ->execute([now(), $uid, now(), $id]);
}

/** Procesa una operación de la cola del teléfono. Nunca se aplica dos veces la misma. */
function process_op(array $op, int $uid): array
{
    $opId = (string) ($op['op_id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9-]{8,40}$/', $opId)) {
        return ['op_id' => $opId, 'status' => 'rejected', 'error' => 'Operación sin identificador.'];
    }
    $done = db()->prepare('SELECT response FROM sync_ops WHERE op_id = ? AND user_id = ?');
    $done->execute([$opId, $uid]);
    if ($prev = $done->fetchColumn()) {
        return json_decode((string) $prev, true);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $res = match ((string) ($op['type'] ?? '')) {
            'project_create' => op_project_create($op, $uid),
            'project_rename' => op_project_rename($op, $uid),
            'task_create'    => op_task_create($op, $uid),
            'task_update'    => op_task_update($op, $uid),
            'task_delete'    => op_task_delete($op, $uid),
            default          => throw new SyncReject('Operación desconocida.'),
        };
        $res = ['op_id' => $opId] + $res;
        $pdo->prepare('INSERT INTO sync_ops (op_id, user_id, response, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$opId, $uid, json_encode($res, JSON_UNESCAPED_UNICODE), now()]);
        $pdo->commit();
        return $res;
    } catch (SyncReject $ex) {
        $pdo->rollBack();
        $res = ['op_id' => $opId, 'status' => 'rejected', 'error' => $ex->getMessage()];
        $pdo->prepare('INSERT INTO sync_ops (op_id, user_id, response, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$opId, $uid, json_encode($res, JSON_UNESCAPED_UNICODE), now()]);
        return $res;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/** Limpia de vez en cuando el registro de operaciones ya procesadas y las tareas borradas antiguas. */
function sync_housekeeping(): void
{
    if (random_int(1, 200) !== 1) {
        return;
    }
    $limit = date('Y-m-d H:i:s', time() - 45 * 86400);
    db()->prepare('DELETE FROM sync_ops WHERE created_at < ?')->execute([$limit]);
    db()->prepare('DELETE FROM tasks WHERE deleted_at IS NOT NULL AND deleted_at < ? AND id NOT IN (SELECT task_id FROM (SELECT task_id FROM task_conflicts) c)')->execute([$limit]);
}

// ---------- Conflictos (los resuelve quien creó el proyecto) ----------

function conflicts_for_project(int $projectId): array
{
    $stmt = db()->prepare('SELECT c.*, t.title, t.notes, t.due_date, t.status, t.project_id AS t_project, t.deleted_at,
            u.display_name AS by_name, u.email AS by_email, tu.display_name AS current_by_name, t.updated_at AS current_at
        FROM task_conflicts c
        JOIN tasks t ON t.id = c.task_id
        JOIN users u ON u.id = c.user_id
        LEFT JOIN users tu ON tu.id = t.updated_by
        WHERE c.project_id = ? ORDER BY c.id');
    $stmt->execute([$projectId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $fields = json_decode((string) $r['fields'], true) ?: [];
        $current = [];
        foreach (array_keys($fields) as $k) {
            $current[$k] = $k === 'due_date' && $r['due_date'] ? substr((string) $r['due_date'], 0, 10) : ($k === 'project_id' ? (int) $r['t_project'] : $r[$k]);
        }
        $out[] = [
            'id'         => (int) $r['id'],
            'task_id'    => (int) $r['task_id'],
            'title'      => $r['title'],
            'kind'       => $r['kind'],
            'by'         => $r['by_name'] ?: ($r['by_email'] ?: 'Otra persona'),
            'changed_at' => $r['changed_at'],
            'fields'     => $fields,
            'current'    => $current,
            'current_by' => $r['current_by_name'],
            'current_at' => $r['current_at'],
            'gone'       => !empty($r['deleted_at']),
        ];
    }
    return $out;
}

function resolve_conflict(int $conflictId, string $choice, int $uid): void
{
    $stmt = db()->prepare('SELECT * FROM task_conflicts WHERE id = ?');
    $stmt->execute([$conflictId]);
    $c = $stmt->fetch();
    if (!$c) {
        throw new SyncReject('Este conflicto ya se resolvió.');
    }
    if (require_member((int) $c['project_id'], $uid) !== 'owner') {
        throw new SyncReject('Solo quien creó el proyecto puede decidir.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($choice === 'incoming') {
            $task = task_row_from_ref((int) $c['task_id']);
            if ($task && empty($task['deleted_at'])) {
                if ($c['kind'] === 'delete') {
                    soft_delete_task((int) $task['id'], (int) $c['user_id']);
                } else {
                    $fields = json_decode((string) $c['fields'], true) ?: [];
                    $fields = array_intersect_key($fields, array_flip(TASK_FIELDS));
                    if ($fields) {
                        write_task_fields((int) $task['id'], $fields, (int) $c['user_id'], $task);
                    }
                }
            }
        }
        $pdo->prepare('DELETE FROM task_conflicts WHERE id = ?')->execute([$conflictId]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}
