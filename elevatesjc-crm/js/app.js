/* Elevate SJC CRM — front-end SPA. Talks to the PHP/MySQL API under api/.
   No build step, no framework: a small hash router + template strings. */
(function () {
  'use strict';

  const DEAL_STAGES = ['New Enquiry', 'Needs Assessment', 'Proposal Sent', 'Negotiation', 'Won', 'Lost'];
  const PROGRAM_CATEGORIES = ['Leadership Development', 'Technical Skills', 'Soft Skills', 'Data Analytics & Visualisation', 'E-Learning'];
  const PAGE_TITLES = { dashboard: 'Dashboard', contacts: 'Contacts', deals: 'Pipeline', tasks: 'Tasks', programs: 'Programs', users: 'Users', settings: 'Settings' };

  const contentEl = document.getElementById('content');
  const pageTitleEl = document.getElementById('pageTitle');
  const modalOverlay = document.getElementById('modalOverlay');
  const modalBody = document.getElementById('modalBody');
  const toastEl = document.getElementById('toast');

  // ---------------- helpers ----------------
  function esc(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function money(n) {
    n = Number(n) || 0;
    return 'R ' + n.toLocaleString('en-ZA', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  }
  function fmtDate(d) {
    if (!d) return '—';
    const dt = new Date(d + 'T00:00:00');
    if (isNaN(dt)) return d;
    return dt.toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' });
  }
  function toast(msg) {
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => toastEl.classList.remove('show'), 2600);
  }
  function openModal(html) {
    modalBody.innerHTML = html;
    modalOverlay.classList.add('open');
  }
  function closeModal() {
    modalOverlay.classList.remove('open');
    modalBody.innerHTML = '';
  }
  modalOverlay.addEventListener('click', (e) => { if (e.target === modalOverlay) closeModal(); });

  async function api(path, opts) {
    opts = opts || {};
    const headers = Object.assign({ 'Content-Type': 'application/json' }, opts.headers || {});
    if (opts.method && opts.method !== 'GET') headers['X-CSRF-Token'] = window.CRM.csrfToken;
    const res = await fetch(window.CRM.apiBase + path, Object.assign({ credentials: 'same-origin' }, opts, { headers }));
    if (res.status === 401) { window.location.href = 'login.php'; throw new Error('Not authenticated'); }
    let data = null;
    try { data = await res.json(); } catch (e) { /* no body */ }
    if (!res.ok) throw new Error((data && data.error) || ('Request failed (' + res.status + ')'));
    return data;
  }
  const get = (path) => api(path);
  const post = (path, body) => api(path, { method: 'POST', body: JSON.stringify(body) });
  const put = (path, body) => api(path, { method: 'PUT', body: JSON.stringify(body) });
  const del = (path) => api(path, { method: 'DELETE' });

  function optionList(items, valueKey, labelKey, selected) {
    return '<option value="">—</option>' + items.map((it) =>
      `<option value="${it[valueKey]}"${String(it[valueKey]) === String(selected) ? ' selected' : ''}>${esc(it[labelKey])}</option>`
    ).join('');
  }

  // ---------------- router ----------------
  const routes = { dashboard: renderDashboard, contacts: renderContacts, deals: renderDeals, tasks: renderTasks, programs: renderPrograms, users: renderUsers, settings: renderSettings };

  function route() {
    const hash = (location.hash || '#/dashboard').replace(/^#\/?/, '');
    const name = routes[hash] ? hash : 'dashboard';
    document.querySelectorAll('.nav-item').forEach((a) => a.classList.toggle('active', a.dataset.route === name));
    pageTitleEl.textContent = PAGE_TITLES[name] || 'Dashboard';
    document.getElementById('sidebar').classList.remove('open');
    contentEl.innerHTML = '<div class="empty">Loading…</div>';
    routes[name]().catch((e) => { contentEl.innerHTML = `<div class="card"><div class="empty">Couldn't load this page: ${esc(e.message)}</div></div>`; });
  }
  window.addEventListener('hashchange', route);
  document.getElementById('menuBtn').addEventListener('click', () => document.getElementById('sidebar').classList.toggle('open'));

  // ================= Dashboard =================
  async function renderDashboard() {
    const d = await get('dashboard.php');
    const stageMax = Math.max(1, ...d.stage_breakdown.map((s) => s.c));
    const catMax = Math.max(1, ...d.category_breakdown.map((c) => c.c));

    contentEl.innerHTML = `
      <div class="kpi-grid">
        <div class="kpi"><div class="kpi-val">${d.total_contacts}</div><div class="kpi-lbl">Total Contacts</div></div>
        <div class="kpi navy"><div class="kpi-val">${d.open_deals_count}</div><div class="kpi-lbl">Open Deals</div></div>
        <div class="kpi"><div class="kpi-val">${money(d.open_deals_value)}</div><div class="kpi-lbl">Open Pipeline Value</div></div>
        <div class="kpi gold"><div class="kpi-val">${d.won_this_month_count}</div><div class="kpi-lbl">Won This Month (${money(d.won_this_month_value)})</div></div>
        <div class="kpi${d.tasks_overdue ? ' gold' : ''}"><div class="kpi-val">${d.tasks_due_week}</div><div class="kpi-lbl">Tasks Due (7 Days)${d.tasks_overdue ? ' · ' + d.tasks_overdue + ' overdue' : ''}</div></div>
      </div>
      <div class="two-col">
        <div class="card">
          <h3>Pipeline by Stage</h3>
          ${d.stage_breakdown.length ? d.stage_breakdown.map((s) => `
            <div class="bar"><div class="bar-lbl">${esc(s.stage)}</div><div class="bar-track"><div class="bar-fill" style="width:${(s.c / stageMax) * 100}%"></div></div><div class="bar-val">${s.c}</div></div>
          `).join('') : '<div class="empty">No deals yet.</div>'}
        </div>
        <div class="card">
          <h3>Active Pipeline by Category</h3>
          ${d.category_breakdown.length ? d.category_breakdown.map((c) => `
            <div class="bar"><div class="bar-lbl">${esc(c.category)}</div><div class="bar-track"><div class="bar-fill" style="width:${(c.c / catMax) * 100}%"></div></div><div class="bar-val">${c.c}</div></div>
          `).join('') : '<div class="empty">No categorised deals yet.</div>'}
        </div>
      </div>
      <div class="card">
        <h3>Upcoming Tasks</h3>
        ${d.upcoming_tasks.length ? d.upcoming_tasks.map((t) => `
          <div class="task-row"><div class="task-title">${esc(t.title)}${t.contact_name ? ' <span class="task-meta">— ' + esc(t.contact_name) + '</span>' : ''}</div><span class="pill pill-${t.priority === 'high' ? 'red' : t.priority === 'medium' ? 'gold' : 'navy'}">${esc(t.priority)}</span><span class="task-meta">${fmtDate(t.due_date)}</span></div>
        `).join('') : '<div class="empty">Nothing due — you\'re all caught up.</div>'}
      </div>`;
  }

  // ================= Contacts =================
  async function renderContacts(q) {
    q = q || '';
    const contacts = await get('contacts.php' + (q ? '?q=' + encodeURIComponent(q) : ''));
    contentEl.innerHTML = `
      <div class="toolbar">
        <input class="search" id="contactSearch" placeholder="Search contacts, company, tags…" value="${esc(q)}"/>
        <button class="btn btn-primary" id="addContactBtn">+ Add Contact</button>
      </div>
      <div class="card table-wrap">
        <table><thead><tr><th>Name</th><th>Company</th><th>Role</th><th>Email</th><th>Phone</th><th>Tags</th><th></th></tr></thead>
        <tbody>${contacts.length ? contacts.map((c) => `
          <tr><td><strong>${esc(c.name)}</strong></td><td>${esc(c.company)}</td><td>${esc(c.role)}</td>
          <td>${esc(c.email)}</td><td>${esc(c.phone)}</td>
          <td>${(c.tags || '').split(',').filter(Boolean).map((t) => `<span class="pill pill-teal">${esc(t.trim())}</span>`).join(' ')}</td>
          <td class="row-actions"><button class="btn btn-outline btn-sm" data-edit="${c.id}">Edit</button><button class="btn btn-danger btn-sm" data-del="${c.id}">Delete</button></td></tr>
        `).join('') : `<tr><td colspan="7"><div class="empty">No contacts found.</div></td></tr>`}</tbody></table>
      </div>`;

    document.getElementById('contactSearch').addEventListener('change', (e) => renderContacts(e.target.value));
    document.getElementById('addContactBtn').addEventListener('click', () => openContactModal());
    contentEl.querySelectorAll('[data-edit]').forEach((btn) => btn.addEventListener('click', () => openContactModal(contacts.find((c) => c.id == btn.dataset.edit))));
    contentEl.querySelectorAll('[data-del]').forEach((btn) => btn.addEventListener('click', async () => {
      if (!confirm('Delete this contact? This cannot be undone.')) return;
      await del('contacts.php?id=' + btn.dataset.del);
      toast('Contact deleted.');
      renderContacts(q);
    }));
  }

  function openContactModal(c) {
    c = c || {};
    openModal(`
      <h2>${c.id ? 'Edit Contact' : 'Add Contact'}</h2>
      <form id="contactForm">
        <div class="field"><label>Name *</label><input name="name" required value="${esc(c.name)}"/></div>
        <div class="field-row">
          <div class="field"><label>Company</label><input name="company" value="${esc(c.company)}"/></div>
          <div class="field"><label>Role</label><input name="role" value="${esc(c.role)}"/></div>
        </div>
        <div class="field-row">
          <div class="field"><label>Email</label><input type="email" name="email" value="${esc(c.email)}"/></div>
          <div class="field"><label>Phone</label><input name="phone" value="${esc(c.phone)}"/></div>
        </div>
        <div class="field"><label>Tags (comma-separated)</label><input name="tags" value="${esc(c.tags)}"/></div>
        <div class="field"><label>Notes</label><textarea name="notes">${esc(c.notes)}</textarea></div>
        <div class="modal-actions"><button type="button" class="btn btn-outline" id="cancelBtn">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
      </form>`);
    document.getElementById('cancelBtn').addEventListener('click', closeModal);
    document.getElementById('contactForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target).entries());
      try {
        if (c.id) await put('contacts.php?id=' + c.id, body); else await post('contacts.php', body);
        closeModal(); toast('Contact saved.'); renderContacts();
      } catch (err) { alert(err.message); }
    });
  }

  // ================= Deals / Pipeline =================
  async function renderDeals() {
    const [deals, contacts, programs] = await Promise.all([get('deals.php'), get('contacts.php'), get('programs.php')]);
    contentEl.innerHTML = `
      <div class="toolbar"><div></div><button class="btn btn-primary" id="addDealBtn">+ Add Deal</button></div>
      <div class="kanban" id="kanban">${DEAL_STAGES.map((stage) => {
        const items = deals.filter((d) => d.stage === stage);
        return `<div class="kcol" data-stage="${esc(stage)}">
          <div class="kcol-head">${esc(stage)}<span class="kcol-count">${items.length}</span></div>
          ${items.map((d) => `
            <div class="kcard${stage === 'Lost' ? ' lost' : ''}${stage === 'Won' ? ' won' : ''}" draggable="true" data-id="${d.id}">
              <strong>${esc(d.title)}</strong>
              <div class="meta">${esc(d.contact_name || 'No contact')}${d.contact_company ? ' · ' + esc(d.contact_company) : ''}</div>
              <div class="meta">${esc(d.program_name || 'No program')}</div>
              <div class="val">${money(d.value)}</div>
              <div class="row-actions" style="margin-top:8px"><button class="btn btn-outline btn-sm" data-edit="${d.id}">Edit</button><button class="btn btn-danger btn-sm" data-del="${d.id}">Delete</button></div>
            </div>`).join('')}
        </div>`;
      }).join('')}</div>`;

    document.getElementById('addDealBtn').addEventListener('click', () => openDealModal(null, contacts, programs));
    contentEl.querySelectorAll('[data-edit]').forEach((btn) => btn.addEventListener('click', (e) => {
      e.stopPropagation();
      openDealModal(deals.find((d) => d.id == btn.dataset.edit), contacts, programs);
    }));
    contentEl.querySelectorAll('[data-del]').forEach((btn) => btn.addEventListener('click', async (e) => {
      e.stopPropagation();
      if (!confirm('Delete this deal?')) return;
      await del('deals.php?id=' + btn.dataset.del);
      toast('Deal deleted.'); renderDeals();
    }));

    // Drag & drop between kanban columns.
    let dragId = null;
    contentEl.querySelectorAll('.kcard').forEach((card) => {
      card.addEventListener('dragstart', () => { dragId = card.dataset.id; });
    });
    contentEl.querySelectorAll('.kcol').forEach((col) => {
      col.addEventListener('dragover', (e) => { e.preventDefault(); col.classList.add('drag-over'); });
      col.addEventListener('dragleave', () => col.classList.remove('drag-over'));
      col.addEventListener('drop', async (e) => {
        e.preventDefault(); col.classList.remove('drag-over');
        if (!dragId) return;
        try { await put('deals.php?id=' + dragId, { stage: col.dataset.stage }); toast('Deal moved to ' + col.dataset.stage + '.'); renderDeals(); }
        catch (err) { alert(err.message); }
      });
    });
  }

  function openDealModal(d, contacts, programs) {
    d = d || {};
    openModal(`
      <h2>${d.id ? 'Edit Deal' : 'Add Deal'}</h2>
      <form id="dealForm">
        <div class="field"><label>Title *</label><input name="title" required value="${esc(d.title)}"/></div>
        <div class="field-row">
          <div class="field"><label>Contact</label><select name="contact_id">${optionList(contacts, 'id', 'name', d.contact_id)}</select></div>
          <div class="field"><label>Program</label><select name="program_id">${optionList(programs, 'id', 'name', d.program_id)}</select></div>
        </div>
        <div class="field-row">
          <div class="field"><label>Value (ZAR)</label><input type="number" step="0.01" name="value" value="${d.value || 0}"/></div>
          <div class="field"><label>Stage</label><select name="stage">${DEAL_STAGES.map((s) => `<option${s === (d.stage || 'New Enquiry') ? ' selected' : ''}>${s}</option>`).join('')}</select></div>
        </div>
        <div class="field"><label>Expected Close Date</label><input type="date" name="expected_close" value="${d.expected_close || ''}"/></div>
        <div class="field"><label>Notes</label><textarea name="notes">${esc(d.notes)}</textarea></div>
        <div class="modal-actions"><button type="button" class="btn btn-outline" id="cancelBtn">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
      </form>`);
    document.getElementById('cancelBtn').addEventListener('click', closeModal);
    document.getElementById('dealForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target).entries());
      try {
        if (d.id) await put('deals.php?id=' + d.id, body); else await post('deals.php', body);
        closeModal(); toast('Deal saved.'); renderDeals();
      } catch (err) { alert(err.message); }
    });
  }

  // ================= Tasks =================
  async function renderTasks(filter) {
    filter = filter || 'all';
    const [tasks, contacts, deals] = await Promise.all([get('tasks.php'), get('contacts.php'), get('deals.php')]);
    const today = new Date().toISOString().slice(0, 10);
    const filtered = tasks.filter((t) => {
      if (filter === 'overdue') return !t.done && t.due_date && t.due_date < today;
      if (filter === 'today') return !t.done && t.due_date === today;
      if (filter === 'upcoming') return !t.done && (!t.due_date || t.due_date >= today);
      if (filter === 'done') return !!t.done;
      return true;
    });

    contentEl.innerHTML = `
      <div class="toolbar">
        <select class="filter" id="taskFilter">
          <option value="all"${filter === 'all' ? ' selected' : ''}>All Tasks</option>
          <option value="overdue"${filter === 'overdue' ? ' selected' : ''}>Overdue</option>
          <option value="today"${filter === 'today' ? ' selected' : ''}>Due Today</option>
          <option value="upcoming"${filter === 'upcoming' ? ' selected' : ''}>Upcoming</option>
          <option value="done"${filter === 'done' ? ' selected' : ''}>Completed</option>
        </select>
        <button class="btn btn-primary" id="addTaskBtn">+ Add Task</button>
      </div>
      <div class="card">
        ${filtered.length ? filtered.map((t) => `
          <div class="task-row${t.done ? ' done' : ''}">
            <input type="checkbox" class="chk" data-toggle="${t.id}" ${t.done ? 'checked' : ''}/>
            <div class="task-title">${esc(t.title)}${t.contact_name ? ' <span class="task-meta">— ' + esc(t.contact_name) + '</span>' : ''}</div>
            <span class="pill pill-${t.priority === 'high' ? 'red' : t.priority === 'medium' ? 'gold' : 'navy'}">${esc(t.priority)}</span>
            <span class="task-meta">${fmtDate(t.due_date)}</span>
            <div class="row-actions"><button class="btn btn-outline btn-sm" data-edit="${t.id}">Edit</button><button class="btn btn-danger btn-sm" data-del="${t.id}">Delete</button></div>
          </div>
        `).join('') : '<div class="empty">No tasks in this view.</div>'}
      </div>`;

    document.getElementById('taskFilter').addEventListener('change', (e) => renderTasks(e.target.value));
    document.getElementById('addTaskBtn').addEventListener('click', () => openTaskModal(null, contacts, deals, filter));
    contentEl.querySelectorAll('[data-toggle]').forEach((chk) => chk.addEventListener('change', async () => {
      await put('tasks.php?id=' + chk.dataset.toggle, { done: chk.checked });
      renderTasks(filter);
    }));
    contentEl.querySelectorAll('[data-edit]').forEach((btn) => btn.addEventListener('click', () => openTaskModal(tasks.find((t) => t.id == btn.dataset.edit), contacts, deals, filter)));
    contentEl.querySelectorAll('[data-del]').forEach((btn) => btn.addEventListener('click', async () => {
      if (!confirm('Delete this task?')) return;
      await del('tasks.php?id=' + btn.dataset.del);
      toast('Task deleted.'); renderTasks(filter);
    }));
  }

  function openTaskModal(t, contacts, deals, filter) {
    t = t || {};
    openModal(`
      <h2>${t.id ? 'Edit Task' : 'Add Task'}</h2>
      <form id="taskForm">
        <div class="field"><label>Title *</label><input name="title" required value="${esc(t.title)}"/></div>
        <div class="field-row">
          <div class="field"><label>Due Date</label><input type="date" name="due_date" value="${t.due_date || ''}"/></div>
          <div class="field"><label>Priority</label><select name="priority">${['low', 'medium', 'high'].map((p) => `<option${p === (t.priority || 'medium') ? ' selected' : ''}>${p}</option>`).join('')}</select></div>
        </div>
        <div class="field-row">
          <div class="field"><label>Contact</label><select name="contact_id">${optionList(contacts, 'id', 'name', t.contact_id)}</select></div>
          <div class="field"><label>Deal</label><select name="deal_id">${optionList(deals, 'id', 'title', t.deal_id)}</select></div>
        </div>
        <div class="field"><label>Notes</label><textarea name="notes">${esc(t.notes)}</textarea></div>
        <div class="modal-actions"><button type="button" class="btn btn-outline" id="cancelBtn">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
      </form>`);
    document.getElementById('cancelBtn').addEventListener('click', closeModal);
    document.getElementById('taskForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target).entries());
      try {
        if (t.id) await put('tasks.php?id=' + t.id, body); else await post('tasks.php', body);
        closeModal(); toast('Task saved.'); renderTasks(filter);
      } catch (err) { alert(err.message); }
    });
  }

  // ================= Programs =================
  async function renderPrograms() {
    const programs = await get('programs.php');
    contentEl.innerHTML = `
      <div class="toolbar"><div></div><button class="btn btn-primary" id="addProgramBtn">+ Add Program</button></div>
      <div class="card table-wrap">
        <table><thead><tr><th>Name</th><th>Category</th><th>Description</th><th>Status</th><th></th></tr></thead>
        <tbody>${programs.length ? programs.map((p) => `
          <tr><td><strong>${esc(p.name)}</strong></td><td><span class="pill pill-navy">${esc(p.category)}</span></td>
          <td>${esc(p.description)}</td><td>${p.active == 1 ? '<span class="pill pill-teal">Active</span>' : '<span class="pill pill-red">Inactive</span>'}</td>
          <td class="row-actions"><button class="btn btn-outline btn-sm" data-edit="${p.id}">Edit</button><button class="btn btn-danger btn-sm" data-del="${p.id}">Delete</button></td></tr>
        `).join('') : '<tr><td colspan="5"><div class="empty">No programs yet.</div></td></tr>'}</tbody></table>
      </div>`;

    document.getElementById('addProgramBtn').addEventListener('click', () => openProgramModal());
    contentEl.querySelectorAll('[data-edit]').forEach((btn) => btn.addEventListener('click', () => openProgramModal(programs.find((p) => p.id == btn.dataset.edit))));
    contentEl.querySelectorAll('[data-del]').forEach((btn) => btn.addEventListener('click', async () => {
      if (!confirm('Delete this program?')) return;
      await del('programs.php?id=' + btn.dataset.del);
      toast('Program deleted.'); renderPrograms();
    }));
  }

  function openProgramModal(p) {
    p = p || {};
    openModal(`
      <h2>${p.id ? 'Edit Program' : 'Add Program'}</h2>
      <form id="programForm">
        <div class="field"><label>Name *</label><input name="name" required value="${esc(p.name)}"/></div>
        <div class="field"><label>Category *</label><select name="category" required>${PROGRAM_CATEGORIES.map((c) => `<option${c === p.category ? ' selected' : ''}>${c}</option>`).join('')}</select></div>
        <div class="field"><label>Description</label><textarea name="description">${esc(p.description)}</textarea></div>
        <div class="field"><label><input type="checkbox" name="active" value="1" ${p.active == 0 ? '' : 'checked'} style="width:auto;margin-right:6px"/>Active</label></div>
        <div class="modal-actions"><button type="button" class="btn btn-outline" id="cancelBtn">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
      </form>`);
    document.getElementById('cancelBtn').addEventListener('click', closeModal);
    document.getElementById('programForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const body = Object.fromEntries(fd.entries());
      body.active = fd.has('active');
      try {
        if (p.id) await put('programs.php?id=' + p.id, body); else await post('programs.php', body);
        closeModal(); toast('Program saved.'); renderPrograms();
      } catch (err) { alert(err.message); }
    });
  }

  // ================= Users (admin only) =================
  async function renderUsers() {
    if (window.CRM.currentUser.role !== 'admin') { contentEl.innerHTML = '<div class="card"><div class="empty">Administrator access required.</div></div>'; return; }
    const users = await get('users.php');
    contentEl.innerHTML = `
      <div class="toolbar"><div></div><button class="btn btn-primary" id="addUserBtn">+ Add User</button></div>
      <div class="card table-wrap">
        <table><thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Sign-in</th><th>Status</th><th></th></tr></thead>
        <tbody>${users.map((u) => `
          <tr><td><strong>${esc(u.name)}</strong></td><td>${esc(u.username) || '—'}</td><td>${esc(u.email) || '—'}</td>
          <td><span class="pill pill-navy">${esc(u.role)}</span></td>
          <td>${u.username ? '<span class="pill pill-teal">Local</span>' : ''}${u.ms_linked == 1 ? ' <span class="pill pill-gold">Microsoft</span>' : ''}</td>
          <td>${u.active == 1 ? '<span class="pill pill-teal">Active</span>' : '<span class="pill pill-red">Inactive</span>'}</td>
          <td class="row-actions"><button class="btn btn-outline btn-sm" data-edit="${u.id}">Edit</button><button class="btn btn-danger btn-sm" data-del="${u.id}">Delete</button></td></tr>
        `).join('')}</tbody></table>
      </div>
      <p style="font-size:.72rem;color:var(--text-muted);margin-top:10px">To let someone sign in with "Sign in with Microsoft", add them here with their work email — their first Microsoft sign-in will link automatically.</p>`;

    document.getElementById('addUserBtn').addEventListener('click', () => openUserModal());
    contentEl.querySelectorAll('[data-edit]').forEach((btn) => btn.addEventListener('click', () => openUserModal(users.find((u) => u.id == btn.dataset.edit))));
    contentEl.querySelectorAll('[data-del]').forEach((btn) => btn.addEventListener('click', async () => {
      if (!confirm('Delete this user?')) return;
      try { await del('users.php?id=' + btn.dataset.del); toast('User deleted.'); renderUsers(); }
      catch (err) { alert(err.message); }
    }));
  }

  function openUserModal(u) {
    u = u || {};
    openModal(`
      <h2>${u.id ? 'Edit User' : 'Add User'}</h2>
      <form id="userForm">
        <div class="field"><label>Name *</label><input name="name" required value="${esc(u.name)}"/></div>
        <div class="field-row">
          <div class="field"><label>Username (local sign-in)</label><input name="username" value="${esc(u.username)}" ${u.id ? 'disabled' : ''}/></div>
          <div class="field"><label>Email (Microsoft sign-in)</label><input type="email" name="email" value="${esc(u.email)}"/></div>
        </div>
        <div class="field"><label>${u.id ? 'Reset Password (leave blank to keep current)' : 'Password (required if setting a username)'}</label><input type="password" name="password" autocomplete="new-password"/></div>
        <div class="field-row">
          <div class="field"><label>Role</label><select name="role">${['user', 'admin'].map((r) => `<option${r === (u.role || 'user') ? ' selected' : ''}>${r}</option>`).join('')}</select></div>
          <div class="field"><label><input type="checkbox" name="active" value="1" ${u.active == 0 ? '' : 'checked'} style="width:auto;margin-right:6px"/>Active</label></div>
        </div>
        <div class="modal-actions"><button type="button" class="btn btn-outline" id="cancelBtn">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
      </form>`);
    document.getElementById('cancelBtn').addEventListener('click', closeModal);
    document.getElementById('userForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const body = Object.fromEntries(fd.entries());
      body.active = fd.has('active');
      try {
        if (u.id) await put('users.php?id=' + u.id, body); else await post('users.php', body);
        closeModal(); toast('User saved.'); renderUsers();
      } catch (err) { alert(err.message); }
    });
  }

  // ================= Settings =================
  async function renderSettings() {
    const s = await get('settings.php');
    const isAdmin = window.CRM.currentUser.role === 'admin';
    contentEl.innerHTML = `
      <div class="card" style="max-width:520px">
        <h3>Branding</h3>
        <form id="settingsForm">
          <div class="field"><label>Company Name</label><input name="company_name" value="${esc(s.company_name)}" ${isAdmin ? '' : 'disabled'}/></div>
          <div class="field"><label>Tagline</label><input name="tagline" value="${esc(s.tagline)}" ${isAdmin ? '' : 'disabled'}/></div>
          <div class="field-row">
            <div class="field"><label>Primary Color</label><input type="color" name="primary_color" value="${s.primary_color || '#142850'}" ${isAdmin ? '' : 'disabled'}/></div>
            <div class="field"><label>Accent Color</label><input type="color" name="accent_color" value="${s.accent_color || '#16C79A'}" ${isAdmin ? '' : 'disabled'}/></div>
          </div>
          ${isAdmin ? '<div class="modal-actions" style="justify-content:flex-start"><button type="submit" class="btn btn-primary">Save Settings</button></div>' : '<p style="font-size:.72rem;color:var(--text-muted)">Only administrators can change branding settings.</p>'}
        </form>
      </div>`;
    if (isAdmin) {
      document.getElementById('settingsForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(e.target).entries());
        try {
          await put('settings.php', body);
          document.documentElement.style.setProperty('--brand-primary', body.primary_color);
          document.documentElement.style.setProperty('--brand-accent', body.accent_color);
          toast('Settings saved. Refresh to see branding text changes everywhere.');
        } catch (err) { alert(err.message); }
      });
    }
  }

  route();
})();
