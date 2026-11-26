# AI Form Multi Tasks

Gestor de tareas de **AI Form Consulting**, dividido por proyectos. Cada proyecto se ve como un tablero con tres columnas: **Pendiente**, **En curso** y **Completado**.

Se publica en `https://aiformconsulting.com/tareas`.

## Qué hace

- Proyectos en pestañas en la parte de arriba, con el número de tareas abiertas.
- Tablero con tres columnas. Las tareas se arrastran entre columnas y se pueden reordenar dentro de cada una.
- Cada tarea tiene título, notas, fecha y estado, y se puede pasar a otro proyecto.
- Las tareas con fecha pasada que no están completadas se marcan en rojo.
- **Cada persona tiene su cuenta y solo ve sus proyectos.** No hace falta registrarse: al pulsar «Empezar» se crea una cuenta en ese dispositivo.
- **Iniciar sesión con Google (opcional):** sirve para no perder las tareas al cambiar de teléfono y para usar la misma cuenta en varios dispositivos.
- **Proyectos compartidos:** se invita a otras personas por su Gmail o con un enlace. Todos ven y editan las mismas tareas, y los cambios aparecen solos.
- **Código de acceso:** para entrar en la cuenta desde otro dispositivo sin usar Google.
- Funciona en el móvil: en pantallas pequeñas las columnas se apilan.
- Atajo de teclado: `N` crea una tarea nueva.
- **App instalable** en el móvil y el ordenador, con su propio icono. El botón «Instalar» aparece siempre que la app no está instalada y explica los pasos según el teléfono y el navegador.
- **Funciona sin conexión:** se pueden crear, editar, mover y borrar tareas y proyectos. Los cambios se guardan en el dispositivo y se suben solos al volver internet.
- **Conflictos resueltos:** si dos personas cambian lo mismo, decide quien creó el proyecto, o gana el cambio más reciente si así se configura.

## Requisitos

- PHP 8.1 o superior, con PDO MySQL y cURL (incluidos en los hostings de IONOS).
- Una base de datos MySQL o MariaDB.
- Apache con `.htaccess` activo (el de IONOS lo tiene).

Las tablas se crean solas la primera vez que se abre la app, así que no hay que importar ningún SQL.

## Instalación en aiformconsulting.com/tareas (IONOS)

1. **Base de datos.** En el panel de IONOS, ve a *Hosting > Bases de datos* y crea una base MySQL. Apunta el servidor, el nombre de la base, el usuario y la contraseña.
2. **Archivos.** Sube el contenido de este repositorio a una carpeta `tareas` en la raíz de la web, al lado de `index.html` y de la carpeta `img`. Tienes dos opciones:
   - **Automático desde GitHub** (recomendado): ver «Publicación automática» más abajo.
   - A mano por SFTP, por ejemplo con FileZilla, con los datos de *Hosting > SFTP & SSH*.
3. **Configuración.** En el servidor, copia `config.sample.php` como `config.php` y rellena los datos de la base de datos:
   ```php
   'dsn'  => 'mysql:host=dbXXXXXXXX.hosting-data.io;port=3306;dbname=dbsXXXXXXXX;charset=utf8mb4',
   'user' => 'dbuXXXXXXX',
   'pass' => 'tu-contraseña',
   ```
   `config.php` no se sube nunca a GitHub (está en `.gitignore`).
4. **Google (opcional).** Si quieres el botón «Continuar con Google», sigue los pasos de la sección siguiente y añade los datos en `config.php`.
5. **Listo.** Abre `https://aiformconsulting.com/tareas` y pulsa «Empezar».

El logo sale de `/img/logo.png`, el mismo que usa la web. Si en algún momento cambia de sitio, se muestra solo el texto «AI Form Multi Tasks».

## Publicación automática desde GitHub

Cada vez que se sube un cambio a la rama `main`, GitHub Actions (`.github/workflows/deploy.yml`):
1. Comprueba que el PHP no tiene errores.
2. Pone una versión nueva en el service worker, para que los móviles con la app instalada se actualicen solos.
3. Copia los archivos por SFTP a la carpeta de la app en IONOS y borra los que ya no existan en el repositorio.
4. Comprueba que `https://aiformconsulting.com/tareas` responde.

