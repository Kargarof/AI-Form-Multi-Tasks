<?php
// Copia este archivo como config.php y rellena los datos de tu base de datos.
// config.php no se sube a GitHub (está en .gitignore).

return [
    'db' => [
        // MySQL en IONOS: los datos están en el panel de IONOS > Hosting > Bases de datos.
        'dsn'  => 'mysql:host=dbXXXXXXXX.hosting-data.io;port=3306;dbname=dbsXXXXXXXX;charset=utf8mb4',
        'user' => 'dbuXXXXXXX',
        'pass' => 'CAMBIA_ESTA_CONTRASEÑA',

        // Para probar en tu ordenador sin MySQL puedes usar SQLite:
        // 'dsn'  => 'sqlite:' . __DIR__ . '/data/tareas.sqlite',
        // 'user' => null,
        // 'pass' => null,
    ],
    'timezone' => 'America/Denver',
    'app_name' => 'AI Tarea',
];
