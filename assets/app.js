(() => {
  'use strict';

  const STATUSES = ['pendiente', 'en_curso', 'completado'];
  const STATUS_LABEL = { pendiente: 'Pendiente', en_curso: 'En curso', completado: 'Completado' };
  const csrf = document.querySelector('meta[name="csrf-token"]').content;

  const state = { projects: [], projectId: null, tasks: [], editingTask: null, editingProject: null };

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

  const els = {
    chips: $('#projectChips'),
    title: $('#projectTitle'),
    progress: $('#progressPill'),
    editProject: $('#editProjectBtn'),
    newTask: $('#newTaskBtn'),
    board: $('#board'),
    empty: $('#emptyState'),
    taskDialog: $('#taskDialog'),
    taskForm: $('#taskForm'),
    taskDialogTitle: $('#taskDialogTitle'),
    taskProjectSelect: $('#taskProjectSelect'),
    deleteTask: $('#deleteTaskBtn'),
    projectDialog: $('#projectDialog'),
    projectForm: $('#projectForm'),
    projectDialogTitle: $('#projectDialogTitle'),
    deleteProject: $('#deleteProjectBtn'),
    toast: $('#toast'),
  };

  // ---------- Utilidades ----------
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch { /* sin almacenamiento */ } },
  };

  let toastTimer;
  function toast(msg, isError = false) {
    els.toast.textContent = msg;
    els.toast.classList.toggle('error', isError);
    els.toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => els.toast.classList.remove('show'), 2600);
  }

  async function api(action, { params, body } = {}) {
    const qs = new URLSearchParams({ action, ...(params || {}) });
    const opts = body
      ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) }
      : { method: 'GET' };
    let res;
    try {
      res = await fetch(`api.php?${qs}`, { credentials: 'same-origin', ...opts });
    } catch {
      throw new Error('Sin conexión. Revisa tu internet.');
    }
    if (res.status === 401) { location.href = 'login.php'; throw new Error('Sesión caducada'); }
    if (!body) {
      const offline = res.headers.get('X-Offline-Copy') === '1';
      document.body.classList.toggle('offline-copy', offline);
      window.dispatchEvent(new Event('aiform:offline-copy'));
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Algo ha fallado.');
    return data;
  }

  const todayISO = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  };

  function formatDate(iso) {
    const [y, m, d] = iso.split('-').map(Number);
    const date = new Date(y, m - 1, d);
    const sameYear = y === new Date().getFullYear();
    return date.toLocaleDateString('es-ES', { day: 'numeric', month: 'short', ...(sameYear ? {} : { year: 'numeric' }) });
  }

  function el(tag, props = {}, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(props)) {
      if (k === 'class') node.className = v;
      else if (k === 'dataset') Object.assign(node.dataset, v);
      else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
      else if (v !== false && v != null) node.setAttribute(k, v === true ? '' : v);
    }
    for (const c of children.flat()) if (c != null) node.append(c.nodeType ? c : document.createTextNode(c));
    return node;
  }

  const svg = (markup) => { const t = document.createElement('template'); t.innerHTML = markup.trim(); return t.content.firstChild; };
  const ICON_BOARD = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="5" height="16" rx="1.5"/><rect x="9.5" y="4" width="5" height="10" rx="1.5"/><rect x="16" y="4" width="5" height="13" rx="1.5"/></svg>';
  const ICON_CHECK = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

  // ---------- Proyectos ----------
  async function loadProjects() {
    const { projects } = await api('projects');
    state.projects = projects;
    if (!projects.some((p) => p.id === state.projectId)) {
      const saved = Number(store.get('aiform-mt.project'));
      state.projectId = projects.some((p) => p.id === saved) ? saved : (projects[0]?.id ?? null);
    }
    renderChips();
  }

  function currentProject() { return state.projects.find((p) => p.id === state.projectId) || null; }

  function renderChips() {
    els.chips.replaceChildren(...state.projects.map((p) => {
      const pending = p.counts.pendiente + p.counts.en_curso;
      return el('button', {
        type: 'button',
        class: 'chip' + (p.id === state.projectId ? ' active' : ''),
        'aria-pressed': p.id === state.projectId ? 'true' : 'false',
        onclick: () => selectProject(p.id),
      }, svg(ICON_BOARD), el('span', {}, p.name), pending ? el('span', { class: 'chip-count', title: 'Tareas abiertas' }, String(pending)) : null);
    }));
    const active = $('.chip.active', els.chips);
    if (active) active.scrollIntoView({ block: 'nearest', inline: 'nearest' });

    els.taskProjectSelect.replaceChildren(...state.projects.map((p) => el('option', { value: p.id }, p.name)));

    const has = state.projects.length > 0;
    els.empty.hidden = has;
    els.board.hidden = !has;
    els.newTask.hidden = !has;
    els.editProject.hidden = !has;
    els.progress.hidden = !has;
    if (!has) els.title.textContent = 'Gestor de tareas';
  }

  async function selectProject(id) {
    state.projectId = id;
    store.set('aiform-mt.project', String(id));
    renderChips();
    await loadTasks();
  }

  // ---------- Tareas ----------
  async function loadTasks() {
    const p = currentProject();
    if (!p) { state.tasks = []; return; }
    els.title.textContent = p.name;
    document.title = `${p.name} · AI Form Multi Tasks`;
    const { tasks } = await api('tasks', { params: { project_id: p.id } });
    state.tasks = tasks;
    renderBoard();
  }

  function renderBoard() {
    const today = todayISO();
    for (const status of STATUSES) {
      const list = state.tasks.filter((t) => t.status === status).sort((a, b) => a.position - b.position || a.id - b.id);
      const col = $(`.col[data-status="${status}"]`);
      $('.count', col).textContent = String(list.length);
      const zone = $('.cards', col);
      zone.replaceChildren(...(list.length ? list.map((t) => renderCard(t, today)) : [el('div', { class: 'col-empty' }, 'Sin tareas')]));
    }
    const done = state.tasks.filter((t) => t.status === 'completado').length;
    els.progress.textContent = `${done}/${state.tasks.length} completadas`;
    // Actualiza los contadores del chip sin recargar todo
    const p = currentProject();
    if (p) {
      for (const s of STATUSES) p.counts[s] = state.tasks.filter((t) => t.status === s).length;
      const chipCount = $('.chip.active .chip-count', els.chips);
      const open = p.counts.pendiente + p.counts.en_curso;
      if (chipCount) { chipCount.textContent = String(open); chipCount.hidden = !open; }
    }
  }

  function renderCard(t, today) {
    const meta = [];
    if (t.due_date) {
      const overdue = t.status !== 'completado' && t.due_date < today;
      meta.push(el('span', { class: 'tag' + (overdue ? ' overdue' : ''), title: overdue ? 'Fecha pasada' : 'Fecha' }, formatDate(t.due_date)));
    }
    if (t.status === 'completado') meta.push(el('span', { class: 'tag status' }, 'Completado'));

    const card = el('article', {
      class: 'card', tabindex: '0', draggable: 'true', role: 'button',
      'aria-label': `${t.title}. ${STATUS_LABEL[t.status]}. Pulsa para editar`,
      dataset: { id: String(t.id), status: t.status },
      onclick: () => openTask(t),
      onkeydown: (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openTask(t); } },
    },
    el('p', { class: 'card-title' }, t.title),
    t.notes ? el('p', { class: 'card-notes' }, t.notes) : null,
    meta.length ? el('div', { class: 'card-meta' }, meta) : null,
    t.status === 'completado' ? el('span', { class: 'check', 'aria-hidden': 'true' }, svg(ICON_CHECK)) : null);

    card.addEventListener('dragstart', (e) => {
      card.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', String(t.id));
    });
    card.addEventListener('dragend', () => {
      card.classList.remove('dragging');
      $$('.cards').forEach((z) => z.classList.remove('drop-target'));
    });
    return card;
  }

  // ---------- Arrastrar y soltar ----------
  function cardAfter(zone, y) {
    const cards = $$('.card:not(.dragging)', zone);
    let closest = { offset: -Infinity, el: null };
    for (const c of cards) {
      const box = c.getBoundingClientRect();
      const offset = y - box.top - box.height / 2;
      if (offset < 0 && offset > closest.offset) closest = { offset, el: c };
    }
    return closest.el;
  }

  $$('.cards').forEach((zone) => {
    zone.addEventListener('dragover', (e) => {
      const dragging = $('.card.dragging');
      if (!dragging) return;
      e.preventDefault();
      zone.classList.add('drop-target');
      $('.col-empty', zone)?.remove();
      const after = cardAfter(zone, e.clientY);
      if (after) zone.insertBefore(dragging, after); else zone.append(dragging);
    });
    zone.addEventListener('dragleave', (e) => {
      if (!zone.contains(e.relatedTarget)) zone.classList.remove('drop-target');
    });
    zone.addEventListener('drop', async (e) => {
      e.preventDefault();
      zone.classList.remove('drop-target');
      const id = Number(e.dataTransfer.getData('text/plain'));
      const status = zone.dataset.status;
      const order = $$('.card', zone).map((c) => Number(c.dataset.id));
      const task = state.tasks.find((t) => t.id === id);
      if (!task) return;
      const prevStatus = task.status;
      task.status = status;
      order.forEach((tid, i) => { const x = state.tasks.find((t) => t.id === tid); if (x) x.position = i + 1; });
      renderBoard();
      try {
        await api('task_move', { body: { id, status, order } });
        if (prevStatus !== status) toast(`Movida a ${STATUS_LABEL[status]}`);
      } catch (err) {
        toast(err.message, true);
        await loadTasks();
      }
    });
  });

  // ---------- Diálogo de tarea ----------
  function openTask(task = null, status = 'pendiente') {
    state.editingTask = task;
    const f = els.taskForm;
    els.taskDialogTitle.textContent = task ? 'Editar tarea' : 'Nueva tarea';
    f.title.value = task?.title || '';
    f.notes.value = task?.notes || '';
    f.due_date.value = task?.due_date || '';
    f.project_id.value = String(task?.project_id ?? state.projectId);
    const st = task?.status || status;
    $$('input[name="status"]', f).forEach((r) => { r.checked = r.value === st; });
    els.deleteTask.hidden = !task;
    els.taskDialog.showModal();
  }

  els.taskForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = els.taskForm;
    const body = {
      id: state.editingTask?.id,
      project_id: Number(f.project_id.value),
      title: f.title.value.trim(),
      notes: f.notes.value,
      due_date: f.due_date.value || null,
      status: $('input[name="status"]:checked', f).value,
    };
    if (!body.title) { f.title.focus(); return; }
    try {
      const { task } = await api('task_save', { body });
      els.taskDialog.close();
      if (task.project_id !== state.projectId) {
        toast('Tarea movida a otro proyecto');
        await loadProjects();
        await loadTasks();
        return;
      }
      const i = state.tasks.findIndex((t) => t.id === task.id);
      if (i >= 0) state.tasks[i] = task; else state.tasks.push(task);
      renderBoard();
      toast(body.id ? 'Tarea guardada' : 'Tarea creada');
    } catch (err) { toast(err.message, true); }
  });

  els.deleteTask.addEventListener('click', async () => {
    const t = state.editingTask;
    if (!t || !window.confirm(`¿Eliminar la tarea «${t.title}»?`)) return;
    try {
      await api('task_delete', { body: { id: t.id } });
      state.tasks = state.tasks.filter((x) => x.id !== t.id);
      els.taskDialog.close();
      renderBoard();
      toast('Tarea eliminada');
    } catch (err) { toast(err.message, true); }
  });

  // ---------- Diálogo de proyecto ----------
  function openProject(project = null) {
    state.editingProject = project;
    els.projectDialogTitle.textContent = project ? 'Editar proyecto' : 'Nuevo proyecto';
    els.projectForm.name.value = project?.name || '';
    els.deleteProject.hidden = !project;
    els.projectDialog.showModal();
  }

  els.projectForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = els.projectForm.name.value.trim();
    if (!name) return;
    try {
      const { project } = await api('project_save', { body: { id: state.editingProject?.id, name } });
      els.projectDialog.close();
      state.projectId = project.id;
      store.set('aiform-mt.project', String(project.id));
      await loadProjects();
      await loadTasks();
      toast(state.editingProject ? 'Proyecto guardado' : 'Proyecto creado');
    } catch (err) { toast(err.message, true); }
  });

  els.deleteProject.addEventListener('click', async () => {
    const p = state.editingProject;
    if (!p) return;
    const total = p.counts.pendiente + p.counts.en_curso + p.counts.completado;
    const msg = total
      ? `¿Eliminar el proyecto «${p.name}» y sus ${total} tareas? No se puede deshacer.`
      : `¿Eliminar el proyecto «${p.name}»?`;
    if (!window.confirm(msg)) return;
    try {
      await api('project_delete', { body: { id: p.id } });
      els.projectDialog.close();
      state.projectId = null;
      await loadProjects();
      await loadTasks();
      toast('Proyecto eliminado');
    } catch (err) { toast(err.message, true); }
  });

  // ---------- Eventos generales ----------
  $$('[data-close]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));
  $$('dialog').forEach((d) => d.addEventListener('click', (e) => { if (e.target === d) d.close(); }));
  $('#addProjectBtn').addEventListener('click', () => openProject());
  $('#emptyCreateBtn').addEventListener('click', () => openProject());
  els.editProject.addEventListener('click', () => openProject(currentProject()));
  els.newTask.addEventListener('click', () => openTask(null, 'pendiente'));
  $$('.add-in-col').forEach((b) => b.addEventListener('click', () => openTask(null, b.dataset.status)));

  document.addEventListener('keydown', (e) => {
    if (e.key === 'n' && !e.metaKey && !e.ctrlKey && !$('dialog[open]') && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) && state.projectId) {
      e.preventDefault();
      openTask(null, 'pendiente');
    }
  });

  // ---------- Inicio ----------
  (async () => {
    try {
      await loadProjects();
      await loadTasks();
      // Acceso directo «Nueva tarea» del icono de la app
      const params = new URLSearchParams(location.search);
      if (params.has('nueva')) {
        history.replaceState(null, '', location.pathname);
        if (state.projectId) openTask(null, 'pendiente'); else openProject();
      }
    } catch (err) {
      els.title.textContent = 'No se pudo cargar';
      toast(err.message, true);
    }
  })();
})();