**Nunca se sube ni se borra** `config.php` ni la carpeta `data/` del servidor, así que la configuración y las claves se quedan solo allí.

**Configuración inicial** (una sola vez). En GitHub, ve a *Settings > Secrets and variables > Actions > New repository secret* y crea estos tres secretos con los datos de IONOS (*Hosting > SFTP & SSH*):

| Secreto | Qué poner |
|---|---|
| `SFTP_HOST` | El servidor, p. ej. `access-5012345678.webspace-host.com` |
| `SFTP_USER` | El usuario SFTP |
| `SFTP_PASSWORD` | La contraseña de ese usuario |

La carpeta de destino está fijada en el flujo: `AI_Form/tareas` (la que sirve aiformconsulting.com/tareas). Nunca se sube ni se borra `config.php` ni `data/`.

Mientras falten secretos, la publicación se salta con un aviso, sin errores. Para publicar sin subir cambios: pestaña *Actions > Publicar en aiformconsulting.com/tareas > Run workflow*.

## Iniciar sesión con Google

Se configura una sola vez, en la consola de Google Cloud. Es gratis.

1. Entra en [console.cloud.google.com](https://console.cloud.google.com) con tu cuenta de Google y crea un proyecto, por ejemplo «AI Form Multi Tasks».
2. Ve a **Google Auth Platform** (o *APIs y servicios > Pantalla de consentimiento de OAuth*) y configúrala:
   - Tipo de usuario: **Externo**.
   - Nombre de la app: *AI Form Multi Tasks*, con tu email de asistencia y, si quieres, el logo.
   - Dominio autorizado: `aiformconsulting.com`.
   - Permisos: solo los básicos (`openid`, `email` y `profile`). Con estos no hace falta que Google revise la app.
   - Al terminar, pulsa **Publicar app** (estado *En producción*). Si la dejas *En pruebas*, solo podrán entrar los Gmail que añadas como usuarios de prueba.
3. Ve a **Clientes > Crear cliente** y elige *Aplicación web*:
   - En *URI de redirección autorizados*, añade `https://aiformconsulting.com/tareas/oauth.php`.
4. Copia el **ID de cliente** y el **secreto de cliente** en `config.php`:
   ```php
   'google' => [
       'client_id'     => '1234567890-xxxx.apps.googleusercontent.com',
       'client_secret' => 'GOCSPX-xxxx',
       'redirect_uri'  => 'https://aiformconsulting.com/tareas/oauth.php',
   ],
   ```

Mientras estos datos estén vacíos, el botón de Google no aparece y la app funciona con cuentas de dispositivo y códigos de acceso.

## Cuentas y proyectos compartidos

**Cuentas.**
- «Empezar» crea una cuenta anónima guardada en ese dispositivo, en una cookie segura de larga duración.
- Si esa persona entra después con Google, sus proyectos pasan a su cuenta de Google y desde ese momento los ve en cualquier dispositivo en el que entre con ese Gmail.
- En iPhone conviene instalar la app primero y empezar desde el icono, porque Safari y la app instalada guardan los datos por separado.

**Compartir un proyecto** (botón «Compartir» del tablero):
- **Por Gmail:** si esa persona ya usa la app con Google, lo ve al momento. Si no, el proyecto le aparece en cuanto entre con ese Gmail.
- **Por enlace:** vale para cualquiera, aunque no use Google. El enlace se puede cambiar o desactivar.

**Permisos:**
- Solo quien crea el proyecto puede renombrarlo, eliminarlo, invitar o quitar a otras personas.
- Los demás pueden crear, editar, mover y completar tareas, y salir del proyecto cuando quieran.
- En los proyectos compartidos, cada tarjeta indica quién hizo el último cambio. El tablero se actualiza solo cada 20 segundos y al volver a la app.

**Sin conexión y conflictos.**
- La app guarda una copia de todos tus proyectos en el dispositivo y trabaja sobre ella. Cada cambio se apunta en una cola y se envía al servidor en cuanto hay internet. Si se corta a mitad, no se aplica dos veces.
- Cada cambio lleva el valor que tenía la tarea cuando se editó. Si otra persona cambió **otro** campo de la misma tarea (por ejemplo, una las notas y otra el estado), se juntan los dos cambios.
- Si dos personas cambian **el mismo** campo, lo decide la opción del proyecto (en *Editar proyecto*):
  - **Decido yo** (por defecto): se mantiene la versión actual y quien creó el proyecto ve «Por revisar» para elegir cuál se queda.
  - **Gana el más reciente:** se queda automáticamente el cambio hecho más tarde.
- Entre dispositivos de la misma persona gana siempre el cambio más reciente.
- Lo que necesita al servidor en el momento (compartir, invitar, eliminar un proyecto ya guardado, la cuenta) pide conexión.

**Preparado para crecer.** La base de datos ya tiene un campo `plan` por usuario y un sistema de versiones del esquema (`lib/db.php`). Así se pueden añadir más adelante el registro con email o los planes de pago sin perder datos.

## Instalarla como app

La web funciona como app instalable (PWA). Para que se pueda instalar tiene que servirse por **HTTPS**, como ya hace aiformconsulting.com.

| Dispositivo | Cómo se instala |
|---|---|
| Android (Chrome) | Botón **Instalar** de la app, o menú ⋮ > *Instalar aplicación* |
| iPhone / iPad (Safari) | Botón **Instalar**, que explica los pasos: *Compartir* > *Añadir a pantalla de inicio* |
| Ordenador (Chrome / Edge) | Botón **Instalar**, o el icono de instalar en la barra de direcciones |

Al mantener pulsado el icono de la app aparece el acceso directo **Nueva tarea**.

**Sin conexión:** la app abre igualmente y muestra la última copia guardada de los proyectos y tareas, en solo lectura. Al cerrar sesión, esa copia se borra del dispositivo.

**Actualizaciones:** la publicación automática cambia la versión del service worker en cada cambio, así que los dispositivos con la app instalada descargan la nueva versión la próxima vez que la abren con conexión.

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
| `api.php` | API JSON que usa el tablero: sincronización, proyectos, conflictos, cuenta y compartir |
| `lib/sync.php` | Sincronización sin conexión: cola de cambios, fusión campo a campo y conflictos |
| `.github/workflows/deploy.yml` | Publicación automática en IONOS |
| `welcome.php` | Pantalla de bienvenida: Empezar, Continuar con Google o código de acceso |
| `oauth.php`, `lib/google.php` | Inicio de sesión con Google (OAuth 2.0 con PKCE) |
| `lib/` | Base de datos y versiones del esquema, cuentas y dispositivos, sesión |
| `assets/` | Estilos (`app.css`), lógica del tablero (`app.js`) e iconos |
| `manifest.webmanifest`, `sw.js`, `offline.html` | App instalable: datos de la app, funcionamiento sin conexión y pantalla sin conexión |
| `assets/pwa.js`, `assets/icons/` | Botón «Instalar» e iconos de la app |
| `privacidad.html`, `condiciones.html` | Política de privacidad y condiciones del servicio (Google las enlaza en la pantalla de inicio de sesión) |
| `config.sample.php` | Plantilla de configuración |

## Seguridad

- Cada dispositivo tiene su propia llave secreta, en una cookie `HttpOnly`. En la base de datos solo se guarda su huella (SHA-256), nunca la llave.
- Los códigos de acceso también se guardan solo como huella y se muestran una sola vez.
- El servidor comprueba en cada petición que la persona es miembro del proyecto. Nadie puede leer ni tocar proyectos ajenos.
- El login con Google usa `state`, `nonce` y PKCE, y comprueba el emisor, el destinatario, la caducidad y que el email esté verificado.
- Todas las peticiones que cambian datos llevan token CSRF.
- Todas las consultas a la base de datos son preparadas.
- La cookie de sesión es `HttpOnly`, `SameSite=Lax` y `Secure` cuando la web va por HTTPS.
- Después de cada código de acceso incorrecto hay una pausa que va aumentando.
- El `.htaccess` bloquea el acceso directo a `config.php`, `lib/` y `data/`.
- Las páginas llevan `noindex`, para que la app no aparezca en Google.

## Colores

Los de AI Form Consulting: azul marino `#111827` / `#1B222E`, dorado `#C7AA89` y títulos en Montserrat. Los estados usan gris para Pendiente, dorado para En curso y verde salvia para Completado.
