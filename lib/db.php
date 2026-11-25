<?php
declare(strict_types=1);

const TASK_STATUSES = ['pendiente', 'en_curso', 'completado'];

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    global $config;
    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'] ?? null,
        $config['db']['pass'] ?? null,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    if (empty($_SESSION['schema_ok'])) {
        install_schema($pdo);
        $_SESSION['schema_ok'] = true;
    }
    return $pdo;
}

/** Crea las tablas la primera vez que se usa la app. */
function install_schema(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL
        ) $opts");
        $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            position INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        ) $opts");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            notes TEXT NULL,
            due_date DATE NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            position INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_tasks_board (project_id, status, position),
            CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        ) $opts");
        return;
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL
    )');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        title TEXT NOT NULL,
        notes TEXT NULL,
        due_date TEXT NULL,
        status TEXT NOT NULL DEFAULT 'pendiente',
        position INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_board ON tasks (project_id, status, position)');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}
