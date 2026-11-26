<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = current_user();
if (!$user) {
    $join = isset($_GET['unirse']) && is_string($_GET['unirse']) ? '?unirse=' . rawurlencode($_GET['unirse']) : '';
    redirect('welcome.php' . $join);
}
$appName = app_name();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="app-flash" content="<?= e((string) $flash) ?>">
<meta name="google-enabled" content="<?= google_enabled() ? '1' : '0' ?>">
<title><?= e($appName) ?> · AI Form Consulting</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<?php include __DIR__ . '/lib/pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
  <a class="brand" href="./" aria-label="<?= e($appName) ?>">
    <img src="/img/logo.png" alt="AI Form Consulting" onerror="this.remove()">
    <span><?= e($appName) ?></span>
  </a>
  <nav class="chips" id="projectChips" aria-label="Proyectos"></nav>
  <button class="chip chip-add" id="addProjectBtn" type="button" title="Nuevo proyecto">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    <span>Proyecto</span>
  </button>
  <button class="chip chip-install js-install" id="installBtn" type="button" hidden title="Instalar la app en este dispositivo">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>
    <span>Instalar</span>
  </button>
  <button class="account-btn" id="accountBtn" type="button" aria-label="Tu cuenta" title="Tu cuenta">
    <?php if (!empty($user['avatar_url'])): ?>
      <img src="<?= e($user['avatar_url']) ?>" alt="" referrerpolicy="no-referrer" width="32" height="32">
    <?php else: ?>
      <span class="avatar-initial"><?= e(mb_strtoupper(mb_substr((string) ($user['display_name'] ?: '?'), 0, 1))) ?></span>
    <?php endif; ?>
  </button>
</header>

<div class="offline-bar" id="offlineBar" role="status" aria-live="polite" hidden></div>

<main class="panel" id="panel">
  <div class="panel-head">
    <div class="icon-box"><?php include __DIR__ . '/assets/board-icon.svg'; ?></div>
    <div class="panel-title">
      <div class="eyebrow" id="projectEyebrow">Proyecto</div>
      <h1 id="projectTitle">Cargando…</h1>
    </div>
    <div class="head-actions">
      <button class="pill pill-btn pill-warn" id="conflictBtn" type="button" hidden>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17.5v.5"/></svg>
        <span>Por revisar</span>
      </button>
      <span class="pill" id="progressPill" hidden></span>
      <button class="pill pill-btn" id="shareBtn" type="button" hidden>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c.8-3 3-4.6 5.5-4.6s4.7 1.6 5.5 4.6"/><path d="M16 6.5a3 3 0 0 1 0 5.6M18 14.6c1.3.6 2.2 2 2.5 4.4"/></svg>
        <span id="shareLabel">Compartir</span>
      </button>
      <button class="pill pill-btn" id="editProjectBtn" type="button" hidden>Editar</button>
      <button class="btn btn-primary" id="newTaskBtn" type="button" hidden>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        Nueva tarea
      </button>
    </div>
  </div>

  <div class="board" id="board" hidden>
    <section class="col" data-status="pendiente">
      <header class="col-head"><h2>Pendiente</h2><span class="count">0</span></header>
      <div class="cards" data-status="pendiente"></div>
      <button class="add-in-col" type="button" data-status="pendiente">+ Añadir tarea</button>
    </section>
    <section class="col" data-status="en_curso">
      <header class="col-head"><h2>En curso</h2><span class="count">0</span></header>
      <div class="cards" data-status="en_curso"></div>
      <button class="add-in-col" type="button" data-status="en_curso">+ Añadir tarea</button>
    </section>
    <section class="col" data-status="completado">
      <header class="col-head"><h2>Completado</h2><span class="count">0</span></header>
      <div class="cards" data-status="completado"></div>
      <button class="add-in-col" type="button" data-status="completado">+ Añadir tarea</button>
    </section>
  </div>

  <div class="empty" id="emptyState" hidden>
    <p>Todavía no tienes proyectos.</p>
    <button class="btn btn-primary" type="button" id="emptyCreateBtn">Crear el primer proyecto</button>
  </div>
</main>

<dialog id="taskDialog" class="dialog">
  <form method="dialog" id="taskForm">
    <h3 id="taskDialogTitle">Nueva tarea</h3>
    <label>Tarea<input name="title" maxlength="255" required autofocus></label>
    <label>Notas<textarea name="notes" rows="4"></textarea></label>
    <div class="row">
      <label>Fecha<input type="date" name="due_date"></label>
      <label>Proyecto<select name="project_id" id="taskProjectSelect"></select></label>
    </div>
    <fieldset class="segmented">
      <legend>Estado</legend>
      <label><input type="radio" name="status" value="pendiente" checked><span>Pendiente</span></label>
      <label><input type="radio" name="status" value="en_curso"><span>En curso</span></label>
      <label><input type="radio" name="status" value="completado"><span>Completado</span></label>
    </fieldset>
    <div class="dialog-actions">
      <button class="btn btn-danger" type="button" id="deleteTaskBtn" hidden>Eliminar</button>
      <span class="spacer"></span>
      <button class="btn" type="button" data-close>Cancelar</button>
      <button class="btn btn-primary" type="submit">Guardar</button>
    </div>
  </form>
</dialog>

