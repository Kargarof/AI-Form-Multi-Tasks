<?php
// Copia este archivo como config.php y rellena tus datos.
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

    // Iniciar sesión con Google (opcional). Si lo dejas vacío, el botón de Google no aparece
    // y la app funciona solo con cuentas de dispositivo y códigos de acceso.
    // Cómo conseguir estos datos: README.md > "Iniciar sesión con Google".
    'google' => [
        'client_id'     => '',
        'client_secret' => '',
        'redirect_uri'  => 'https://aiformconsulting.com/tareas/oauth.php',
    ],

    'timezone' => 'America/Denver',
    'app_name' => 'AI Form Multi Tasks',
];
