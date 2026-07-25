/* Elevate SJC CRM — front-end SPA. Talks to the PHP/MySQL API under api/.
   No build step, no framework: a small hash router + template strings. */
(function () {
  'use strict';

  const DEAL_STAGES = ['New Enquiry', 'Needs Assessment', 'Proposal Sent', 'Negotiation', 'Won', 'Lost'];
  const PROGRAM_CATEGORIES = ['Leadership Development', 'Technical Skills', 'Soft Skills', 'Data Analytics & Visualisation', 'E-Learning'];
  const PROPOSAL_STATUSES = ['draft', 'sent', 'accepted', 'declined'];
  const INVOICE_STATUSES = ['draft', 'sent', 'paid', 'overdue', 'cancelled'];
  const EXPENSE_CATEGORIES = ['Travel', 'Venue & Catering', 'Materials', 'Software & Subscriptions', 'Subsistence', 'Other'];
  const EXPENSE_PAYMENT_METHODS = ['Card', 'Cash', 'EFT', 'Other'];
  const WEEKDAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const PAGE_TITLES = { dashboard: 'Dashboard', contacts: 'Contacts', deals: 'Pipeline', calendar: 'Calendar', tasks: 'Tasks', proposals: 'Proposals', invoices: 'Invoicing', expenses: 'Expenses', programs: 'Programs', users: 'Users', settings: 'Settings' };

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
    // FormData sets its own multipart Content-Type (with boundary) — letting
    // the default 'application/json' through here would break file uploads.
    const isFormData = typeof FormData !== 'undefined' && opts.body instanceof FormData;
    const headers = isFormData ? Object.assign({}, opts.headers || {}) : Object.assign({ 'Content-Type': 'application/json' }, opts.headers || {});
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
  function statusPillClass(status) {
    return { sent: 'gold', accepted: 'teal', paid: 'teal', reimbursed: 'teal', approved: 'teal', declined: 'red', overdue: 'red', cancelled: 'red', draft: 'navy', pending: 'gold' }[status] || 'navy';
  }
  function ymd(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  // ---------------- shared line-item editor (proposals + invoices) ----------------
  function renderLineItemRows(items) {
    return items.map((it) => `
      <tr class="li-row">
        <td><input class="li-desc" value="${esc(it.description)}" placeholder="Description"/></td>
        <td><input class="li-qty" type="number" step="0.01" min="0" value="${it.quantity ?? 1}"/></td>
        <td><input class="li-price" type="number" step="0.01" min="0" value="${it.unit_price ?? 0}"/></td>
        <td class="li-total">R 0.00</td>
        <td><button type="button" class="btn btn-danger btn-sm li-remove">×</button></td>
      </tr>`).join('');
  }
  function addLineItemRow(tableBody, item) {
    tableBody.insertAdjacentHTML('beforeend', renderLineItemRows([item || {}]));
  }
  function getLineItems(tableBody) {
    return Array.from(tableBody.querySelectorAll('.li-row')).map((tr) => ({
      description: tr.querySelector('.li-desc').value.trim(),
      quantity: parseFloat(tr.querySelector('.li-qty').value) || 0,
      unit_price: parseFloat(tr.querySelector('.li-price').value) || 0,
    })).filter((i) => i.description);
  }
  /** Wires live recalculation; returns a `recalc()` function to call after adding/removing rows. */
  function wireLineItemsTable(tableBody, subtotalEl, taxRateInput, taxAmountEl, grandTotalEl) {
    function recalc() {
      let subtotal = 0;
      tableBody.querySelectorAll('.li-row').forEach((tr) => {
        const qty = parseFloat(tr.querySelector('.li-qty').value) || 0;
        const price = parseFloat(tr.querySelector('.li-price').value) || 0;
        const lineTotal = qty * price;
        subtotal += lineTotal;
        tr.querySelector('.li-total').textContent = money(lineTotal);
      });
      if (subtotalEl) subtotalEl.textContent = money(subtotal);
      if (taxRateInput && taxAmountEl && grandTotalEl) {
        const rate = parseFloat(taxRateInput.value) || 0;
        const tax = subtotal * rate / 100;
        taxAmountEl.textContent = money(tax);
        grandTotalEl.textContent = money(subtotal + tax);
      }
    }
    tableBody.addEventListener('input', recalc);
    tableBody.addEventListener('click', (e) => {
      if (e.target.classList.contains('li-remove')) { e.target.closest('.li-row').remove(); recalc(); }
    });
    if (taxRateInput) taxRateInput.addEventListener('input', recalc);
    recalc();
    return recalc;
  }

  // ---------------- router ----------------
  const routes = { dashboard: renderDashboard, contacts: renderContacts, deals: renderDeals, calendar: renderCalendar, tasks: renderTasks, proposals: renderProposals, invoices: renderInvoices, expenses: renderExpenses, programs: renderPrograms, users: renderUsers, settings: renderSettings };

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
    const [s, calData] = await Promise.all([get('settings.php'), get('calendar_connections.php')]);
    const isAdmin = window.CRM.currentUser.role === 'admin';
    const dis = isAdmin ? '' : 'disabled';
    const connections = calData.connections;
    contentEl.innerHTML = `
      <div class="card">
        <h3>Logo</h3>
        <p style="font-size:.78rem;color:var(--text-muted);margin-bottom:10px">Shown in the sidebar, the login screen, and on printed proposals/invoices. JPEG, PNG or WebP, up to 3MB.</p>
        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
          <div id="logoPreviewBox" style="width:64px;height:64px;border-radius:10px;background:#fff;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
            ${s.company_logo ? `<img src="${esc(s.company_logo)}?t=${Date.now()}" alt="Logo" style="max-width:100%;max-height:100%;object-fit:contain"/>` : `<span style="font-weight:800;color:var(--brand-primary);font-size:1.3rem">${esc((s.company_name || 'E')[0].toUpperCase())}</span>`}
          </div>
          ${isAdmin ? `
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <label class="btn btn-outline btn-sm" style="cursor:pointer">Upload logo<input type="file" id="logoFileInput" accept="image/png,image/jpeg,image/webp" style="display:none"/></label>
            ${s.company_logo ? '<button type="button" class="btn btn-outline btn-sm" id="removeLogoBtn">Remove logo</button>' : ''}
          </div>` : ''}
        </div>
      </div>
      <form id="settingsForm">
        <div class="two-col">
          <div class="card">
            <h3>Branding Colours</h3>
            <div class="field"><label>Company Name</label><input name="company_name" value="${esc(s.company_name)}" ${dis}/></div>
            <div class="field"><label>Tagline</label><input name="tagline" value="${esc(s.tagline)}" ${dis}/></div>
            <div class="field-row">
              <div class="field"><label>Primary Color</label><input type="color" name="primary_color" value="${s.primary_color || '#142850'}" ${dis}/></div>
              <div class="field"><label>Accent Color</label><input type="color" name="accent_color" value="${s.accent_color || '#16C79A'}" ${dis}/></div>
            </div>
            <div class="field"><label>Secondary Accent Color</label><input type="color" name="accent_color_2" value="${s.accent_color_2 || '#F4A300'}" ${dis}/></div>
          </div>
          <div class="card">
            <h3>Company Details (used on proposal/invoice letterheads)</h3>
            <div class="field"><label>Address</label><input name="company_address" value="${esc(s.company_address)}" ${dis}/></div>
            <div class="field-row">
              <div class="field"><label>Phone</label><input name="company_phone" value="${esc(s.company_phone)}" ${dis}/></div>
              <div class="field"><label>Email</label><input type="email" name="company_email" value="${esc(s.company_email)}" ${dis}/></div>
            </div>
            <div class="field-row">
              <div class="field"><label>VAT Number</label><input name="vat_number" value="${esc(s.vat_number)}" ${dis}/></div>
              <div class="field"><label>Default Tax Rate (%)</label><input type="number" step="0.01" name="default_tax_rate" value="${s.default_tax_rate || 15}" ${dis}/></div>
            </div>
          </div>
          <div class="card">
            <h3>Banking Details (shown on invoices)</h3>
            <div class="field"><label>Account Holder</label><input name="bank_account_holder" value="${esc(s.bank_account_holder)}" ${dis}/></div>
            <div class="field"><label>Bank Name</label><input name="bank_name" value="${esc(s.bank_name)}" ${dis}/></div>
            <div class="field-row">
              <div class="field"><label>Account Number</label><input name="bank_account_number" value="${esc(s.bank_account_number)}" ${dis}/></div>
              <div class="field"><label>Branch Code</label><input name="bank_branch_code" value="${esc(s.bank_branch_code)}" ${dis}/></div>
            </div>
          </div>
          <div class="card">
            <h3>Quote / Proposal / Invoice Template</h3>
            <div class="field">
              <label>Layout Style</label>
              <select name="template_style" ${dis}>
                <option value="classic" ${(s.template_style || 'classic') === 'classic' ? 'selected' : ''}>Classic — bordered letterhead</option>
                <option value="modern" ${s.template_style === 'modern' ? 'selected' : ''}>Modern — bold colour band</option>
                <option value="minimal" ${s.template_style === 'minimal' ? 'selected' : ''}>Minimal — understated rule line</option>
              </select>
            </div>
            <div class="field"><label>Proposal Footer Note</label><textarea name="proposal_footer_note" ${dis} placeholder="e.g. Thank you for the opportunity to work with you.">${esc(s.proposal_footer_note)}</textarea></div>
            <div class="field"><label>Invoice Footer Note</label><textarea name="invoice_footer_note" ${dis} placeholder="e.g. Payment due within 30 days. Thank you for your business.">${esc(s.invoice_footer_note)}</textarea></div>
          </div>
        </div>
        ${isAdmin ? '<div class="modal-actions" style="justify-content:flex-start;margin-top:6px"><button type="submit" class="btn btn-primary">Save Settings</button></div>' : '<p style="font-size:.72rem;color:var(--text-muted);margin-top:10px">Only administrators can change these settings.</p>'}
      </form>
      <div class="card" style="margin-top:16px">
        <h3>Connected Calendars</h3>
        <p style="font-size:.78rem;color:var(--text-muted);margin-bottom:10px">Link a Microsoft 365 calendar to sync it two-way with the CRM Calendar. Everyone's connected calendars show up together there.</p>
        ${!calData.sync_enabled ? '<p style="font-size:.78rem;color:var(--text-muted)">Microsoft calendar sync is not configured on this server yet.</p>' : `
        <table><thead><tr><th>Owner</th><th>Account</th><th>Colour</th><th>Last synced</th><th></th></tr></thead>
        <tbody>${connections.length ? connections.map((c) => `
          <tr>
            <td>${esc(c.owner_name)}${c.is_mine ? ' (you)' : ''}</td>
            <td>${esc(c.display_name || c.ms_email || '—')}${c.last_sync_error ? `<div style="font-size:.68rem;color:var(--danger)">⚠ ${esc(c.last_sync_error)}</div>` : ''}</td>
            <td>${c.is_mine ? `<input type="color" class="calColorInput" data-id="${c.id}" value="${esc(c.color)}"/>` : `<span class="cal-dot" style="background:${esc(c.color)}"></span>`}</td>
            <td style="font-size:.75rem;color:var(--text-muted)">${c.last_synced_at ? new Date(c.last_synced_at + 'Z').toLocaleString('en-ZA') : 'Never'}</td>
            <td>${c.is_mine ? `<button class="btn btn-outline btn-sm calDisconnectBtn" data-id="${c.id}">Disconnect</button>` : ''}</td>
          </tr>`).join('') : '<tr><td colspan="5" style="color:var(--text-muted)">No calendars connected yet.</td></tr>'}
        </tbody></table>
        <div class="modal-actions" style="justify-content:flex-start;margin-top:12px">
          <a class="btn btn-primary" href="auth/ms_calendar_connect.php">+ Connect Microsoft Calendar</a>
        </div>`}
      </div>`;
    if (isAdmin) {
      document.getElementById('settingsForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(e.target).entries());
        try {
          await put('settings.php', body);
          document.documentElement.style.setProperty('--brand-primary', body.primary_color);
          document.documentElement.style.setProperty('--brand-accent', body.accent_color);
          document.documentElement.style.setProperty('--brand-gold', body.accent_color_2);
          toast('Settings saved. Refresh to see branding text changes everywhere.');
        } catch (err) { alert(err.message); }
      });
      const logoInput = document.getElementById('logoFileInput');
      if (logoInput) logoInput.addEventListener('change', async () => {
        const file = logoInput.files[0];
        if (!file) return;
        const form = new FormData();
        form.append('logo', file);
        try {
          await api('settings_logo.php', { method: 'POST', body: form });
          toast('Logo updated.'); renderSettings();
        } catch (err) { alert(err.message); }
      });
      const removeLogoBtn = document.getElementById('removeLogoBtn');
      if (removeLogoBtn) removeLogoBtn.addEventListener('click', async () => {
        if (!confirm('Remove the current logo?')) return;
        try { await del('settings_logo.php'); toast('Logo removed.'); renderSettings(); }
        catch (err) { alert(err.message); }
      });
    }
    contentEl.querySelectorAll('.calColorInput').forEach((input) => input.addEventListener('change', async () => {
      try { await api('calendar_connections.php?id=' + input.dataset.id, { method: 'PATCH', body: JSON.stringify({ color: input.value }) }); toast('Colour updated.'); }
      catch (err) { alert(err.message); }
    }));
    contentEl.querySelectorAll('.calDisconnectBtn').forEach((btn) => btn.addEventListener('click', async () => {
      if (!confirm('Disconnect this calendar? Events already synced will stay in the CRM but stop updating.')) return;
      try { await del('calendar_connections.php?id=' + btn.dataset.id); toast('Calendar disconnected.'); renderSettings(); }
      catch (err) { alert(err.message); }
    }));
  }

  // ================= Calendar =================
  let calendarViewDate = null;
  const hiddenCalendars = new Set(); // 'local' and/or connection ids (as strings) currently hidden
  let calendarSyncing = false;

  function calendarLabel(conn) {
    return conn.display_name || conn.ms_email || conn.owner_name;
  }

  async function renderCalendar() {
    if (!calendarViewDate) calendarViewDate = new Date();
    const year = calendarViewDate.getFullYear();
    const month = calendarViewDate.getMonth();
    const firstOfMonth = new Date(year, month, 1);
    const lastOfMonth = new Date(year, month + 1, 0);
    const gridStart = new Date(firstOfMonth); gridStart.setDate(gridStart.getDate() - gridStart.getDay());
    const gridEnd = new Date(lastOfMonth); gridEnd.setDate(gridEnd.getDate() + (6 - gridEnd.getDay()));

    const [allEvents, contacts, deals, calData] = await Promise.all([
      get('calendar.php?start=' + ymd(gridStart) + '&end=' + ymd(gridEnd)),
      get('contacts.php'), get('deals.php'), get('calendar_connections.php'),
    ]);
    const connections = calData.connections;
    const myConnections = connections.filter((c) => c.is_mine);

    // Opportunistically refresh from Microsoft once per view-load, for whichever
    // of the current user's own connected calendars need it — cheap no-op if
    // there's nothing to sync, and keeps "must show all calendars" reasonably live
    // without requiring everyone to click Sync manually.
    let events = allEvents;
    if (calData.sync_enabled && myConnections.length && !calendarSyncing) {
      calendarSyncing = true;
      try {
        await post('calendar_connections.php?action=sync', {});
        events = await get('calendar.php?start=' + ymd(gridStart) + '&end=' + ymd(gridEnd));
      } catch (e) { /* best-effort — stale data is still shown */ }
      calendarSyncing = false;
    }

    const visibleEvents = events.filter((e) => !hiddenCalendars.has(e.connection_id ? String(e.connection_id) : 'local'));
    const eventsByDay = {};
    visibleEvents.forEach((e) => { const day = e.start_datetime.slice(0, 10); (eventsByDay[day] = eventsByDay[day] || []).push(e); });

    const monthLabel = firstOfMonth.toLocaleDateString('en-ZA', { month: 'long', year: 'numeric' });
    const todayStr = ymd(new Date());

    let cells = '';
    for (let d = new Date(gridStart); d <= gridEnd; d.setDate(d.getDate() + 1)) {
      const dayStr = ymd(d);
      const inMonth = d.getMonth() === month;
      const dayEvents = eventsByDay[dayStr] || [];
      cells += `<div class="cal-cell${inMonth ? '' : ' outside'}${dayStr === todayStr ? ' today' : ''}" data-date="${dayStr}">
        <div class="cal-daynum">${d.getDate()}</div>
        ${dayEvents.slice(0, 3).map((e) => `<div class="cal-event" data-id="${e.id}"${e.calendar_color ? ` style="border-left:4px solid ${esc(e.calendar_color)}"` : ''}>${esc(e.title)}</div>`).join('')}
        ${dayEvents.length > 3 ? `<div class="cal-more">+${dayEvents.length - 3} more</div>` : ''}
      </div>`;
    }

    const legendChips = [
      `<label class="cal-legend-chip"><input type="checkbox" data-cal="local" ${hiddenCalendars.has('local') ? '' : 'checked'}/><span class="cal-dot" style="background:var(--brand-accent)"></span>Local only</label>`,
      ...connections.map((c) => `<label class="cal-legend-chip"><input type="checkbox" data-cal="${c.id}" ${hiddenCalendars.has(String(c.id)) ? '' : 'checked'}/><span class="cal-dot" style="background:${esc(c.color)}"></span>${esc(calendarLabel(c))}${c.last_sync_error ? ' ⚠' : ''}</label>`),
    ].join('');

    contentEl.innerHTML = `
      <div class="toolbar">
        <div style="display:flex;align-items:center;gap:10px">
          <button class="btn btn-outline btn-sm" id="calPrev">‹</button>
          <strong style="color:var(--brand-primary);min-width:140px;display:inline-block">${monthLabel}</strong>
          <button class="btn btn-outline btn-sm" id="calNext">›</button>
          <button class="btn btn-outline btn-sm" id="calToday">Today</button>
        </div>
        <div style="display:flex;gap:8px">
          ${calData.sync_enabled && myConnections.length ? '<button class="btn btn-outline btn-sm" id="calSyncBtn">⟳ Sync now</button>' : ''}
          <button class="btn btn-primary" id="addEventBtn">+ Add Event</button>
        </div>
      </div>
      <div class="cal-legend">${legendChips}</div>
      <div class="card">
        <div class="cal-weekdays">${WEEKDAY_LABELS.map((d) => `<div>${d}</div>`).join('')}</div>
        <div class="cal-grid">${cells}</div>
      </div>`;

    document.getElementById('calPrev').addEventListener('click', () => { calendarViewDate = new Date(year, month - 1, 1); renderCalendar(); });
    document.getElementById('calNext').addEventListener('click', () => { calendarViewDate = new Date(year, month + 1, 1); renderCalendar(); });
    document.getElementById('calToday').addEventListener('click', () => { calendarViewDate = new Date(); renderCalendar(); });
    document.getElementById('addEventBtn').addEventListener('click', () => openEventModal(null, contacts, deals, myConnections, todayStr));
    const syncBtn = document.getElementById('calSyncBtn');
    if (syncBtn) syncBtn.addEventListener('click', async () => {
      toast('Syncing with Microsoft…');
      try {
        const r = await post('calendar_connections.php?action=sync', {});
        toast(r.errors.length ? `Synced with issues: ${r.errors[0]}` : `Synced (${r.pulled} pulled, ${r.pushed} pushed).`);
      } catch (err) { toast('Sync failed: ' + err.message); }
      renderCalendar();
    });

    contentEl.querySelectorAll('[data-cal]').forEach((box) => box.addEventListener('change', () => {
      const key = box.dataset.cal;
      if (box.checked) hiddenCalendars.delete(key); else hiddenCalendars.add(key);
      renderCalendar();
    }));
    contentEl.querySelectorAll('.cal-event').forEach((el) => el.addEventListener('click', (e) => {
      e.stopPropagation();
      openEventModal(events.find((x) => x.id == el.dataset.id), contacts, deals, myConnections);
    }));
    contentEl.querySelectorAll('.cal-cell').forEach((cell) => cell.addEventListener('click', () => openEventModal(null, contacts, deals, myConnections, cell.dataset.date)));
  }

  function openEventModal(ev, contacts, deals, myConnections, defaultDate) {
    ev = ev || {};
    const startVal = ev.start_datetime ? ev.start_datetime.replace(' ', 'T').slice(0, 16) : (defaultDate ? defaultDate + 'T09:00' : '');
    const endVal = ev.end_datetime ? ev.end_datetime.replace(' ', 'T').slice(0, 16) : '';
    const calendarField = ev.id
      ? (ev.connection_id ? `<div class="field"><label>Calendar</label><div style="padding:8px 0;font-size:.8rem"><span class="cal-dot" style="background:${esc(ev.calendar_color)}"></span> Synced with ${esc(ev.calendar_label || ev.calendar_email)} — can't be moved to another calendar.</div></div>` : '')
      : (myConnections.length ? `<div class="field"><label>Calendar</label><select name="connection_id"><option value="">Local only (this CRM)</option>${myConnections.map((c) => `<option value="${c.id}">${esc(calendarLabel(c))}</option>`).join('')}</select></div>` : '');
    openModal(`
      <h2>${ev.id ? 'Edit Event' : 'Add Event'}</h2>
      <form id="eventForm">
        <div class="field"><label>Title *</label><input name="title" required value="${esc(ev.title)}"/></div>
        <div class="field-row">
          <div class="field"><label>Start *</label><input type="datetime-local" name="start_datetime" required value="${startVal}"/></div>
          <div class="field"><label>End</label><input type="datetime-local" name="end_datetime" value="${endVal}"/></div>
        </div>
        <div class="field"><label>Location</label><input name="location" value="${esc(ev.location)}"/></div>
        <div class="field-row">
          <div class="field"><label>Contact</label><select name="contact_id">${optionList(contacts, 'id', 'name', ev.contact_id)}</select></div>
          <div class="field"><label>Deal</label><select name="deal_id">${optionList(deals, 'id', 'title', ev.deal_id)}</select></div>
        </div>
        ${calendarField}
        <div class="field"><label>Description</label><textarea name="description">${esc(ev.description)}</textarea></div>
        <div class="modal-actions">
          ${ev.id ? '<button type="button" class="btn btn-danger" id="deleteEventBtn" style="margin-right:auto">Delete</button>' : ''}
          <button type="button" class="btn btn-outline" id="cancelBtn">Cancel</button><button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>`);
    document.getElementById('cancelBtn').addEventListener('click', closeModal);
    if (ev.id) document.getElementById('deleteEventBtn').addEventListener('click', async () => {
      if (!confirm('Delete this event?')) return;
      await del('calendar.php?id=' + ev.id); closeModal(); toast('Event deleted.'); renderCalendar();
    });
    document.getElementById('eventForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target).entries());
      body.start_datetime = body.start_datetime.replace('T', ' ');
      if (body.end_datetime) body.end_datetime = body.end_datetime.replace('T', ' ');
      try {
        const r = ev.id ? await put('calendar.php?id=' + ev.id, body) : await post('calendar.php', body);
        closeModal(); toast(r.warning || 'Event saved.'); renderCalendar();
      } catch (err) { alert(err.message); }
    });
  }

  // ================= Proposals =================
  async function renderProposals() {
    const [proposals, contacts, deals] = await Promise.all([get('proposals.php'), get('contacts.php'), get('deals.php')]);
    contentEl.innerHTML = `
      <div class="toolbar"><div></div><button class="btn btn-primary" id="addProposalBtn">+ New Proposal</button></div>
      <div class="card table-wrap">
        <table><thead><tr><th>Number</th><th>Title</th><th>Contact</th><th>Status</th><th>Total</th><th>Valid Until</th><th></th></tr></thead>
        <tbody>${proposals.length ? proposals.map((p) => `
          <tr><td><strong>${esc(p.proposal_number)}</strong></td><td>${esc(p.title)}</td>
          <td>${esc(p.contact_name) || '—'}</td>
          <td><span class="pill pill-${statusPillClass(p.status)}">${esc(p.status)}</span></td>
          <td>${money(p.total)}</td><td>${fmtDate(p.valid_until)}</td>
          <td class="row-actions">
            <a class="btn btn-outline btn-sm" href="proposal_print.php?id=${p.id}" target="_blank" rel="noopener">View</a>
            ${p.status === 'accepted' ? `<button class="btn btn-outline btn-sm" data-convert="${p.id}">To Invoice</button>` : ''}
            <button class="btn btn-outline btn-sm" data-edit="${p.id}">Edit</button>
            <button class="btn btn-danger btn-sm" data-del="${p.id}">Delete</button>
          </td></tr>
        `).join('') : `<tr><td colspan="7"><div class="empty">No proposals yet.</div></td></tr>`}</tbody></table>
      </div>`;

    document.getElementById('addProposalBtn').addEventListener('click', () => openProposalModal(null, contacts, deals));
    contentEl.querySelectorAll('[data-edit]').forEach((btn) => btn.addEventListener('click', async () => openProposalModal(await get('proposals.php?id=' + btn.dataset.edit), contacts, deals)));
    contentEl.querySelectorAll('[data-del]').forEach((btn) => btn.addEventListener('click', async () => {
      if (!confirm('Delete this proposal?')) return;
      await del('proposals.php?id=' + btn.dataset.del); toast('Proposal deleted.'); renderProposals();
    }));
    contentEl.querySelectorAll('[data-convert]').forEach((btn) => btn.addEventListener('click', async () => {
      if (!confirm('Create a draft invoice from this accepted proposal?')) return;
      try {
        const res = await post('invoices.php', { from_proposal_id: btn.dataset.convert });
        toast('Invoice ' + res.invoice_number + ' created.');
        location.hash = '#/invoices';
      } catch (err) { alert(err.message); }
    }));
  }

  function openProposalModal(p, contacts, deals) {
    p = p || {};
    const items = p.items && p.items.length ? p.items : [{}];
    openModal(`
      <h2 style="margin-bottom:4px">${p.id ? 'Edit Proposal' : 'New Proposal'}</h2>
      ${p.id ? `<div style="font-size:.72rem;color:var(--text-muted);margin-bottom:12px">${esc(p.proposal_number)}</div>` : ''}
      <form id="proposalForm">
        <div class="field"><label>Title *</label><input name="title" required value="${esc(p.title)}"/></div>
        <div class="field-row">
          <div class="field"><label>Contact</label><select name="contact_id">${optionList(contacts, 'id', 'name', p.contact_id)}</select></div>
          <div class="field"><label>Deal</label><select name="deal_id">${optionList(deals, 'id', 'title', p.deal_id)}</select></div>
        </div>
        <div class="field-row">
          <div class="field"><label>Status</label><select name="status">${PROPOSAL_STATUSES.map((s) => `<option${s === (p.status || 'draft') ? ' selected' : ''}>${s}</option>`).join('')}</select></div>
          <div class="field"><label>Valid Until</label><input type="date" name="valid_until" value="${p.valid_until || ''}"/></div>
        </div>
        <div class="field"><label>Intro / Cover Note</label><textarea name="intro_text">${esc(p.intro_text)}</textarea></div>
        <div class="field"><label>Line Items</label>
          <div class="table-wrap"><table class="li-table"><thead><tr><th>Description</th><th style="width:70px">Qty</th><th style="width:110px">Unit Price</th><th style="width:90px">Total</th><th></th></tr></thead>
          <tbody id="lineItems">${renderLineItemRows(items)}</tbody></table></div>
          <button type="button" class="btn btn-outline btn-sm" id="addLineBtn" style="margin-top:8px">+ Add Line</button>
          <div style="text-align:right;margin-top:8px;font-weight:800;color:var(--brand-primary)">Total: <span id="docSubtotal">R 0.00</span></div>
        </div>
        <div class="modal-actions"><button type="button" class="btn btn-outline" id="cancelBtn">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
      </form>`);

    const table = document.getElementById('lineItems');
    const recalc = wireLineItemsTable(table, document.getElementById('docSubtotal'));
    document.getElementById('addLineBtn').addEventListener('click', () => { addLineItemRow(table, {}); recalc(); });
    document.getElementById('cancelBtn').addEventListener('click', closeModal);
    document.getElementById('proposalForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target).entries());
      body.items = getLineItems(table);
      try {
        if (p.id) await put('proposals.php?id=' + p.id, body); else await post('proposals.php', body);
        closeModal(); toast('Proposal saved.'); renderProposals();
      } catch (err) { alert(err.message); }
    });
  }

  // ================= Invoices =================
  async function renderInvoices() {
    const [invoices, contacts, deals] = await Promise.all([get('invoices.php'), get('contacts.php'), get('deals.php')]);
    contentEl.innerHTML = `
      <div class="toolbar"><div></div><button class="btn btn-primary" id="addInvoiceBtn">+ New Invoice</button></div>
      <div class="card table-wrap">
        <table><thead><tr><th>Number</th><th>Contact</th><th>Status</th><th>Total (incl. VAT)</th><th>Due</th><th></th></tr></thead>
        <tbody>${invoices.length ? invoices.map((inv) => `
          <tr><td><strong>${esc(inv.invoice_number)}</strong></td>
          <td>${esc(inv.contact_name) || '—'}</td>
          <td><span class="pill pill-${statusPillClass(inv.is_overdue ? 'overdue' : inv.status)}">${inv.is_overdue ? 'overdue' : esc(inv.status)}</span></td>
          <td>${money(inv.total)}</td><td>${fmtDate(inv.due_date)}</td>
          <td class="row-actions">
            <a class="btn btn-outline btn-sm" href="invoice_print.php?id=${inv.id}" target="_blank" rel="noopener">View</a>
            ${inv.status !== 'paid' ? `<button class="btn btn-outline btn-sm" data-paid="${inv.id}">Mark Paid</button>` : ''}
            <button class="btn btn-outline btn-sm" data-edit="${inv.id}">Edit</button>
            <button class="btn btn-danger btn-sm" data-del="${inv.id}">Delete</button>
          </td></tr>
        `).join('') : `<tr><td colspan="6"><div class="empty">No invoices yet.</div></td></tr>`}</tbody></table>
      </div>`;

    document.getElementById('addInvoiceBtn').addEventListener('click', () => openInvoiceModal(null, contacts, deals));
    contentEl.querySelectorAll('[data-edit]').forEach((btn) => btn.addEventListener('click', async () => openInvoiceModal(await get('invoices.php?id=' + btn.dataset.edit), contacts, deals)));
    contentEl.querySelectorAll('[data-del]').forEach((btn) => btn.addEventListener('click', async () => {
      if (!confirm('Delete this invoice?')) return;
      await del('invoices.php?id=' + btn.dataset.del); toast('Invoice deleted.'); renderInvoices();
    }));
    contentEl.querySelectorAll('[data-paid]').forEach((btn) => btn.addEventListener('click', async () => {
      await put('invoices.php?id=' + btn.dataset.paid, { status: 'paid' });
      toast('Invoice marked as paid.'); renderInvoices();
    }));
  }

  function openInvoiceModal(inv, contacts, deals) {
    inv = inv || {};
    const items = inv.items && inv.items.length ? inv.items : [{}];
    openModal(`
      <h2 style="margin-bottom:4px">${inv.id ? 'Edit Invoice' : 'New Invoice'}</h2>
      ${inv.id ? `<div style="font-size:.72rem;color:var(--text-muted);margin-bottom:12px">${esc(inv.invoice_number)}</div>` : ''}
      <form id="invoiceForm">
        <div class="field-row">
          <div class="field"><label>Contact</label><select name="contact_id">${optionList(contacts, 'id', 'name', inv.contact_id)}</select></div>
          <div class="field"><label>Deal</label><select name="deal_id">${optionList(deals, 'id', 'title', inv.deal_id)}</select></div>
        </div>
        <div class="field-row">
          <div class="field"><label>Issue Date</label><input type="date" name="issue_date" value="${inv.issue_date || ymd(new Date())}"/></div>
          <div class="field"><label>Due Date</label><input type="date" name="due_date" value="${inv.due_date || ''}"/></div>
        </div>
        <div class="field-row">
          <div class="field"><label>Status</label><select name="status">${INVOICE_STATUSES.map((s) => `<option${s === (inv.status || 'draft') ? ' selected' : ''}>${s}</option>`).join('')}</select></div>
          <div class="field"><label>Tax Rate (%)</label><input type="number" step="0.01" id="taxRateInput" name="tax_rate" value="${inv.tax_rate ?? 15}"/></div>
        </div>
        <div class="field"><label>Notes</label><textarea name="notes">${esc(inv.notes)}</textarea></div>
        <div class="field"><label>Line Items</label>
          <div class="table-wrap"><table class="li-table"><thead><tr><th>Description</th><th style="width:70px">Qty</th><th style="width:110px">Unit Price</th><th style="width:90px">Total</th><th></th></tr></thead>
          <tbody id="lineItems">${renderLineItemRows(items)}</tbody></table></div>
          <button type="button" class="btn btn-outline btn-sm" id="addLineBtn" style="margin-top:8px">+ Add Line</button>
          <div style="text-align:right;margin-top:8px;font-size:.82rem">
            <div>Subtotal: <span id="docSubtotal">R 0.00</span></div>
            <div>VAT: <span id="docTax">R 0.00</span></div>
            <div style="font-weight:800;color:var(--brand-primary);font-size:1rem">Total: <span id="docGrandTotal">R 0.00</span></div>
          </div>
        </div>
        <div class="modal-actions"><button type="button" class="btn btn-outline" id="cancelBtn">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
      </form>`);

    const table = document.getElementById('lineItems');
    const recalc = wireLineItemsTable(table, document.getElementById('docSubtotal'), document.getElementById('taxRateInput'), document.getElementById('docTax'), document.getElementById('docGrandTotal'));
    document.getElementById('addLineBtn').addEventListener('click', () => { addLineItemRow(table, {}); recalc(); });
    document.getElementById('cancelBtn').addEventListener('click', closeModal);
    document.getElementById('invoiceForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target).entries());
      body.items = getLineItems(table);
      try {
        if (inv.id) await put('invoices.php?id=' + inv.id, body); else await post('invoices.php', body);
        closeModal(); toast('Invoice saved.'); renderInvoices();
      } catch (err) { alert(err.message); }
    });
  }

  // ================= Expenses (incl. "Scan a Slip" receipt capture) =================
  async function renderExpenses(filter) {
    filter = filter || 'all';
    const expenses = await get('expenses.php' + (filter !== 'all' ? '?status=' + filter : ''));
    contentEl.innerHTML = `
      <div class="toolbar">
        <select class="filter" id="expenseFilter">
          <option value="all"${filter === 'all' ? ' selected' : ''}>All Expenses</option>
          <option value="pending"${filter === 'pending' ? ' selected' : ''}>Pending</option>
          <option value="approved"${filter === 'approved' ? ' selected' : ''}>Approved</option>
          <option value="reimbursed"${filter === 'reimbursed' ? ' selected' : ''}>Reimbursed</option>
        </select>
        <div style="display:flex;gap:8px">
          <button class="btn btn-outline" id="scanSlipBtn">📷 Scan a Slip</button>
          <button class="btn btn-primary" id="addExpenseBtn">+ Add Expense</button>
        </div>
      </div>
      <div class="card table-wrap">
        <table><thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Vendor</th><th>Amount</th><th>Status</th><th>Receipt</th><th></th></tr></thead>
        <tbody>${expenses.length ? expenses.map((ex) => `
          <tr><td>${fmtDate(ex.expense_date)}</td><td>${esc(ex.description)}</td><td><span class="pill pill-navy">${esc(ex.category)}</span></td>
          <td>${esc(ex.vendor) || '—'}</td><td>${money(ex.amount)}</td>
          <td><span class="pill pill-${statusPillClass(ex.status)}">${esc(ex.status)}</span></td>
          <td>${ex.receipt_path ? `<a href="download_receipt.php?expense_id=${ex.id}" target="_blank" rel="noopener">View</a>` : '—'}</td>
          <td class="row-actions">
            ${window.CRM.currentUser.role === 'admin' && ex.status === 'pending' ? `<button class="btn btn-outline btn-sm" data-approve="${ex.id}">Approve</button>` : ''}
            ${window.CRM.currentUser.role === 'admin' && ex.status === 'approved' ? `<button class="btn btn-outline btn-sm" data-reimburse="${ex.id}">Reimburse</button>` : ''}
            <button class="btn btn-outline btn-sm" data-edit="${ex.id}">Edit</button>
            <button class="btn btn-danger btn-sm" data-del="${ex.id}">Delete</button>
          </td></tr>
        `).join('') : `<tr><td colspan="8"><div class="empty">No expenses in this view.</div></td></tr>`}</tbody></table>
      </div>`;

    document.getElementById('expenseFilter').addEventListener('change', (e) => renderExpenses(e.target.value));
    document.getElementById('addExpenseBtn').addEventListener('click', () => openExpenseModal(null, null, filter));
    document.getElementById('scanSlipBtn').addEventListener('click', () => openScanSlipModal(filter));
    contentEl.querySelectorAll('[data-edit]').forEach((btn) => btn.addEventListener('click', () => openExpenseModal(expenses.find((x) => x.id == btn.dataset.edit), null, filter)));
    contentEl.querySelectorAll('[data-del]').forEach((btn) => btn.addEventListener('click', async () => {
      if (!confirm('Delete this expense?')) return;
      try { await del('expenses.php?id=' + btn.dataset.del); toast('Expense deleted.'); renderExpenses(filter); }
      catch (err) { alert(err.message); }
    }));
    contentEl.querySelectorAll('[data-approve]').forEach((btn) => btn.addEventListener('click', async () => {
      await put('expenses.php?id=' + btn.dataset.approve, { status: 'approved' }); toast('Expense approved.'); renderExpenses(filter);
    }));
    contentEl.querySelectorAll('[data-reimburse]').forEach((btn) => btn.addEventListener('click', async () => {
      await put('expenses.php?id=' + btn.dataset.reimburse, { status: 'reimbursed' }); toast('Expense marked reimbursed.'); renderExpenses(filter);
    }));
  }

  /** Camera-capture flow: pick/photograph a slip, upload it, run best-effort
   *  OCR, then hand off to the normal (editable) expense form pre-filled
   *  with whatever was recognised. */
  function openScanSlipModal(filter) {
    openModal(`
      <h2>Scan a Slip</h2>
      <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:16px">Take a photo of a receipt with your phone, or choose an existing photo. We'll try to read the amount, date and vendor automatically — you can always correct them before saving.</p>
      <div class="field">
        <label>Receipt Photo</label>
        <input type="file" id="slipFile" accept="image/*" capture="environment"/>
      </div>
      <div id="scanStatus" style="font-size:.8rem;color:var(--text-muted)"></div>
      <div class="modal-actions"><button type="button" class="btn btn-outline" id="cancelBtn">Cancel</button></div>`);
    document.getElementById('cancelBtn').addEventListener('click', closeModal);
    document.getElementById('slipFile').addEventListener('change', async (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const statusEl = document.getElementById('scanStatus');
      statusEl.textContent = 'Reading receipt…';
      const fd = new FormData();
      fd.append('receipt', file);
      try {
        const res = await fetch(window.CRM.apiBase + 'expenses_upload.php', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'X-CSRF-Token': window.CRM.csrfToken },
          body: fd,
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Upload failed.');
        closeModal();
        openExpenseModal(null, data, filter);
      } catch (err) {
        statusEl.textContent = 'Error: ' + err.message;
      }
    });
  }

  function openExpenseModal(ex, scanResult, filter) {
    ex = ex || {};
    const guess = scanResult ? scanResult.guess : null;
    const receiptPath = ex.receipt_path || (scanResult ? scanResult.receipt_path : '');
    openModal(`
      <h2>${ex.id ? 'Edit Expense' : 'Add Expense'}</h2>
      ${scanResult && !scanResult.ocr_available ? '<p style="font-size:.72rem;color:var(--text-muted);margin-bottom:10px">Automatic text recognition isn\'t available on this server — the photo has been attached, please fill in the details below.</p>' : ''}
      ${scanResult && scanResult.ocr_available && !guess.raw_text ? '<p style="font-size:.72rem;color:var(--text-muted);margin-bottom:10px">Couldn\'t read this receipt automatically — the photo has been attached, please fill in the details below.</p>' : ''}
      <form id="expenseForm">
        <input type="hidden" name="receipt_path" value="${esc(receiptPath)}"/>
        <div class="field"><label>Description *</label><input name="description" required value="${esc(ex.description || (guess && guess.vendor ? 'Purchase at ' + guess.vendor : ''))}"/></div>
        <div class="field-row">
          <div class="field"><label>Amount (ZAR) *</label><input type="number" step="0.01" min="0.01" name="amount" required value="${ex.amount || (guess && guess.amount) || ''}"/></div>
          <div class="field"><label>Date *</label><input type="date" name="expense_date" required value="${ex.expense_date || (guess && guess.date) || ymd(new Date())}"/></div>
        </div>
        <div class="field-row">
          <div class="field"><label>Category</label><select name="category">${EXPENSE_CATEGORIES.map((c) => `<option${c === (ex.category || 'Other') ? ' selected' : ''}>${c}</option>`).join('')}</select></div>
          <div class="field"><label>Payment Method</label><select name="payment_method">${EXPENSE_PAYMENT_METHODS.map((m) => `<option${m === (ex.payment_method || 'Card') ? ' selected' : ''}>${m}</option>`).join('')}</select></div>
        </div>
        <div class="field"><label>Vendor</label><input name="vendor" value="${esc(ex.vendor || (guess && guess.vendor) || '')}"/></div>
        <div class="field"><label>Notes</label><textarea name="notes">${esc(ex.notes)}</textarea></div>
        ${receiptPath ? (ex.id
          ? `<div class="field"><label>Receipt</label><a href="download_receipt.php?expense_id=${ex.id}" target="_blank" rel="noopener">📎 View photo</a></div>`
          : `<div class="field"><label>Receipt</label><span style="font-size:.8rem;color:var(--text-muted)">📎 Photo attached — viewable once saved</span></div>`
        ) : ''}
        <div class="modal-actions"><button type="button" class="btn btn-outline" id="cancelBtn">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
      </form>`);
    document.getElementById('cancelBtn').addEventListener('click', closeModal);
    document.getElementById('expenseForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target).entries());
      try {
        if (ex.id) await put('expenses.php?id=' + ex.id, body); else await post('expenses.php', body);
        closeModal(); toast('Expense saved.'); renderExpenses(filter);
      } catch (err) { alert(err.message); }
    });
  }

  (function handleMsCalendarRedirect() {
    const params = new URLSearchParams(location.search);
    if (params.has('ms_cal_connected')) toast('Microsoft calendar connected.');
    // A failed connect/sync is rare but important — a 2.6s toast is too easy
    // to miss right after an OAuth redirect, so this one gets a blocking alert.
    if (params.has('ms_cal_error')) alert('Microsoft calendar connection failed:\n\n' + params.get('ms_cal_error'));
    if (params.has('ms_cal_connected') || params.has('ms_cal_error')) {
      history.replaceState(null, '', location.pathname + location.hash);
    }
  })();

  route();
})();
