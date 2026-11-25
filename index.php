<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();
$user = current_user();
$appName = $config['app_name'] ?? 'AI Tarea';
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#111827">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($appName) ?> · AI Form Consulting</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
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
  <form method="post" action="logout.php" class="logout">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <button class="icon-btn" type="submit" title="Cerrar sesión (<?= e($user['username']) ?>)" aria-label="Cerrar sesión">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
    </button>
  </form>
</header>

<main class="panel" id="panel">
  <div class="panel-head">
    <div class="icon-box"><?php include __DIR__ . '/assets/board-icon.svg'; ?></div>
    <div class="panel-title">
      <div class="eyebrow">Proyecto</div>
      <h1 id="projectTitle">Cargando…</h1>
    </div>
    <div class="head-actions">
      <span class="pill" id="progressPill" hidden></span>
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
    <div class="dialog-actions">
      <button class="btn btn-danger" type="button" id="deleteProjectBtn" hidden>Eliminar proyecto</button>
      <span class="spacer"></span>
      <button class="btn" type="button" data-close>Cancelar</button>
      <button class="btn btn-primary" type="submit">Guardar</button>
    </div>
  </form>
</dialog>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script src="assets/app.js" defer></script>
</body>
</html>
