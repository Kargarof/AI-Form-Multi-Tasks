(() => {
  'use strict';

  const STATUSES = ['pendiente', 'en_curso', 'completado'];
  const STATUS_LABEL = { pendiente: 'Pendiente', en_curso: 'En curso', completado: 'Completado' };
  const meta = (n) => document.querySelector(`meta[name="${n}"]`)?.content || '';
  const csrf = meta('csrf-token');
  const SYNC_MS = 20000;

  const state = {
    me: null, google: meta('google-enabled') === '1',
    projects: [], projectId: null, tasks: [],
    editingTask: null, editingProject: null, sharing: null, pendingJoin: null,
  };

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

  const els = {
    chips: $('#projectChips'),
    title: $('#projectTitle'),
    eyebrow: $('#projectEyebrow'),
    progress: $('#progressPill'),
    share: $('#shareBtn'),
    shareLabel: $('#shareLabel'),
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
    shareDialog: $('#shareDialog'),
    joinDialog: $('#joinDialog'),
    accountDialog: $('#accountDialog'),
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
    toastTimer = setTimeout(() => els.toast.classList.remove('show'), 2800);
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
    if (res.status === 401) { location.href = 'welcome.php'; throw new Error('Sin cuenta'); }
    if (!body) {
      const offline = res.headers.get('X-Offline-Copy') === '1';
      document.body.classList.toggle('offline-copy', offline);
      window.dispatchEvent(new Event('aiform:offline-copy'));
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const err = new Error(data.error || 'Algo ha fallado.');
      err.status = res.status;
      throw err;
    }
    return data;
  }

  const todayISO = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  };

  function formatDate(iso) {
    const [y, m, d] = iso.split('-').map(Number);
    const sameYear = y === new Date().getFullYear();
    return new Date(y, m - 1, d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short', ...(sameYear ? {} : { year: 'numeric' }) });
  }

  function el(tag, props = {}, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(props)) {
      if (k === 'class') node.className = v;
      else if (k === 'dataset') Object.assign(node.dataset, v);
      else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
      else if (v !== false && v != null) node.setAttribute(k, v === true ? '' : v);
    }
    for (const c of children.flat()) if (c != null && c !== false) node.append(c.nodeType ? c : document.createTextNode(c));
    return node;
  }

  const svg = (markup) => { const t = document.createElement('template'); t.innerHTML = markup.trim(); return t.content.firstChild; };
  const ICON_BOARD = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="5" height="16" rx="1.5"/><rect x="9.5" y="4" width="5" height="10" rx="1.5"/><rect x="16" y="4" width="5" height="13" rx="1.5"/></svg>';
  const ICON_SHARED = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c.8-3 3-4.6 5.5-4.6s4.7 1.6 5.5 4.6"/><path d="M16 6.5a3 3 0 0 1 0 5.6M18 14.6c1.3.6 2.2 2 2.5 4.4"/></svg>';
  const ICON_CHECK = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

  const personName = (p) => p.name || p.email || 'Sin nombre';
  const initial = (s) => (s || '?').trim().charAt(0).toUpperCase() || '?';

  async function copyText(text) {
    try { await navigator.clipboard.writeText(text); toast('Copiado'); }
    catch { toast('No se pudo copiar. Selecciónalo y cópialo a mano.', true); }
  }

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

  const currentProject = () => state.projects.find((p) => p.id === state.projectId) || null;
  const isShared = (p) => p && (p.members > 1 || p.role !== 'owner');

  function renderChips() {
    els.chips.replaceChildren(...state.projects.map((p) => {
      const open = p.counts.pendiente + p.counts.en_curso;
      return el('button', {
        type: 'button',
        class: 'chip' + (p.id === state.projectId ? ' active' : '') + (isShared(p) ? ' shared' : ''),
        'aria-pressed': p.id === state.projectId ? 'true' : 'false',
        title: isShared(p) ? 'Proyecto compartido' : null,
        onclick: () => selectProject(p.id),
      }, svg(isShared(p) ? ICON_SHARED : ICON_BOARD), el('span', {}, p.name),
      open ? el('span', { class: 'chip-count', title: 'Tareas abiertas' }, String(open)) : null);
    }));
    $('.chip.active', els.chips)?.scrollIntoView({ block: 'nearest', inline: 'nearest' });

    els.taskProjectSelect.replaceChildren(...state.projects.map((p) => el('option', { value: p.id }, p.name)));

    const has = state.projects.length > 0;
    els.empty.hidden = has;
    els.board.hidden = !has;
    for (const b of [els.newTask, els.progress, els.share]) b.hidden = !has;
    const p = currentProject();
    els.editProject.hidden = !p || p.role !== 'owner';
    if (!has) { els.title.textContent = 'Tus proyectos'; els.eyebrow.textContent = 'AI Form Multi Tasks'; }
    if (p) {
      els.eyebrow.textContent = p.role === 'owner'
        ? (p.members > 1 ? `Proyecto compartido · ${p.members} personas` : 'Proyecto')
        : `Compartido por ${p.owner}`;
      els.shareLabel.textContent = p.members > 1 ? `${p.members} personas` : 'Compartir';
    }
  }

  async function selectProject(id) {
    state.projectId = id;
    store.set('aiform-mt.project', String(id));
    renderChips();
    await loadTasks();
  }

  // ---------- Tareas ----------
  async function loadTasks({ quiet = false } = {}) {
    const p = currentProject();
    if (!p) { state.tasks = []; return; }
    els.title.textContent = p.name;
    document.title = `${p.name} · AI Form Multi Tasks`;
    try {
      const { tasks } = await api('tasks', { params: { project_id: p.id } });
      state.tasks = tasks;
      renderBoard();
    } catch (err) {
      if (err.status === 404) { // ya no tiene acceso (le quitaron del proyecto)
        if (!quiet) toast(err.message, true);
        state.projectId = null;
        await loadProjects();
        await loadTasks();
      } else if (!quiet) throw err;
    }
  }

  function renderBoard() {
    if ($('.card.dragging')) return; // no repintar mientras se arrastra
    const today = todayISO();
    const p = currentProject();
    for (const status of STATUSES) {
      const list = state.tasks.filter((t) => t.status === status).sort((a, b) => a.position - b.position || a.id - b.id);
      const col = $(`.col[data-status="${status}"]`);
      $('.count', col).textContent = String(list.length);
      $('.cards', col).replaceChildren(...(list.length
        ? list.map((t) => renderCard(t, today, isShared(p)))
        : [el('div', { class: 'col-empty' }, 'Sin tareas')]));
    }
    const done = state.tasks.filter((t) => t.status === 'completado').length;
    els.progress.textContent = `${done}/${state.tasks.length} completadas`;
    if (p) {
      for (const s of STATUSES) p.counts[s] = state.tasks.filter((t) => t.status === s).length;
      const chipCount = $('.chip.active .chip-count', els.chips);
      const open = p.counts.pendiente + p.counts.en_curso;
      if (chipCount) { chipCount.textContent = String(open); chipCount.hidden = !open; }
    }
  }

  function renderCard(t, today, shared) {
    const tags = [];
    if (t.due_date) {
      const overdue = t.status !== 'completado' && t.due_date < today;
      tags.push(el('span', { class: 'tag' + (overdue ? ' overdue' : ''), title: overdue ? 'Fecha pasada' : 'Fecha' }, formatDate(t.due_date)));
    }
    if (t.status === 'completado') tags.push(el('span', { class: 'tag status' }, 'Completado'));
    if (shared && t.updated_by_name && t.updated_by !== state.me?.id) {
      tags.push(el('span', { class: 'tag who', title: 'Último cambio' }, t.updated_by_name));
    }

    const card = el('article', {
      class: 'card', tabindex: '0', draggable: 'true', role: 'button',
      'aria-label': `${t.title}. ${STATUS_LABEL[t.status]}. Pulsa para editar`,
      dataset: { id: String(t.id), status: t.status },
      onclick: () => openTask(t),
      onkeydown: (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openTask(t); } },
    },
    el('p', { class: 'card-title' }, t.title),
    t.notes ? el('p', { class: 'card-notes' }, t.notes) : null,
    tags.length ? el('div', { class: 'card-meta' }, tags) : null,
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
    let closest = { offset: -Infinity, el: null };
    for (const c of $$('.card:not(.dragging)', zone)) {
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
      $('.card.dragging')?.classList.remove('dragging');
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
    let msg = total ? `¿Eliminar el proyecto «${p.name}» y sus ${total} tareas? No se puede deshacer.` : `¿Eliminar el proyecto «${p.name}»?`;
    if (p.members > 1) msg += `\n\nTambién desaparecerá para las otras ${p.members - 1} personas con las que lo compartes.`;
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

  // ---------- Compartir ----------
  const inviteURL = (code) => new URL(`./?unirse=${code}`, location.href).href;

  async function openShare() {
    const p = currentProject();
    if (!p) return;
    $('#shareProjectName').textContent = p.name;
    try {
      state.sharing = await api('sharing', { params: { project_id: p.id } });
    } catch (err) { toast(err.message, true); return; }
    renderShare();
    els.shareDialog.showModal();
  }

  function renderShare() {
    const s = state.sharing;
    const owner = s.role === 'owner';
    $('#shareOwnerTools').hidden = !owner;
    $('#leaveProjectBtn').hidden = owner;
    $('#shareIntro').textContent = owner
      ? 'Las personas que añadas verán este proyecto y podrán crear, mover y completar sus tareas.'
      : 'Este proyecto lo ha compartido otra persona contigo. Solo quien lo creó puede invitar o quitar gente.';
    $('#emailHint').textContent = s.google
      ? 'Si ya usa la app con Google, lo verá al momento. Si no, le aparecerá en cuanto entre con ese Gmail.'
      : 'Le aparecerá cuando entre en la app con ese Gmail. (Activa «Iniciar sesión con Google» en la configuración para que funcione.)';

    // Enlace
    $('#linkRow').hidden = !s.link;
    $('#createLinkBtn').hidden = !!s.link;
    $('#resetLinkBtn').hidden = !s.link;
    $('#disableLinkBtn').hidden = !s.link;
    if (s.link) $('#inviteLinkInput').value = inviteURL(s.link);

    // Personas
    $('#memberList').replaceChildren(...s.members.map((m) => el('li', { class: 'person' },
      m.avatar ? el('img', { src: m.avatar, alt: '', referrerpolicy: 'no-referrer', class: 'avatar' }) : el('span', { class: 'avatar' }, initial(personName(m))),
      el('span', { class: 'person-info' },
        el('span', { class: 'person-name' }, personName(m) + (m.me ? ' (tú)' : '')),
        el('span', { class: 'person-sub' }, m.role === 'owner' ? 'Creó el proyecto' : (m.email || 'Miembro'))),
      owner && !m.me ? el('button', { class: 'btn btn-ghost btn-sm', type: 'button', onclick: () => removeMember(m) }, 'Quitar') : null)));
    $('#pendingList').replaceChildren(...s.pending.map((inv) => el('li', { class: 'person pending' },
      el('span', { class: 'avatar' }, '@'),
      el('span', { class: 'person-info' },
        el('span', { class: 'person-name' }, inv.email),
        el('span', { class: 'person-sub' }, 'Invitación pendiente · entrará con este Gmail')),
      owner ? el('button', { class: 'btn btn-ghost btn-sm', type: 'button', onclick: () => cancelInvite(inv) }, 'Cancelar') : null)));
  }

  async function shareAction(action, body, okMsg) {
    try {
      const res = await api(action, { body: { project_id: state.projectId, ...body } });
      state.sharing = { ...state.sharing, ...res };
      renderShare();
      if (okMsg) toast(typeof okMsg === 'function' ? okMsg(res) : okMsg);
      await loadProjects();
    } catch (err) { toast(err.message, true); }
  }

  $('#inviteEmailForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = e.target.email;
    const email = input.value.trim();
    if (!email) return;
    await shareAction('invite_email', { email }, (r) => (r.added ? 'Añadido al proyecto' : 'Invitación guardada'));
    input.value = '';
  });
  const cancelInvite = (inv) => shareAction('invite_cancel', { invite_id: inv.id }, 'Invitación cancelada');
  const removeMember = (m) => {
    if (window.confirm(`¿Quitar a ${personName(m)} del proyecto? Dejará de verlo.`)) shareAction('member_remove', { user_id: m.id }, 'Persona quitada');
  };
  $('#createLinkBtn').addEventListener('click', () => shareAction('invite_link', {}, 'Enlace creado'));
  $('#resetLinkBtn').addEventListener('click', () => {
    if (window.confirm('El enlace anterior dejará de funcionar. ¿Crear uno nuevo?')) shareAction('invite_link', {}, 'Enlace nuevo creado');
  });
  $('#disableLinkBtn').addEventListener('click', () => shareAction('invite_link', { disable: true }, 'Enlace desactivado'));
  $('#copyLinkBtn').addEventListener('click', async () => {
    const url = $('#inviteLinkInput').value;
    const p = currentProject();
    if (navigator.share && matchMedia('(pointer: coarse)').matches) {
      try { await navigator.share({ title: p.name, text: `Únete a «${p.name}» en AI Form Multi Tasks`, url }); return; } catch { /* cancelado */ }
    }
    copyText(url);
  });
  $('#leaveProjectBtn').addEventListener('click', async () => {
    const p = currentProject();
    if (!p || !window.confirm(`¿Salir de «${p.name}»? Dejarás de ver sus tareas.`)) return;
    try {
      await api('project_leave', { body: { id: p.id } });
      els.shareDialog.close();
      state.projectId = null;
      await loadProjects();
      await loadTasks();
      toast('Has salido del proyecto');
    } catch (err) { toast(err.message, true); }
  });

  // ---------- Unirse con enlace ----------
  async function checkJoin() {
    const params = new URLSearchParams(location.search);
    const code = params.get('unirse');
    if (!code) return;
    history.replaceState(null, '', location.pathname);
    try {
      const { project } = await api('invite_info', { params: { code } });
      if (project.member) {
        await selectProject(project.id);
        toast(`Ya estás en «${project.name}»`);
        return;
      }
      state.pendingJoin = code;
      $('#joinText').textContent = `${project.owner} te invita al proyecto «${project.name}». Podrás ver y editar sus tareas.`;
      els.joinDialog.showModal();
    } catch (err) { toast(err.message, true); }
  }

  $('#joinConfirmBtn').addEventListener('click', async () => {
    try {
      const { project_id } = await api('join', { body: { code: state.pendingJoin } });
      els.joinDialog.close();
      state.projectId = project_id;
      store.set('aiform-mt.project', String(project_id));
      await loadProjects();
      await loadTasks();
      toast('Te has unido al proyecto');
    } catch (err) { toast(err.message, true); }
  });

  // ---------- Cuenta ----------
  async function openAccount() {
    try { const r = await api('me'); state.me = r.user; state.google = r.google; } catch (err) { toast(err.message, true); return; }
    const me = state.me;
    $('#nameForm').name.value = me.name || '';
    $('#googleBlock').hidden = !state.google && !me.google;
    $('#googleLinked').hidden = !me.google;
    $('#googleNotLinked').hidden = me.google;
    $('#googleEmail').textContent = me.email || '';
    $('#googleConnectBtn').href = `oauth.php?start=1&csrf=${encodeURIComponent(csrf)}`;
    $('#codeBox').hidden = true;
    $('#codeShownOnce').hidden = true;
    $('#newCodeBtn').textContent = me.hasCode ? 'Crear un código nuevo' : 'Crear código de acceso';
    els.accountDialog.showModal();
  }

  $('#nameForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const { user } = await api('me_update', { body: { name: e.target.name.value } });
      state.me = user;
      const btn = $('#accountBtn');
      if (!btn.querySelector('img')) btn.replaceChildren(el('span', { class: 'avatar-initial' }, initial(user.name)));
      toast('Nombre guardado');
    } catch (err) { toast(err.message, true); }
  });

  $('#newCodeBtn').addEventListener('click', async () => {
    if (state.me?.hasCode && !window.confirm('El código anterior dejará de funcionar. ¿Crear uno nuevo?')) return;
    try {
      const { code } = await api('recovery_code', { body: {} });
      state.me.hasCode = true;
      $('#codeValue').textContent = code;
      $('#codeBox').hidden = false;
      $('#codeShownOnce').hidden = false;
      $('#newCodeBtn').textContent = 'Crear un código nuevo';
    } catch (err) { toast(err.message, true); }
  });
  $('#copyCodeBtn').addEventListener('click', () => copyText($('#codeValue').textContent));

  $('#signOutBtn').addEventListener('click', async () => {
    const me = state.me || {};
    const msg = me.google
      ? 'Se cerrará la sesión en este dispositivo. Podrás volver a entrar con Google.'
      : me.hasCode
        ? 'Se cerrará la sesión en este dispositivo. Para volver a entrar necesitarás tu código de acceso.'
        : '⚠️ Tu cuenta no está conectada con Google y no tienes código de acceso.\n\nSi cierras la sesión, PERDERÁS el acceso a tus tareas para siempre.\n\n¿Cerrar sesión de todos modos?';
    if (!window.confirm(msg)) return;
    try {
      await api('sign_out', { body: {} });
      navigator.serviceWorker?.controller?.postMessage('clear-data');
      if ('caches' in window) await caches.delete('aiform-mt-data').catch(() => {});
      location.href = 'welcome.php';
    } catch (err) { toast(err.message, true); }
  });

  // ---------- Sincronización (proyectos compartidos) ----------
  async function sync() {
    if (document.hidden || $('dialog[open]') || $('.card.dragging') || !navigator.onLine) return;
    try {
      await loadProjects();
      await loadTasks({ quiet: true });
    } catch { /* se reintenta en la siguiente vuelta */ }
  }
  setInterval(() => { if (isShared(currentProject())) sync(); }, SYNC_MS);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) sync(); });

  // ---------- Eventos generales ----------
  $$('[data-close]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));
  $$('dialog').forEach((d) => d.addEventListener('click', (e) => { if (e.target === d) d.close(); }));
  $('#addProjectBtn').addEventListener('click', () => openProject());
  $('#emptyCreateBtn').addEventListener('click', () => openProject());
  $('#accountBtn').addEventListener('click', openAccount);
  els.share.addEventListener('click', openShare);
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
      const [{ user }] = await Promise.all([api('me'), loadProjects()]);
      state.me = user;
      await loadTasks();
      const flash = meta('app-flash');
      if (flash === 'google_ok') toast(user.email ? `Sesión iniciada con ${user.email}` : 'Sesión iniciada con Google');
      if (flash === 'welcome' && !state.projects.length && !new URLSearchParams(location.search).has('unirse')) openProject();
      const params = new URLSearchParams(location.search);
      if (params.has('nueva')) {
        history.replaceState(null, '', location.pathname);
        if (state.projectId) openTask(null, 'pendiente'); else openProject();
      }
      await checkJoin();
    } catch (err) {
      els.title.textContent = 'No se pudo cargar';
      toast(err.message, true);
    }
  })();
})();
