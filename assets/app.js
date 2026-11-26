/* AI Form Multi Tasks · tablero
 * Funciona primero en local: cada cambio se aplica al momento en el teléfono, se guarda en una
 * cola y se envía al servidor en cuanto hay conexión (ver lib/sync.php para los conflictos).
 */
(() => {
  'use strict';

  const STATUSES = ['pendiente', 'en_curso', 'completado'];
  const STATUS_LABEL = { pendiente: 'Pendiente', en_curso: 'En curso', completado: 'Completado' };
  const FIELD_LABEL = { title: 'Título', notes: 'Notas', due_date: 'Fecha', status: 'Estado', project_id: 'Proyecto', delete: 'Eliminar' };
  const TASK_FIELDS = ['title', 'notes', 'due_date', 'status', 'project_id'];
  const meta = (n) => document.querySelector(`meta[name="${n}"]`)?.content || '';
  let csrf = meta('csrf-token');
  const POLL_MS = 20000;

  const state = {
    me: null, google: meta('google-enabled') === '1',
    projectId: null, editingTask: null, editingProject: null, sharing: null, pendingJoin: null,
    syncing: false, online: navigator.onLine,
  };

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

  const els = {
    chips: $('#projectChips'), title: $('#projectTitle'), eyebrow: $('#projectEyebrow'),
    progress: $('#progressPill'), share: $('#shareBtn'), shareLabel: $('#shareLabel'),
    conflicts: $('#conflictBtn'), editProject: $('#editProjectBtn'), newTask: $('#newTaskBtn'),
    board: $('#board'), empty: $('#emptyState'), syncBar: $('#offlineBar'),
    taskDialog: $('#taskDialog'), taskForm: $('#taskForm'), taskDialogTitle: $('#taskDialogTitle'),
    taskProjectSelect: $('#taskProjectSelect'), deleteTask: $('#deleteTaskBtn'),
    projectDialog: $('#projectDialog'), projectForm: $('#projectForm'), projectDialogTitle: $('#projectDialogTitle'),
    deleteProject: $('#deleteProjectBtn'), shareDialog: $('#shareDialog'), joinDialog: $('#joinDialog'),
    accountDialog: $('#accountDialog'), conflictDialog: $('#conflictDialog'), toast: $('#toast'),
  };

  // ---------- Utilidades ----------
  const ls = {
    get(k, def) { try { const v = localStorage.getItem(k); return v == null ? def : JSON.parse(v); } catch { return def; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch { /* sin almacenamiento */ } },
    del(k) { try { localStorage.removeItem(k); } catch { /* nada */ } },
    keys(prefix) { try { return Object.keys(localStorage).filter((k) => k.startsWith(prefix)); } catch { return []; } },
  };

  const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  }));
  const nowISO = () => new Date().toISOString();
  const sameId = (a, b) => String(a) === String(b);
  const toRef = (v) => (typeof v === 'string' && /^\d+$/.test(v) ? Number(v) : v);

  let toastTimer;
  function toast(msg, isError = false, ms = 3200) {
    els.toast.textContent = msg;
    els.toast.classList.toggle('error', isError);
    els.toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => els.toast.classList.remove('show'), ms);
  }

  class NetError extends Error {}

  async function api(action, { params, body } = {}) {
    const qs = new URLSearchParams({ action, ...(params || {}) });
    const opts = body
      ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) }
      : { method: 'GET' };
    let res;
    try {
      res = await fetch(`api.php?${qs}`, { credentials: 'same-origin', ...opts });
    } catch {
      setOnline(false);
      throw new NetError('Sin conexión.');
    }
    if (res.headers.get('X-Offline-Copy') === '1') { setOnline(false); if (res.status === 503) throw new NetError('Sin conexión.'); }
    else setOnline(true);
    if (res.status === 401) { location.href = 'welcome.php'; throw new Error('Sin cuenta'); }
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
  function formatWhen(s) {
    const d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? '' : d.toLocaleString('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
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
  const ICON_CLOUD = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 18h10a4 4 0 0 0 .5-8 6 6 0 0 0-11.5 1.5A3.3 3.3 0 0 0 7 18z"/></svg>';

  const personName = (p) => p.name || p.email || 'Sin nombre';
  const initial = (s) => (s || '?').trim().charAt(0).toUpperCase() || '?';

  async function copyText(text) {
    try { await navigator.clipboard.writeText(text); toast('Copiado'); }
    catch { toast('No se pudo copiar. Selecciónalo y cópialo a mano.', true); }
  }

  function requireOnline() {
    if (navigator.onLine && state.online) return true;
    toast('Para esto necesitas conexión a internet.', true);
    return false;
  }

  // ---------- Almacén local (por cuenta) ----------
  const store = {
    ns: null, projects: [], tasks: {}, queue: [],
    load(uid) {
      this.ns = `mt:${uid}:`;
      this.projects = ls.get(this.ns + 'projects', []);
      this.tasks = ls.get(this.ns + 'tasks', {});
      this.queue = ls.get(this.ns + 'queue', []);
    },
    save() {
      if (!this.ns) return;
      ls.set(this.ns + 'projects', this.projects);
      ls.set(this.ns + 'tasks', this.tasks);
      ls.set(this.ns + 'queue', this.queue);
    },
    list(pid) { return this.tasks[String(pid)] || (this.tasks[String(pid)] = []); },
    findTask(id) {
      for (const pid of Object.keys(this.tasks)) {
        const t = this.tasks[pid].find((x) => sameId(x.id, id));
        if (t) return t;
      }
      return null;
    },
    clear() { ls.keys(this.ns).forEach((k) => ls.del(k)); },
  };

  function enqueue(op) {
    store.queue.push({ op_id: uuid(), changed_at: nowISO(), ...op });
    store.save();
    updateSyncBar();
    flush();
  }

  const currentProject = () => store.projects.find((p) => sameId(p.id, state.projectId)) || null;
  const isShared = (p) => p && (p.members > 1 || p.role !== 'owner');
  const projectTasks = () => (state.projectId == null ? [] : store.list(state.projectId));

  function recount(p) {
    if (!p || !store.tasks[String(p.id)]) return;
    const list = store.tasks[String(p.id)];
    p.counts = { pendiente: 0, en_curso: 0, completado: 0 };
    for (const t of list) p.counts[t.status]++;
  }

  // ---------- Acciones (se aplican al momento y se ponen en la cola) ----------
  function createProject(name) {
    const cid = uuid();
    const p = { id: `c:${cid}`, client_id: cid, name, role: 'owner', members: 1, conflicts: 0, conflict_mode: 'owner', owner: null, counts: { pendiente: 0, en_curso: 0, completado: 0 } };
    store.projects.push(p);
    store.tasks[p.id] = [];
    enqueue({ type: 'project_create', client_id: cid, name });
    return p;
  }

  function renameProject(p, name) {
    p.name = name;
    enqueue({ type: 'project_rename', project: p.id, name });
  }

  function createTask(pid, fields) {
    const cid = uuid();
    const list = store.list(pid);
    const pos = Math.max(0, ...list.filter((t) => t.status === fields.status).map((t) => t.position)) + 1;
    const t = { id: `c:${cid}`, client_id: cid, project_id: pid, position: pos, version: 0, updated_by: state.me?.id, updated_by_name: state.me?.name, ...fields };
    list.push(t);
    enqueue({ type: 'task_create', client_id: cid, project: pid, ...fields });
    return t;
  }

  function updateTask(task, fields, extra = {}) {
    const changed = {};
    const base = {};
    for (const k of TASK_FIELDS) {
      if (k in fields && String(fields[k] ?? '') !== String(task[k] ?? '')) {
        changed[k] = fields[k];
        base[k] = task[k] ?? null;
      }
    }
    if (!Object.keys(changed).length && !extra.order) return false;
    const fromPid = task.project_id;
    Object.assign(task, changed, { updated_by: state.me?.id, updated_by_name: state.me?.name });
    if ('project_id' in changed) {
      store.tasks[String(fromPid)] = store.list(fromPid).filter((x) => x !== task);
      store.list(changed.project_id).push(task);
    }
    enqueue({ type: 'task_update', task: task.id, fields: changed, base, ...extra });
    return true;
  }

  function deleteTaskLocal(task) {
    const base = {};
    for (const k of TASK_FIELDS) base[k] = task[k] ?? null;
    store.tasks[String(task.project_id)] = store.list(task.project_id).filter((x) => x !== task);
    enqueue({ type: 'task_delete', task: task.id, base });
  }

  // ---------- Sincronización ----------
  function setOnline(v) {
    if (state.online === v) return;
    state.online = v;
    updateSyncBar();
  }

  function updateSyncBar() {
    const n = store.queue.length;
    const offline = !navigator.onLine || !state.online;
    const bar = els.syncBar;
    if (offline) {
      bar.hidden = false;
      bar.className = 'offline-bar';
      bar.textContent = n
        ? `Sin conexión · Puedes seguir trabajando. ${n} ${n === 1 ? 'cambio se guardará' : 'cambios se guardarán'} al volver internet.`
        : 'Sin conexión · Puedes seguir trabajando. Los cambios se guardarán al volver internet.';
    } else if (n) {
      bar.hidden = false;
      bar.className = 'offline-bar syncing';
      bar.textContent = `Guardando ${n} ${n === 1 ? 'cambio' : 'cambios'}…`;
    } else {
      bar.hidden = true;
    }
  }

  function replaceProjectId(tempId, realId) {
    const p = store.projects.find((x) => sameId(x.id, tempId));
    if (p) p.id = realId;
    if (store.tasks[tempId]) {
      store.tasks[String(realId)] = [...(store.tasks[String(realId)] || []), ...store.tasks[tempId]];
      delete store.tasks[tempId];
    }
    for (const t of store.list(realId)) if (sameId(t.project_id, tempId)) t.project_id = realId;
    if (sameId(state.projectId, tempId)) { state.projectId = realId; ls.set(store.ns + 'project', realId); }
  }

  function noteResult(r, op) {
    const t = r.task || (op.task != null ? store.findTask(op.task) : null);
    const title = t?.title || r.title || 'una tarea';
    if (r.status === 'conflict') {
      const p = store.projects.find((x) => sameId(x.id, t?.project_id));
      toast(p?.role === 'owner'
        ? `«${title}» la cambiaron dos personas a la vez. Elige qué versión se queda.`
        : `Tu cambio en «${title}» coincide con el de otra persona. Lo decidirá quien creó el proyecto.`, false, 5000);
    } else if (r.status === 'superseded') {
      toast(`«${title}» tenía un cambio más reciente de otra persona; se ha quedado ese.`, false, 5000);
    } else if (r.status === 'gone') {
      toast(`«${title}» la eliminó otra persona.`, false, 5000);
    } else if (r.status === 'rejected') {
      toast(r.error || 'No se pudo guardar un cambio.', true, 5000);
    }
  }

  let flushPromise = null;
  function flush() {
    if (flushPromise) return flushPromise;
    if (!store.queue.length || !navigator.onLine) { updateSyncBar(); return Promise.resolve(); }
    flushPromise = (async () => {
      state.syncing = true;
      updateSyncBar();
      let synced = false;
      try {
        while (store.queue.length) {
          const batch = store.queue.slice(0, 50);
          const { results } = await api('sync', { body: { ops: batch } });
          const byId = new Map(batch.map((o) => [o.op_id, o]));
          for (const r of results) {
            const op = byId.get(r.op_id);
            if (!op) continue;
            if (r.status === 'applied' && r.project && op.type === 'project_create') replaceProjectId(`c:${op.client_id}`, r.project.id);
            if (r.task && r.task.client_id) {
              const local = store.findTask(`c:${r.task.client_id}`);
              if (local) local.id = r.task.id;
            }
            noteResult(r, op);
          }
          const done = new Set(results.map((r) => r.op_id));
          store.queue = store.queue.filter((o) => !done.has(o.op_id));
          store.save();
          synced = true;
        }
      } catch (err) {
        if (!(err instanceof NetError) && err.status !== 403) console.warn('sync', err);
        if (err.status === 403) await refreshCsrf();
      } finally {
        state.syncing = false;
        flushPromise = null;
        updateSyncBar();
      }
      if (synced) await refreshFromServer({ quiet: true });
    })();
    return flushPromise;
  }

  async function refreshCsrf() {
    try { const r = await api('me'); if (r.csrf) csrf = r.csrf; } catch { /* sin conexión */ }
  }

  /** Mezcla lo que hay en el servidor con los cambios que aún están en la cola. */
  function touchedRefs() {
    const s = new Set();
    for (const op of store.queue) {
      if (op.task != null) s.add(String(op.task));
      if (op.client_id && op.type === 'task_create') s.add(`c:${op.client_id}`);
    }
    return s;
  }
  const isTouched = (t, touched) => touched.has(String(t.id)) || (t.client_id && touched.has(`c:${t.client_id}`));

  function rebaseTasks(pid, serverTasks) {
    const touched = touchedRefs();
    const local = store.list(pid);
    const out = [];
    for (const st of serverTasks) {
      if (isTouched(st, touched)) {
        const lt = local.find((x) => sameId(x.id, st.id) || (st.client_id && x.client_id === st.client_id));
        if (lt) out.push({ ...lt, id: st.id, version: st.version });
        // si no está en local, se movió o se borró aquí y sigue pendiente
      } else {
        out.push(st);
      }
    }
    for (const lt of local) {
      const onServer = out.some((x) => sameId(x.id, lt.id) || (lt.client_id && x.client_id === lt.client_id));
      if (!onServer && isTouched(lt, touched)) out.push(lt);
    }
    store.tasks[String(pid)] = out;
  }

  function rebaseProjects(serverProjects) {
    const pendingCreates = new Set(store.queue.filter((o) => o.type === 'project_create').map((o) => o.client_id));
    const pendingRenames = new Map(store.queue.filter((o) => o.type === 'project_rename').map((o) => [String(o.project), o.name]));
    const out = serverProjects.map((sp) => {
      const name = pendingRenames.get(String(sp.id)) ?? (sp.client_id ? pendingRenames.get(`c:${sp.client_id}`) : undefined);
      return name ? { ...sp, name } : sp;
    });
    for (const lp of store.projects) {
      if (String(lp.id).startsWith('c:') && pendingCreates.has(lp.client_id) && !out.some((x) => x.client_id === lp.client_id)) out.push(lp);
    }
    // los proyectos de los que ya no eres miembro desaparecen también de la copia local
    for (const key of Object.keys(store.tasks)) {
      if (!out.some((p) => sameId(p.id, key))) delete store.tasks[key];
    }
    store.projects = out;
  }

  async function refreshFromServer({ all = false, quiet = false } = {}) {
    try {
      const { projects } = await api('projects');
      if (!state.online) return; // es la copia guardada sin conexión: la local ya está al día
      rebaseProjects(projects);
      if (!store.projects.some((p) => sameId(p.id, state.projectId))) pickProject();
      const ids = all ? store.projects.map((p) => p.id) : [state.projectId];
      await Promise.all(ids.filter((id) => id != null && !String(id).startsWith('c:')).map(async (id) => {
        const { tasks } = await api('tasks', { params: { project_id: id } });
        rebaseTasks(id, tasks);
      }));
      store.projects.forEach(recount);
      store.save();
      render();
    } catch (err) {
      if (!quiet && !(err instanceof NetError)) toast(err.message, true);
    }
  }

  function pickProject() {
    const saved = ls.get(store.ns + 'project', null);
    state.projectId = store.projects.some((p) => sameId(p.id, saved)) ? saved : (store.projects[0]?.id ?? null);
  }

  // ---------- Pintar ----------
  function render() {
    renderChips();
    renderHead();
    renderBoard();
  }

  function renderChips() {
    els.chips.replaceChildren(...store.projects.map((p) => {
      const open = (p.counts?.pendiente || 0) + (p.counts?.en_curso || 0);
      return el('button', {
        type: 'button',
        class: 'chip' + (sameId(p.id, state.projectId) ? ' active' : '') + (isShared(p) ? ' shared' : ''),
        'aria-pressed': sameId(p.id, state.projectId) ? 'true' : 'false',
        title: isShared(p) ? 'Proyecto compartido' : null,
        onclick: () => selectProject(p.id),
      }, svg(isShared(p) ? ICON_SHARED : ICON_BOARD), el('span', {}, p.name),
      p.conflicts ? el('span', { class: 'chip-count warn', title: 'Cambios por revisar' }, '!') : null,
      open ? el('span', { class: 'chip-count', title: 'Tareas abiertas' }, String(open)) : null);
    }));
    $('.chip.active', els.chips)?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    els.taskProjectSelect.replaceChildren(...store.projects.map((p) => el('option', { value: String(p.id) }, p.name)));
  }

  function renderHead() {
    const has = store.projects.length > 0;
    els.empty.hidden = has;
    els.board.hidden = !has;
    for (const b of [els.newTask, els.progress, els.share]) b.hidden = !has;
    const p = currentProject();
    els.editProject.hidden = !p || p.role !== 'owner';
    els.conflicts.hidden = !p || !p.conflicts || p.role !== 'owner';
    if (p?.conflicts) els.conflicts.querySelector('span').textContent = `${p.conflicts} por revisar`;
    if (!has) { els.title.textContent = 'Tus proyectos'; els.eyebrow.textContent = 'AI Form Multi Tasks'; return; }
    if (p) {
      els.title.textContent = p.name;
      document.title = `${p.name} · AI Form Multi Tasks`;
      els.eyebrow.textContent = p.role === 'owner'
        ? (p.members > 1 ? `Proyecto compartido · ${p.members} personas` : 'Proyecto')
        : `Compartido por ${p.owner}`;
      els.shareLabel.textContent = p.members > 1 ? `${p.members} personas` : 'Compartir';
    }
  }

  function renderBoard() {
    if ($('.card.dragging')) return;
    const today = todayISO();
    const p = currentProject();
    const tasks = projectTasks();
    const touched = touchedRefs();
    for (const status of STATUSES) {
      const list = tasks.filter((t) => t.status === status).sort((a, b) => a.position - b.position || String(a.id).localeCompare(String(b.id)));
      const col = $(`.col[data-status="${status}"]`);
      $('.count', col).textContent = String(list.length);
      $('.cards', col).replaceChildren(...(list.length
        ? list.map((t) => renderCard(t, today, isShared(p), isTouched(t, touched)))
        : [el('div', { class: 'col-empty' }, 'Sin tareas')]));
    }
    const done = tasks.filter((t) => t.status === 'completado').length;
    els.progress.textContent = `${done}/${tasks.length} completadas`;
    if (p) recount(p);
    const chipCount = $('.chip.active .chip-count:not(.warn)', els.chips);
    if (p && chipCount) {
      const open = p.counts.pendiente + p.counts.en_curso;
      chipCount.textContent = String(open);
      chipCount.hidden = !open;
    }
  }

  function renderCard(t, today, shared, pending) {
    const tags = [];
    if (t.due_date) {
      const overdue = t.status !== 'completado' && t.due_date < today;
      tags.push(el('span', { class: 'tag' + (overdue ? ' overdue' : ''), title: overdue ? 'Fecha pasada' : 'Fecha' }, formatDate(t.due_date)));
    }
    if (t.status === 'completado') tags.push(el('span', { class: 'tag status' }, 'Completado'));
    if (shared && t.updated_by_name && t.updated_by !== state.me?.id) tags.push(el('span', { class: 'tag who', title: 'Último cambio' }, t.updated_by_name));
    if (pending) tags.push(el('span', { class: 'tag pending', title: 'Pendiente de guardar en el servidor' }, svg(ICON_CLOUD), 'Sin guardar'));

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

  async function selectProject(id) {
    state.projectId = id;
    ls.set(store.ns + 'project', id);
    render();
    if (navigator.onLine && !String(id).startsWith('c:')) refreshFromServer({ quiet: true });
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
    zone.addEventListener('dragleave', (e) => { if (!zone.contains(e.relatedTarget)) zone.classList.remove('drop-target'); });
    zone.addEventListener('drop', (e) => {
      e.preventDefault();
      zone.classList.remove('drop-target');
      const id = toRef(e.dataTransfer.getData('text/plain'));
      const status = zone.dataset.status;
      const order = $$('.card', zone).map((c) => toRef(c.dataset.id));
      const task = projectTasks().find((t) => sameId(t.id, id));
      if (!task) return;
      $('.card.dragging')?.classList.remove('dragging');
      const prev = task.status;
      order.forEach((tid, i) => { const x = projectTasks().find((t) => sameId(t.id, tid)); if (x) x.position = i + 1; });
      updateTask(task, { status }, { order });
      render();
      if (prev !== status) toast(`Movida a ${STATUS_LABEL[status]}`);
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

  els.taskForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const f = els.taskForm;
    const title = f.title.value.trim().replace(/\s+/g, ' ');
    if (!title) { f.title.focus(); return; }
    const fields = {
      title,
      notes: f.notes.value.trim() || null,
      due_date: f.due_date.value || null,
      status: $('input[name="status"]:checked', f).value,
      project_id: toRef(f.project_id.value),
    };
    const task = state.editingTask;
    els.taskDialog.close();
    if (task) {
      const moved = !sameId(fields.project_id, task.project_id);
      if (updateTask(task, fields)) toast(moved ? 'Tarea movida a otro proyecto' : 'Tarea guardada');
    } else {
      const { project_id: pid, ...rest } = fields;
      createTask(pid, rest);
      toast('Tarea creada');
    }
    store.projects.forEach(recount);
    store.save();
    render();
  });

  els.deleteTask.addEventListener('click', () => {
    const t = state.editingTask;
    if (!t || !window.confirm(`¿Eliminar la tarea «${t.title}»?`)) return;
    deleteTaskLocal(t);
    els.taskDialog.close();
    render();
    toast('Tarea eliminada');
  });

  // ---------- Diálogo de proyecto ----------
  function openProject(project = null) {
    state.editingProject = project;
    els.projectDialogTitle.textContent = project ? 'Editar proyecto' : 'Nuevo proyecto';
    els.projectForm.name.value = project?.name || '';
    els.deleteProject.hidden = !project;
    const settings = $('#conflictSettings');
    settings.hidden = !project || String(project.id).startsWith('c:');
    if (project) $$('input[name="conflict_mode"]', settings).forEach((r) => { r.checked = r.value === (project.conflict_mode || 'owner'); });
    els.projectDialog.showModal();
  }

  els.projectForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = els.projectForm.name.value.trim().replace(/\s+/g, ' ');
    if (!name) return;
    const p = state.editingProject;
    if (p) {
      if (name !== p.name) renameProject(p, name);
      const mode = $('input[name="conflict_mode"]:checked', els.projectForm)?.value;
      if (mode && mode !== (p.conflict_mode || 'owner') && !String(p.id).startsWith('c:')) {
        if (!requireOnline()) return;
        try {
          await api('project_settings', { body: { id: p.id, conflict_mode: mode } });
          p.conflict_mode = mode;
        } catch (err) { toast(err.message, true); return; }
      }
      els.projectDialog.close();
      toast('Proyecto guardado');
    } else {
      const np = createProject(name);
      state.projectId = np.id;
      ls.set(store.ns + 'project', np.id);
      els.projectDialog.close();
      toast('Proyecto creado');
    }
    store.save();
    render();
  });

  els.deleteProject.addEventListener('click', async () => {
    const p = state.editingProject;
    if (!p) return;
    const total = (p.counts?.pendiente || 0) + (p.counts?.en_curso || 0) + (p.counts?.completado || 0);
    let msg = total ? `¿Eliminar el proyecto «${p.name}» y sus ${total} tareas? No se puede deshacer.` : `¿Eliminar el proyecto «${p.name}»?`;
    if (p.members > 1) msg += `\n\nTambién desaparecerá para las otras ${p.members - 1} personas con las que lo compartes.`;
    if (String(p.id).startsWith('c:')) {
      // Todavía no se había guardado en el servidor: basta con quitarlo de la cola.
      if (!window.confirm(msg)) return;
      const cid = p.client_id;
      store.queue = store.queue.filter((o) => !(o.type === 'project_create' && o.client_id === cid) && !sameId(o.project, p.id));
      store.projects = store.projects.filter((x) => x !== p);
      delete store.tasks[p.id];
    } else {
      if (!requireOnline() || !window.confirm(msg)) return;
      try { await api('project_delete', { body: { id: p.id } }); } catch (err) { toast(err.message, true); return; }
      store.projects = store.projects.filter((x) => x !== p);
      delete store.tasks[String(p.id)];
    }
    els.projectDialog.close();
    pickProject();
    store.save();
    render();
    toast('Proyecto eliminado');
  });

  // ---------- Conflictos (los decide quien creó el proyecto) ----------
  const showValue = (k, v) => {
    if (v == null || v === '') return '—';
    if (k === 'status') return STATUS_LABEL[v] || v;
    if (k === 'due_date') return formatDate(String(v).slice(0, 10));
    if (k === 'project_id') return store.projects.find((p) => sameId(p.id, v))?.name || 'Otro proyecto';
    return String(v);
  };

  async function openConflicts() {
    const p = currentProject();
    if (!p || !requireOnline()) return;
    let list;
    try { ({ conflicts: list } = await api('conflicts', { params: { project_id: p.id } })); }
    catch (err) { toast(err.message, true); return; }
    renderConflicts(list);
    if (!els.conflictDialog.open) els.conflictDialog.showModal();
  }

  function renderConflicts(list) {
    const box = $('#conflictList');
    if (!list.length) {
      box.replaceChildren(el('p', { class: 'hint' }, 'No queda nada por revisar.'));
      return;
    }
    box.replaceChildren(...list.map((c) => {
      const rows = c.kind === 'delete'
        ? [el('div', { class: 'cf-row' }, el('span', { class: 'cf-label' }, 'Cambio'), el('span', { class: 'cf-new' }, `${c.by} la eliminó sin conexión`))]
        : Object.keys(c.fields).map((k) => el('div', { class: 'cf-row' },
          el('span', { class: 'cf-label' }, FIELD_LABEL[k] || k),
          el('span', { class: 'cf-cur' }, el('small', {}, 'Actual'), showValue(k, c.current[k])),
          el('span', { class: 'cf-new' }, el('small', {}, `De ${c.by}`), showValue(k, c.fields[k]))));
      return el('article', { class: 'conflict' },
        el('h4', {}, `«${c.title}»`),
        el('p', { class: 'hint' }, `${c.by} lo cambió sin conexión (${formatWhen(c.changed_at)}). La versión actual es de ${c.current_by || 'otra persona'} (${formatWhen(c.current_at)}).`),
        ...rows,
        c.gone ? el('p', { class: 'hint warn' }, 'La tarea ya está eliminada.') : null,
        el('div', { class: 'link-actions' },
          el('button', { class: 'btn', type: 'button', onclick: () => resolveConflict(c, 'current') }, 'Mantener la actual'),
          c.gone ? null : el('button', { class: 'btn btn-primary', type: 'button', onclick: () => resolveConflict(c, 'incoming') },
            c.kind === 'delete' ? 'Eliminarla' : `Usar la de ${c.by}`)));
    }));
  }

  async function resolveConflict(c, choice) {
    if (!requireOnline()) return;
    try {
      await api('conflict_resolve', { body: { id: c.id, choice } });
      toast(choice === 'current' ? 'Se queda la versión actual' : 'Cambio aplicado');
      await refreshFromServer({ quiet: true });
      await openConflicts();
    } catch (err) { toast(err.message, true); }
  }

  // ---------- Compartir ----------
  const inviteURL = (code) => new URL(`./?unirse=${code}`, location.href).href;

  async function openShare() {
    const p = currentProject();
    if (!p) return;
    if (String(p.id).startsWith('c:')) { toast('Este proyecto aún no se ha guardado en el servidor. Conéctate para compartirlo.', true); return; }
    if (!requireOnline()) return;
    $('#shareProjectName').textContent = p.name;
    try { state.sharing = await api('sharing', { params: { project_id: p.id } }); }
    catch (err) { toast(err.message, true); return; }
    renderShare();
    els.shareDialog.showModal();
  }

  function renderShare() {
    const s = state.sharing;
    const owner = s.role === 'owner';
    $('#shareOwnerTools').hidden = !owner;
    $('#leaveProjectBtn').hidden = owner;
    $('#shareIntro').textContent = owner
      ? 'Las personas que añadas verán este proyecto y podrán crear, mover y completar sus tareas, también sin conexión.'
      : 'Este proyecto lo ha compartido otra persona contigo. Solo quien lo creó puede invitar o quitar gente.';
    $('#emailHint').textContent = 'Si ya usa la app con Google, lo verá al momento. Si no, le aparecerá en cuanto entre con ese Gmail.';
    $('#linkRow').hidden = !s.link;
    $('#createLinkBtn').hidden = !!s.link;
    $('#resetLinkBtn').hidden = !s.link;
    $('#disableLinkBtn').hidden = !s.link;
    if (s.link) $('#inviteLinkInput').value = inviteURL(s.link);
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
    if (!requireOnline()) return;
    try {
      const res = await api(action, { body: { project_id: state.projectId, ...body } });
      state.sharing = { ...state.sharing, ...res };
      renderShare();
      if (okMsg) toast(typeof okMsg === 'function' ? okMsg(res) : okMsg);
      await refreshFromServer({ quiet: true });
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
    if (!p || !requireOnline() || !window.confirm(`¿Salir de «${p.name}»? Dejarás de ver sus tareas.`)) return;
    try {
      await api('project_leave', { body: { id: p.id } });
      els.shareDialog.close();
      store.projects = store.projects.filter((x) => x !== p);
      delete store.tasks[String(p.id)];
      pickProject();
      store.save();
      render();
      toast('Has salido del proyecto');
    } catch (err) { toast(err.message, true); }
  });

  // ---------- Unirse con enlace ----------
  async function checkJoin() {
    const params = new URLSearchParams(location.search);
    const code = params.get('unirse');
    if (!code) return;
    history.replaceState(null, '', location.pathname);
    if (!requireOnline()) return;
    try {
      const { project } = await api('invite_info', { params: { code } });
      if (project.member) {
        await refreshFromServer({ quiet: true });
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
    if (!requireOnline()) return;
    try {
      const { project_id } = await api('join', { body: { code: state.pendingJoin } });
      els.joinDialog.close();
      state.projectId = project_id;
      ls.set(store.ns + 'project', project_id);
      await refreshFromServer();
      toast('Te has unido al proyecto');
    } catch (err) { toast(err.message, true); }
  });

  // ---------- Cuenta ----------
  async function openAccount() {
    if (navigator.onLine) {
      try { const r = await api('me'); state.me = r.user; state.google = r.google; if (r.csrf) csrf = r.csrf; ls.set('mt:me', r.user); } catch { /* sin conexión */ }
    }
    const me = state.me || {};
    $('#nameForm').name.value = me.name || '';
    $('#googleBlock').hidden = !state.google && !me.google;
    $('#googleLinked').hidden = !me.google;
    $('#googleNotLinked').hidden = !!me.google;
    $('#googleEmail').textContent = me.email || '';
    $('#googleConnectBtn').href = `oauth.php?start=1&csrf=${encodeURIComponent(csrf)}`;
    $('#codeBox').hidden = true;
    $('#codeShownOnce').hidden = true;
    $('#newCodeBtn').textContent = me.hasCode ? 'Crear un código nuevo' : 'Crear código de acceso';
    els.accountDialog.showModal();
  }

  $('#nameForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!requireOnline()) return;
    try {
      const { user } = await api('me_update', { body: { name: e.target.name.value } });
      state.me = user;
      ls.set('mt:me', user);
      const btn = $('#accountBtn');
      if (!btn.querySelector('img')) btn.replaceChildren(el('span', { class: 'avatar-initial' }, initial(user.name)));
      toast('Nombre guardado');
    } catch (err) { toast(err.message, true); }
  });

  $('#googleConnectBtn').addEventListener('click', (e) => { if (!requireOnline()) e.preventDefault(); });

  $('#newCodeBtn').addEventListener('click', async () => {
    if (!requireOnline()) return;
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
    if (!requireOnline()) return;
    await flush();
    const me = state.me || {};
    let msg = me.google
      ? 'Se cerrará la sesión en este dispositivo. Podrás volver a entrar con Google.'
      : me.hasCode
        ? 'Se cerrará la sesión en este dispositivo. Para volver a entrar necesitarás tu código de acceso.'
        : '⚠️ Tu cuenta no está conectada con Google y no tienes código de acceso.\n\nSi cierras la sesión, PERDERÁS el acceso a tus tareas para siempre.\n\n¿Cerrar sesión de todos modos?';
    if (store.queue.length) msg = `Hay ${store.queue.length} cambios sin guardar que se perderán.\n\n` + msg;
    if (!window.confirm(msg)) return;
    try {
      await api('sign_out', { body: {} });
      store.clear();
      ls.del('mt:me');
      navigator.serviceWorker?.controller?.postMessage('clear-data');
      if ('caches' in window) await caches.delete('aiform-mt-data').catch(() => {});
      location.href = 'welcome.php';
    } catch (err) { toast(err.message, true); }
  });

  // ---------- Conexión y refresco periódico ----------
  window.addEventListener('online', () => { setOnline(true); flush().then(() => refreshFromServer({ quiet: true })); });
  window.addEventListener('offline', () => { setOnline(false); updateSyncBar(); });
  setInterval(() => {
    if (document.hidden || $('dialog[open]') || $('.card.dragging') || !navigator.onLine) return;
    if (store.queue.length) flush();
    else if (isShared(currentProject())) refreshFromServer({ quiet: true });
  }, POLL_MS);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && navigator.onLine && !$('dialog[open]')) flush().then(() => refreshFromServer({ quiet: true }));
  });

  // ---------- Eventos generales ----------
  $$('[data-close]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));
  $$('dialog').forEach((d) => d.addEventListener('click', (e) => { if (e.target === d) d.close(); }));
  $('#addProjectBtn').addEventListener('click', () => openProject());
  $('#emptyCreateBtn').addEventListener('click', () => openProject());
  $('#accountBtn').addEventListener('click', openAccount);
  els.share.addEventListener('click', openShare);
  els.conflicts.addEventListener('click', openConflicts);
  els.editProject.addEventListener('click', () => openProject(currentProject()));
  els.newTask.addEventListener('click', () => openTask(null, 'pendiente'));
  $$('.add-in-col').forEach((b) => b.addEventListener('click', () => openTask(null, b.dataset.status)));
  els.conflictDialog.addEventListener('close', () => refreshFromServer({ quiet: true }));

  document.addEventListener('keydown', (e) => {
    if (e.key === 'n' && !e.metaKey && !e.ctrlKey && !$('dialog[open]') && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) && state.projectId != null) {
      e.preventDefault();
      openTask(null, 'pendiente');
    }
  });

  // ---------- Inicio ----------
  (async () => {
    // 1. Cuenta: del servidor si hay conexión; si no, la última conocida en este dispositivo.
    const lastMe = ls.get('mt:me', null);
    try {
      const r = await api('me');
      state.me = r.user; state.google = r.google;
      if (r.csrf) csrf = r.csrf;
    } catch {
      state.me = lastMe;
    }
    if (!state.me) { els.title.textContent = 'Sin conexión'; toast('Conéctate a internet para entrar por primera vez.', true); return; }

    // Si este dispositivo tenía cambios pendientes de otra cuenta que se unió a esta (al entrar con Google), se conservan.
    if (lastMe && lastMe.id !== state.me.id) {
      const oldQueue = ls.get(`mt:${lastMe.id}:queue`, []);
      ls.keys(`mt:${lastMe.id}:`).forEach((k) => ls.del(k));
      store.load(state.me.id);
      if (oldQueue.length) { store.queue.push(...oldQueue); store.save(); }
    } else {
      store.load(state.me.id);
    }
    ls.set('mt:me', state.me);

    // 2. Pinta al momento con la copia local (funciona sin conexión).
    pickProject();
    render();
    updateSyncBar();

    // 3. Envía lo pendiente y trae lo último del servidor (todos los proyectos, para tenerlos sin conexión).
    if (navigator.onLine) {
      await flush();
      await refreshFromServer({ all: true, quiet: store.projects.length > 0 });
    }

    const flash = meta('app-flash');
    if (flash === 'google_ok') toast(state.me.email ? `Sesión iniciada con ${state.me.email}` : 'Sesión iniciada con Google');
    const params = new URLSearchParams(location.search);
    if (flash === 'welcome' && !store.projects.length && !params.has('unirse')) openProject();
    if (params.has('nueva')) {
      history.replaceState(null, '', location.pathname);
      if (state.projectId != null) openTask(null, 'pendiente'); else openProject();
    }
    await checkJoin();
  })();
})();
