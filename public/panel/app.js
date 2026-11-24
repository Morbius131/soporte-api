'use strict';
(function () {
  // ---------- Constantes ----------
  const STATUS = { open: 'Abierto', in_progress: 'En progreso', resolved: 'Resuelto', closed: 'Cerrado' };
  const PRIORITY = { low: 'Baja', medium: 'Media', high: 'Alta', urgent: 'Urgente' };
  const ROLE = { customer: 'Cliente', agent: 'Agente', admin: 'Administrador' };
  // Mismas transiciones que TicketStatus::transitions() en el backend
  const TRANSITIONS = {
    open: ['in_progress', 'closed'],
    in_progress: ['open', 'resolved', 'closed'],
    resolved: ['in_progress', 'closed'],
    closed: [],
  };

  const store = {
    get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* sin almacenamiento */ } },
    del(k) { try { localStorage.removeItem(k); } catch (e) { /* sin almacenamiento */ } },
  };

  const S = {
    token: store.get('soporte_token'), user: null, authMode: 'login',
    tickets: [], meta: null, stats: null, agents: [], current: null, editing: false,
    filters: { q: '', status: '', priority: '', sort: 'created_at', direction: 'desc', page: 1 },
  };

  // ---------- Utilidades ----------
  const $ = (sel, root = document) => root.querySelector(sel);
  const isStaff = () => S.user && S.user.role !== 'customer';

  // Crea nodos DOM sin innerHTML: el texto de usuarios nunca se interpreta como HTML.
  function el(tag, props, ...kids) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(props || {})) {
      if (v === false || v == null) continue;
      if (k === 'class') n.className = v;
      else if (k.startsWith('on')) n.addEventListener(k.slice(2).toLowerCase(), v);
      else if (['value', 'checked', 'disabled', 'selected'].includes(k)) n[k] = v;
      else n.setAttribute(k, v === true ? '' : v);
    }
    const add = (c) => {
      if (c == null || c === false) return;
      if (Array.isArray(c)) c.forEach(add);
      else n.append(c.nodeType ? c : document.createTextNode(String(c)));
    };
    kids.forEach(add);
    return n;
  }

  // Acceso seguro a campos del formulario (form.title / form.name chocan con propiedades nativas)
  const field = (form, name) => form.elements.namedItem(name);

  const fmt = (iso) => iso ? new Date(iso).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' }) : '—';

  function toast(msg, type) {
    const t = el('div', { class: 'toast' + (type === 'error' ? ' error' : '') }, msg);
    $('#toasts').append(t);
    setTimeout(() => t.remove(), 4500);
  }

  function errMsg(d, status) {
    if (d && d.errors) {
      const first = Object.values(d.errors)[0];
      if (first && first[0]) return first[0];
    }
    return (d && d.message) || ('Error ' + status);
  }

  async function api(path, opts = {}) {
    const headers = { Accept: 'application/json' };
    if (opts.body) headers['Content-Type'] = 'application/json';
    if (S.token) headers.Authorization = 'Bearer ' + S.token;
    const res = await fetch('/api' + path, {
      method: opts.method || 'GET', headers, body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    if (res.status === 204) return null;
    let data = null;
    try { data = await res.json(); } catch (e) { /* respuesta sin cuerpo */ }
    if (!res.ok) {
      if (res.status === 401 && S.token && !opts.noRedirect) logout(true);
      const err = new Error(errMsg(data, res.status));
      err.status = res.status;
      throw err;
    }
    return data;
  }

  const badge = (s) => el('span', { class: 'badge ' + s }, STATUS[s] || s);
  const prio = (p) => el('span', { class: 'prio ' + p }, PRIORITY[p] || p);

  function selectEl(options, value, onchange, label) {
    return el('select', { class: 'input', 'aria-label': label, onchange },
      options.map(([v, t]) => el('option', { value: v, selected: String(v) === String(value) }, t)));
  }

  // ---------- Sesión ----------
  function logout(expired) {
    S.token = null; S.user = null; S.current = null;
    store.del('soporte_token');
    renderAuth();
    if (expired) toast('Tu sesión expiró. Inicia sesión de nuevo.', 'error');
  }

  async function onLogout() {
    try { await api('/auth/logout', { method: 'POST', noRedirect: true }); } catch (e) { /* ya revocado */ }
    logout(false);
  }

  async function boot() {
    if (!S.token) return renderAuth();
    try {
      S.user = (await api('/auth/me')).data;
    } catch (e) {
      return renderAuth();
    }
    renderShell();
    await Promise.all([loadTickets(), loadStats(), loadAgents()]);
  }

  // ---------- Pantalla de acceso ----------
  function renderAuth() {
    const isLogin = S.authMode === 'login';
    const err = el('p', { class: 'form-error', role: 'alert' });
    const form = el('form', { onsubmit: (e) => submitAuth(e, err) },
      !isLogin && el('label', { class: 'field' }, el('span', null, 'Nombre'),
        el('input', { class: 'input', name: 'name', required: true, maxlength: 100, autocomplete: 'name' })),
      el('label', { class: 'field' }, el('span', null, 'Correo'),
        el('input', { class: 'input', name: 'email', type: 'email', required: true, autocomplete: 'email' })),
      el('label', { class: 'field' }, el('span', null, 'Contraseña'),
        el('input', { class: 'input', name: 'password', type: 'password', required: true, minlength: isLogin ? 1 : 8,
          autocomplete: isLogin ? 'current-password' : 'new-password' })),
      err,
      el('button', { class: 'btn primary', type: 'submit' }, isLogin ? 'Ingresar' : 'Crear cuenta'));

    const tab = (mode, text) => el('button', {
      class: 'tab', type: 'button', role: 'tab', 'aria-selected': String(S.authMode === mode),
      onclick: () => { S.authMode = mode; renderAuth(); },
    }, text);

    const demo = (label, email) => el('button', {
      class: 'btn', type: 'button',
      onclick: () => { field(form, 'email').value = email; field(form, 'password').value = 'password'; form.requestSubmit(); },
    }, label);

    $('#app').replaceChildren(el('div', { class: 'auth-wrap' }, el('div', { class: 'auth-card' },
      el('div', { class: 'brand' }, el('span', { class: 'brand-mark', 'aria-hidden': 'true' }, '✦'), 'Soporte'),
      el('p', { class: 'sub' }, 'Gestiona y da seguimiento a tus tickets.'),
      el('div', { class: 'tabs', role: 'tablist' }, tab('login', 'Ingresar'), tab('register', 'Registrarme')),
      form,
      isLogin && el('div', { class: 'demo' },
        el('p', null, 'Cuentas de demostración (si ejecutaste el seeder):'),
        el('div', { class: 'demo-row' },
          demo('Cliente', 'cliente@demo.test'), demo('Agente', 'agente@demo.test'), demo('Admin', 'admin@demo.test')))
    )));
  }

  async function submitAuth(e, errBox) {
    e.preventDefault();
    const f = e.target, btn = $('button[type=submit]', f);
    const body = { email: field(f, 'email').value.trim(), password: field(f, 'password').value };
    if (field(f, 'name')) body.name = field(f, 'name').value.trim();
    errBox.textContent = ''; btn.disabled = true;
    try {
      const data = await api(S.authMode === 'login' ? '/auth/login' : '/auth/register', { method: 'POST', body, noRedirect: true });
      S.token = data.token; store.set('soporte_token', data.token);
      await boot();
    } catch (err) {
      errBox.textContent = err.message; btn.disabled = false;
    }
  }

  // ---------- Estructura principal ----------
  function renderShell() {
    const search = el('input', { class: 'input search', type: 'search', placeholder: 'Buscar por título o descripción…',
      'aria-label': 'Buscar', value: S.filters.q });
    let timer;
    search.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => { S.filters.q = search.value.trim(); S.filters.page = 1; loadTickets(); }, 300);
    });
    const setFilter = (k) => (e) => { S.filters[k] = e.target.value; S.filters.page = 1; loadTickets(); };

    $('#app').replaceChildren(
      el('header', { class: 'topbar' }, el('div', { class: 'topbar-in' },
        el('div', { class: 'brand' }, el('span', { class: 'brand-mark', 'aria-hidden': 'true' }, '✦'), 'Soporte'),
        el('div', { class: 'spacer' }),
        el('div', { class: 'me' }, el('span', { class: 'name' }, S.user.name), el('span', { class: 'chip' }, ROLE[S.user.role])),
        el('button', { class: 'btn ghost', onclick: onLogout }, 'Salir'))),
      el('main', { class: 'page' },
        el('div', { id: 'stats' }),
        el('div', { class: 'toolbar' }, search,
          selectEl([['', 'Todos los estados'], ...Object.entries(STATUS)], S.filters.status, setFilter('status'), 'Filtrar por estado'),
          selectEl([['', 'Toda prioridad'], ...Object.entries(PRIORITY)], S.filters.priority, setFilter('priority'), 'Filtrar por prioridad'),
          selectEl([['created_at:desc', 'Más recientes'], ['created_at:asc', 'Más antiguos'], ['updated_at:desc', 'Actualizados'], ['title:asc', 'Título A–Z']],
            S.filters.sort + ':' + S.filters.direction, (e) => {
              [S.filters.sort, S.filters.direction] = e.target.value.split(':'); S.filters.page = 1; loadTickets();
            }, 'Ordenar'),
          el('button', { class: 'btn primary', onclick: openNewTicket }, '+ Nuevo ticket')),
        el('div', { id: 'list' }, el('div', { class: 'loading' }, 'Cargando…')),
        el('div', { id: 'pager' })),
      el('div', { id: 'layer' }));
  }

  // ---------- Datos ----------
  async function loadTickets() {
    const p = new URLSearchParams();
    const f = S.filters;
    if (f.q) p.set('q', f.q);
    if (f.status) p.set('status', f.status);
    if (f.priority) p.set('priority', f.priority);
    p.set('sort', f.sort); p.set('direction', f.direction); p.set('page', f.page); p.set('per_page', 8);
    try {
      const data = await api('/tickets?' + p);
      S.tickets = data.data; S.meta = data.meta;
      renderList();
    } catch (e) { toast(e.message, 'error'); }
  }

  async function loadStats() {
    if (!isStaff()) return;
    try { S.stats = (await api('/stats')).data; renderStats(); } catch (e) { /* no crítico */ }
  }

  async function loadAgents() {
    if (!isStaff()) return;
    try { S.agents = (await api('/agents')).data; } catch (e) { /* no crítico */ }
  }

  const refresh = () => Promise.all([loadTickets(), loadStats()]);

  // ---------- Estadísticas ----------
  function renderStats() {
    const box = $('#stats');
    if (!box || !S.stats) return;
    const st = S.stats;
    const card = (label, n, status) => el('button', {
      class: 'stat', type: 'button', 'aria-pressed': String(S.filters.status === status && status !== ''),
      onclick: () => {
        S.filters.status = S.filters.status === status ? '' : status; S.filters.page = 1;
        renderShell(); renderStats(); loadTickets();
      },
    }, el('div', { class: 'n' }, n), el('div', { class: 'l' }, label));
    box.className = 'stats';
    box.replaceChildren(
      card('Total', st.total, ''), card('Abiertos', st.by_status.open, 'open'),
      card('En progreso', st.by_status.in_progress, 'in_progress'), card('Resueltos', st.by_status.resolved, 'resolved'),
      card('Cerrados', st.by_status.closed, 'closed'),
      el('div', { class: 'stat' }, el('div', { class: 'n' }, st.unassigned_active), el('div', { class: 'l' }, 'Sin asignar (activos)')));
  }

  // ---------- Lista ----------
  function renderList() {
    const list = $('#list'), pager = $('#pager');
    if (!list) return;
    if (!S.tickets.length) {
      list.replaceChildren(el('div', { class: 'empty' }, 'No hay tickets que coincidan.'));
      pager.replaceChildren();
      return;
    }
    list.replaceChildren(el('div', { class: 'list' }, S.tickets.map((t) =>
      el('button', { class: 'ticket', type: 'button', onclick: () => openTicket(t.id) },
        el('div', null,
          el('div', { class: 't' }, '#' + t.id + ' · ' + t.title),
          el('div', { class: 'm' },
            [isStaff() && t.author ? t.author.name : null, fmt(t.created_at), (t.comments_count || 0) + ' comentario(s)',
              t.assignee ? 'Asignado a ' + t.assignee.name : (isStaff() ? 'Sin asignar' : null)].filter(Boolean).join(' · '))),
        el('div', { class: 'badges' }, prio(t.priority), badge(t.status))))));
    const m = S.meta;
    pager.replaceChildren(el('div', { class: 'pager' },
      el('span', null, 'Página ' + m.current_page + ' de ' + m.last_page + ' · ' + m.total + ' ticket(s)'),
      el('div', { class: 'btns' },
        el('button', { class: 'btn', disabled: m.current_page <= 1, onclick: () => { S.filters.page--; loadTickets(); } }, '← Anterior'),
        el('button', { class: 'btn', disabled: m.current_page >= m.last_page, onclick: () => { S.filters.page++; loadTickets(); } }, 'Siguiente →'))));
  }

  // ---------- Detalle ----------
  async function openTicket(id) {
    try {
      S.current = (await api('/tickets/' + id)).data; S.editing = false;
      renderDrawer();
    } catch (e) { toast(e.message, 'error'); }
  }

  function closeLayer() { S.current = null; const l = $('#layer'); if (l) l.replaceChildren(); }

  async function patchTicket(patch) {
    try {
      const data = (await api('/tickets/' + S.current.id, { method: 'PATCH', body: patch })).data;
      S.current = Object.assign({}, S.current, data, { comments: S.current.comments });
      S.editing = false;
      renderDrawer(); refresh(); toast('Ticket actualizado.');
    } catch (e) { toast(e.message, 'error'); renderDrawer(); }
  }

  function renderDrawer() {
    const t = S.current;
    if (!t) return;
    const canEdit = isStaff() || (t.author && t.author.id === S.user.id && t.status === 'open');
    const isAdmin = S.user.role === 'admin';

    let titleBlock, descBlock;
    if (S.editing) {
      const ti = el('input', { class: 'input', value: t.title, maxlength: 150, 'aria-label': 'Título' });
      const de = el('textarea', { class: 'input', 'aria-label': 'Descripción' }, t.description);
      titleBlock = el('div', { style: 'flex:1' }, ti);
      descBlock = el('div', null, de, el('div', { class: 'actions' },
        el('button', { class: 'btn primary', onclick: () => patchTicket({ title: ti.value.trim(), description: de.value.trim() }) }, 'Guardar'),
        el('button', { class: 'btn', onclick: () => { S.editing = false; renderDrawer(); } }, 'Cancelar')));
    } else {
      titleBlock = el('h2', null, '#' + t.id + ' · ' + t.title);
      descBlock = el('p', { class: 'desc' }, t.description);
    }

    const staffControls = isStaff() && el('div', { class: 'controls' },
      el('label', { class: 'field' }, el('span', null, 'Estado'),
        selectEl([[t.status, STATUS[t.status]], ...TRANSITIONS[t.status].map((s) => [s, STATUS[s]])], t.status,
          (e) => patchTicket({ status: e.target.value }), 'Estado')),
      el('label', { class: 'field' }, el('span', null, 'Prioridad'),
        selectEl(Object.entries(PRIORITY), t.priority, (e) => patchTicket({ priority: e.target.value }), 'Prioridad')),
      el('label', { class: 'field' }, el('span', null, 'Asignado a'),
        selectEl([['', 'Sin asignar'], ...S.agents.map((a) => [a.id, a.name])], t.assignee ? t.assignee.id : '',
          (e) => patchTicket({ assigned_to: e.target.value ? Number(e.target.value) : null }), 'Asignado a')));

    const comments = t.comments || [];
    const commentList = comments.length
      ? comments.map((c) => el('div', { class: 'comment' + (c.internal ? ' internal' : '') },
        el('div', { class: 'who' }, el('strong', null, c.author ? c.author.name : '—'),
          c.author ? el('span', { class: 'chip' }, ROLE[c.author.role]) : null,
          c.internal ? el('span', { class: 'chip' }, 'Nota interna') : null, el('span', null, fmt(c.created_at))),
        el('div', { class: 'body' }, c.body)))
      : [el('p', { class: 'muted' }, 'Aún no hay comentarios.')];

    let commentForm;
    if (t.status === 'closed') {
      commentForm = el('p', { class: 'muted' }, 'El ticket está cerrado y no admite comentarios.');
    } else {
      const ta = el('textarea', { class: 'input', placeholder: 'Escribe un comentario…', 'aria-label': 'Comentario' });
      const internal = isStaff() ? el('input', { type: 'checkbox' }) : null;
      commentForm = el('form', { onsubmit: async (e) => {
        e.preventDefault();
        const body = ta.value.trim();
        if (!body) return;
        try {
          const c = (await api('/tickets/' + t.id + '/comments', { method: 'POST', body: { body, internal: !!(internal && internal.checked) } })).data;
          S.current.comments = [...comments, c];
          S.current.comments_count = (S.current.comments_count || 0) + 1;
          renderDrawer(); loadTickets();
        } catch (err) { toast(err.message, 'error'); }
      } }, ta,
      internal && el('label', { class: 'check' }, internal, 'Nota interna (solo la ve el personal)'),
      el('button', { class: 'btn primary', type: 'submit' }, 'Comentar'));
    }

    const layer = $('#layer');
    layer.replaceChildren(el('div', { class: 'overlay', onclick: (e) => { if (e.target.classList.contains('overlay')) closeLayer(); } },
      el('aside', { class: 'drawer', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Detalle del ticket' },
        el('div', { class: 'drawer-head', }, titleBlock,
          el('button', { class: 'btn ghost', onclick: closeLayer, 'aria-label': 'Cerrar' }, '✕')),
        el('div', { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap' }, badge(t.status), prio(t.priority)),
        el('dl', { class: 'meta' },
          el('div', null, el('dt', null, 'Autor'), el('dd', null, t.author ? t.author.name : '—')),
          el('div', null, el('dt', null, 'Asignado a'), el('dd', null, t.assignee ? t.assignee.name : 'Sin asignar')),
          el('div', null, el('dt', null, 'Creado'), el('dd', null, fmt(t.created_at))),
          el('div', null, el('dt', null, 'Resuelto'), el('dd', null, fmt(t.resolved_at)))),
        descBlock,
        !S.editing && (canEdit || isAdmin) && el('div', { class: 'actions' },
          canEdit && el('button', { class: 'btn', onclick: () => { S.editing = true; renderDrawer(); } }, 'Editar'),
          isAdmin && el('button', { class: 'btn danger', onclick: async () => {
            if (!confirm('¿Eliminar este ticket de forma permanente?')) return;
            try { await api('/tickets/' + t.id, { method: 'DELETE' }); closeLayer(); refresh(); toast('Ticket eliminado.'); }
            catch (e) { toast(e.message, 'error'); }
          } }, 'Eliminar')),
        staffControls,
        el('h3', { class: 'section-title' }, 'Comentarios (' + comments.length + ')'),
        commentList, commentForm)));
  }

  // ---------- Nuevo ticket ----------
  function openNewTicket() {
    const err = el('p', { class: 'form-error', role: 'alert' });
    const form = el('form', { onsubmit: async (e) => {
      e.preventDefault();
      const btn = $('button[type=submit]', form);
      btn.disabled = true; err.textContent = '';
      try {
        const t = (await api('/tickets', { method: 'POST', body: {
          title: field(form, 'title').value.trim(), description: field(form, 'description').value.trim(), priority: field(form, 'priority').value,
        } })).data;
        closeLayer(); S.filters.page = 1; await refresh(); toast('Ticket creado.'); openTicket(t.id);
      } catch (error) { err.textContent = error.message; btn.disabled = false; }
    } },
    el('label', { class: 'field' }, el('span', null, 'Título'), el('input', { class: 'input', name: 'title', required: true, maxlength: 150 })),
    el('label', { class: 'field' }, el('span', null, 'Descripción'), el('textarea', { class: 'input', name: 'description', required: true })),
    el('label', { class: 'field' }, el('span', null, 'Prioridad'),
      el('select', { class: 'input', name: 'priority' }, Object.entries(PRIORITY).map(([v, t]) => el('option', { value: v, selected: v === 'medium' }, t)))),
    err,
    el('div', { class: 'modal-actions' },
      el('button', { class: 'btn', type: 'button', onclick: closeLayer }, 'Cancelar'),
      el('button', { class: 'btn primary', type: 'submit' }, 'Crear ticket')));

    $('#layer').replaceChildren(el('div', { class: 'overlay modal-wrap', onclick: (e) => { if (e.target.classList.contains('overlay')) closeLayer(); } },
      el('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Nuevo ticket' }, el('h2', null, 'Nuevo ticket'), form)));
    field(form, 'title').focus();
  }

  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeLayer(); });
  boot();
})();
