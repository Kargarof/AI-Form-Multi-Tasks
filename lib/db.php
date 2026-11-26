<?php
declare(strict_types=1);

const TASK_STATUSES = ['pendiente', 'en_curso', 'completado'];
const SCHEMA_VERSION = 2;
const CONFLICT_MODES = ['owner', 'latest'];

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    global $config;
    $pdo = new PDO(
        trim((string) $config['db']['dsn']),
        isset($config['db']['user']) ? trim((string) $config['db']['user']) : null,
        $config['db']['pass'] ?? null,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    if (is_sqlite()) {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    if (($_SESSION['schema_version'] ?? 0) < SCHEMA_VERSION) {
        migrate($pdo);
        $_SESSION['schema_version'] = SCHEMA_VERSION;
    }
    return $pdo;
}

function is_sqlite(?PDO $pdo = null): bool
{
    static $driver = null;
    if ($pdo) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
    return ($driver ?? db()->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite';
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Crea y actualiza las tablas. Cada versión nueva del esquema se añade como un paso
 * más en $steps, para poder ampliar la app (registro, planes de pago…) sin perder datos.
 */
function migrate(PDO $pdo): void
{
    $sqlite = is_sqlite($pdo);
    $pk     = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $fk     = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $opts   = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS app_meta (k VARCHAR(50) PRIMARY KEY, v VARCHAR(255) NOT NULL)$opts");

    // Evita que dos visitas a la vez actualicen el esquema al mismo tiempo.
    if (!$sqlite) {
        $pdo->query("SELECT GET_LOCK('aiform_mt_migrate', 30)")->fetchColumn();
    }
    try {
        migrate_steps($pdo, $sqlite, $pk, $fk, $opts);
    } finally {
        if (!$sqlite) {
            $pdo->query("SELECT RELEASE_LOCK('aiform_mt_migrate')")->fetchColumn();
        }
    }
}

function migrate_steps(PDO $pdo, bool $sqlite, string $pk, string $fk, string $opts): void
{
    $current = (int) ($pdo->query("SELECT v FROM app_meta WHERE k = 'schema_version'")->fetchColumn() ?: 0);

    $steps = [
        1 => [
            "CREATE TABLE IF NOT EXISTS users (
                id $pk,
                display_name VARCHAR(80) NULL,
                google_sub VARCHAR(64) NULL UNIQUE,
                email VARCHAR(190) NULL,
                avatar_url VARCHAR(500) NULL,
                recovery_hash CHAR(64) NULL UNIQUE,
                plan VARCHAR(20) NOT NULL DEFAULT 'free',
                created_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL
            )$opts",
            'CREATE INDEX idx_users_email ON users (email)',
            "CREATE TABLE IF NOT EXISTS devices (
                id $pk,
                user_id $fk NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )$opts",
            "CREATE TABLE IF NOT EXISTS projects (
                id $pk,
                owner_id $fk NOT NULL,
                name VARCHAR(120) NOT NULL,
                created_at DATETIME NOT NULL,
                FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
            )$opts",
            "CREATE TABLE IF NOT EXISTS project_members (
                project_id $fk NOT NULL,
                user_id $fk NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'editor',
                position INT NOT NULL DEFAULT 0,
                joined_at DATETIME NOT NULL,
                PRIMARY KEY (project_id, user_id),
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )$opts",
            'CREATE INDEX idx_members_user ON project_members (user_id)',
            "CREATE TABLE IF NOT EXISTS project_invites (
                id $pk,
                project_id $fk NOT NULL,
                kind VARCHAR(10) NOT NULL,
                code VARCHAR(32) NULL UNIQUE,
                email VARCHAR(190) NULL,
                created_by $fk NOT NULL,
                created_at DATETIME NOT NULL,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            )$opts",
            'CREATE INDEX idx_invites_email ON project_invites (email)',
            "CREATE TABLE IF NOT EXISTS tasks (
                id $pk,
                project_id $fk NOT NULL,
                title VARCHAR(255) NOT NULL,
                notes TEXT NULL,
                due_date DATE NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                position INT NOT NULL DEFAULT 0,
                created_by $fk NULL,
                updated_by $fk NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            )$opts",
            'CREATE INDEX idx_tasks_board ON tasks (project_id, status, position)',
        ],
        // v2 · trabajo sin conexión y conflictos entre varias personas
        2 => [
            'ALTER TABLE tasks ADD COLUMN version INT NOT NULL DEFAULT 1',
            'ALTER TABLE tasks ADD COLUMN client_id VARCHAR(40) NULL',
            'ALTER TABLE tasks ADD COLUMN deleted_at DATETIME NULL',
            'CREATE UNIQUE INDEX idx_tasks_client ON tasks (client_id)',
            'ALTER TABLE projects ADD COLUMN client_id VARCHAR(40) NULL',
            'CREATE UNIQUE INDEX idx_projects_client ON projects (client_id)',
            "ALTER TABLE projects ADD COLUMN conflict_mode VARCHAR(10) NOT NULL DEFAULT 'owner'",
            "CREATE TABLE IF NOT EXISTS task_conflicts (
                id $pk,
                project_id $fk NOT NULL,
                task_id $fk NOT NULL,
                user_id $fk NOT NULL,
                kind VARCHAR(10) NOT NULL,
                fields TEXT NOT NULL,
                base TEXT NOT NULL,
                changed_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
            )$opts",
            'CREATE INDEX idx_conflicts_project ON task_conflicts (project_id)',
            "CREATE TABLE IF NOT EXISTS sync_ops (
                op_id VARCHAR(40) NOT NULL PRIMARY KEY,
                user_id $fk NOT NULL,
                response TEXT NOT NULL,
                created_at DATETIME NOT NULL
            )$opts",
        ],
    ];

    foreach ($steps as $version => $statements) {
        if ($version <= $current) {
            continue;
        }
        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $ex) {
                // Si un paso ya se hizo en un intento anterior (columna, índice o tabla ya existentes), se sigue.
                $code = (int) ($ex->errorInfo[1] ?? 0);
                $already = in_array($code, [1050, 1060, 1061], true)
                    || preg_match('/duplicate column|already exists/i', $ex->getMessage());
                if (!$already) {
                    throw $ex;
                }
            }
        }
        $upsert = $sqlite
            ? "INSERT INTO app_meta (k, v) VALUES ('schema_version', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v"
            : "INSERT INTO app_meta (k, v) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)";
        $pdo->prepare($upsert)->execute([(string) $version]);
    }
}