<dialog id="projectDialog" class="dialog">
  <form method="dialog" id="projectForm">
    <h3 id="projectDialogTitle">Nuevo proyecto</h3>
    <label>Nombre del proyecto<input name="name" maxlength="120" required autofocus></label>
    <fieldset class="radio-list" id="conflictSettings" hidden>
      <legend>Si dos personas cambian lo mismo sin conexión</legend>
      <label><input type="radio" name="conflict_mode" value="owner"><span><b>Decido yo</b> · los cambios que choquen quedan pendientes y eliges qué versión se queda</span></label>
      <label><input type="radio" name="conflict_mode" value="latest"><span><b>Gana el más reciente</b> · se queda automáticamente el último cambio</span></label>
    </fieldset>
    <div class="dialog-actions">
      <button class="btn btn-danger" type="button" id="deleteProjectBtn" hidden>Eliminar proyecto</button>
      <span class="spacer"></span>
      <button class="btn" type="button" data-close>Cancelar</button>
      <button class="btn btn-primary" type="submit">Guardar</button>
    </div>
  </form>
</dialog>

<dialog id="shareDialog" class="dialog dialog-wide">
  <div class="dialog-body">
    <h3>Compartir «<span id="shareProjectName"></span>»</h3>
    <p class="dialog-intro" id="shareIntro">Las personas que añadas verán este proyecto y podrán crear, mover y completar sus tareas.</p>

    <section class="share-block" id="shareOwnerTools">
      <form id="inviteEmailForm" class="inline-form">
        <label>Invitar por Gmail
          <span class="inline-row">
            <input type="email" name="email" placeholder="nombre@gmail.com" autocomplete="email" required>
            <button class="btn btn-primary" type="submit">Invitar</button>
          </span>
        </label>
      </form>
      <p class="hint" id="emailHint">Le aparecerá el proyecto en cuanto entre en la app con ese Gmail.</p>

      <div class="share-link">
        <div class="share-link-head">
          <strong>Enlace de invitación</strong>
          <span class="hint">Para cualquiera, aunque no use Google.</span>
        </div>
        <div class="inline-row" id="linkRow" hidden>
          <input id="inviteLinkInput" readonly>
          <button class="btn" type="button" id="copyLinkBtn">Copiar</button>
        </div>
        <div class="link-actions">
          <button class="btn" type="button" id="createLinkBtn">Crear enlace</button>
          <button class="btn btn-ghost" type="button" id="resetLinkBtn" hidden>Cambiar enlace</button>
          <button class="btn btn-ghost" type="button" id="disableLinkBtn" hidden>Desactivar</button>
        </div>
      </div>
    </section>

    <section class="share-block">
      <h4>Personas en el proyecto</h4>
      <ul class="people" id="memberList"></ul>
      <ul class="people" id="pendingList"></ul>
    </section>

    <div class="dialog-actions">
      <button class="btn btn-danger" type="button" id="leaveProjectBtn" hidden>Salir del proyecto</button>
      <span class="spacer"></span>
      <button class="btn btn-primary" type="button" data-close>Listo</button>
    </div>
  </div>
</dialog>

<dialog id="conflictDialog" class="dialog dialog-wide">
  <div class="dialog-body">
    <h3>Cambios por revisar</h3>
    <p class="dialog-intro">Dos personas cambiaron lo mismo mientras alguna estaba sin conexión. Como creaste el proyecto, eliges qué versión se queda.</p>
    <div id="conflictList" class="conflict-list"></div>
    <div class="dialog-actions"><span class="spacer"></span><button class="btn btn-primary" type="button" data-close>Listo</button></div>
  </div>
</dialog>

<dialog id="joinDialog" class="dialog">
  <div class="dialog-body">
    <h3>Unirte a un proyecto</h3>
    <p class="dialog-intro" id="joinText"></p>
    <div class="dialog-actions">
      <span class="spacer"></span>
      <button class="btn" type="button" data-close>Ahora no</button>
      <button class="btn btn-primary" type="button" id="joinConfirmBtn">Unirme</button>
    </div>
  </div>
</dialog>

<dialog id="accountDialog" class="dialog">
  <div class="dialog-body">
    <h3>Tu cuenta</h3>
    <form id="nameForm" class="inline-form">
      <label>Tu nombre <span class="optional">(lo verán las personas con las que compartas)</span>
        <span class="inline-row">
          <input name="name" maxlength="80" autocomplete="given-name">
          <button class="btn" type="submit">Guardar</button>
        </span>
      </label>
    </form>

    <section class="share-block" id="googleBlock">
      <div id="googleLinked" hidden>
        <p class="account-line"><span class="dot-ok"></span> Conectada con Google: <b id="googleEmail"></b></p>
        <p class="hint">Entra con este Gmail en cualquier dispositivo y verás tus mismas tareas.</p>
      </div>
      <div id="googleNotLinked" hidden>
        <p class="hint">Ahora tus tareas solo están en este dispositivo. Conecta tu cuenta de Google para no perderlas y poder usarlas en otros dispositivos.</p>
        <a class="btn btn-google" id="googleConnectBtn" href="#">
          <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
          Conectar con Google
        </a>
      </div>
    </section>

    <section class="share-block">
      <h4>Código de acceso</h4>
      <p class="hint" id="codeHint">Sirve para entrar en tu cuenta desde otro dispositivo sin Google. Guárdalo en un sitio seguro: quien lo tenga puede ver tus tareas.</p>
      <div class="code-box" id="codeBox" hidden>
        <code id="codeValue"></code>
        <button class="btn" type="button" id="copyCodeBtn">Copiar</button>
      </div>
      <p class="hint warn" id="codeShownOnce" hidden>Apúntalo ahora: por seguridad no se vuelve a mostrar.</p>
      <button class="btn" type="button" id="newCodeBtn">Crear código de acceso</button>
    </section>

    <div class="dialog-actions">
      <button class="btn btn-danger" type="button" id="signOutBtn">Cerrar sesión en este dispositivo</button>
      <span class="spacer"></span>
      <button class="btn btn-primary" type="button" data-close>Listo</button>
    </div>
  </div>
</dialog>

<?php include __DIR__ . '/lib/install_ui.php'; ?>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script src="assets/app.js" defer></script>
</body>
</html>
