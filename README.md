# AI Tarea

Gestor de tareas de **AI Form Consulting**, dividido por proyectos. Cada proyecto se ve como un tablero con tres columnas: **Pendiente**, **En curso** y **Completado**.

Se publica en `https://aiformconsulting.com/tareas`.

## Qué hace

- Proyectos en pestañas en la parte de arriba, con el número de tareas abiertas.
- Tablero con tres columnas. Las tareas se arrastran entre columnas y se pueden reordenar dentro de cada una.
- Cada tarea tiene título, notas, fecha y estado, y se puede pasar a otro proyecto.
- Las tareas con fecha pasada que no están completadas se marcan en rojo.
- Acceso con usuario y contraseña. La pantalla de alta se desactiva en cuanto existe un usuario.
- Funciona en el móvil: en pantallas pequeñas las columnas se apilan.
- Atajo de teclado: `N` crea una tarea nueva.

## Requisitos

- PHP 8.1 o superior, con PDO MySQL (incluido en los hostings de IONOS).
- Una base de datos MySQL o MariaDB.
- Apache con `.htaccess` activo (el de IONOS lo tiene).

Las tablas se crean solas la primera vez que se abre la app, así que no hay que importar ningún SQL.

## Instalación en aiformconsulting.com/tareas (IONOS)

1. **Base de datos.** En el panel de IONOS, ve a *Hosting > Bases de datos* y crea una base MySQL. Apunta el servidor, el nombre de la base, el usuario y la contraseña.
2. **Archivos.** Sube el contenido de este repositorio a una carpeta `tareas` en la raíz de la web, al lado de `index.html` y de la carpeta `img`. Tienes dos opciones:
   - Por SFTP, por ejemplo con FileZilla, con los datos de acceso de *Hosting > SFTP & SSH*.
   - Con **IONOS Deploy Now**, conectando este repositorio de GitHub y poniendo `tareas` como carpeta de destino.
3. **Configuración.** En el servidor, copia `config.sample.php` como `config.php` y rellena los datos de la base de datos:
   ```php
   'dsn'  => 'mysql:host=dbXXXXXXXX.hosting-data.io;port=3306;dbname=dbsXXXXXXXX;charset=utf8mb4',
   'user' => 'dbuXXXXXXX',
   'pass' => 'tu-contraseña',
   ```
   `config.php` no se sube nunca a GitHub (está en `.gitignore`).
4. **Primer acceso.** Abre `https://aiformconsulting.com/tareas`. La primera vez te pedirá crear el usuario y la contraseña. Después de eso, solo verás la pantalla de login.

El logo sale de `/img/logo.png`, el mismo que usa la web. Si en algún momento cambia de sitio, se muestra solo el texto «AI Tarea».

## Probarlo en tu ordenador

Con MAMP, o con PHP instalado:

```bash
cp config.sample.php config.php   # y activa las líneas de SQLite
php -S localhost:8000
```

Después abre `http://localhost:8000`.

## Estructura

| Archivo | Para qué sirve |
|---|---|
| `index.php` | Pantalla principal: proyectos y tablero |
| `api.php` | API JSON que usa el tablero (proyectos y tareas) |
| `login.php`, `logout.php`, `setup.php` | Acceso y alta del primer usuario |
| `lib/` | Conexión a la base de datos, creación de tablas y sesión |
| `assets/` | Estilos (`app.css`), lógica del tablero (`app.js`) e iconos |
| `config.sample.php` | Plantilla de configuración |

## Seguridad

- Las contraseñas se guardan cifradas con `password_hash`.
- Todas las peticiones que cambian datos llevan token CSRF.
- Todas las consultas a la base de datos son preparadas.
- La cookie de sesión es `HttpOnly`, `SameSite=Lax` y `Secure` cuando la web va por HTTPS.
- Después de cada intento de login fallido hay una pausa que va aumentando.
- El `.htaccess` bloquea el acceso directo a `config.php`, `lib/` y `data/`.
- Las páginas llevan `noindex`, para que la app no aparezca en Google.

## Colores

Los de AI Form Consulting: azul marino `#111827` / `#1B222E`, dorado `#C7AA89` y títulos en Montserrat. Los estados usan gris para Pendiente, dorado para En curso y verde salvia para Completado.
