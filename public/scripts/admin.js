// NexusOS admin panel — interactive & scalable (paginated companies, lazy
// members, inline edits). All actions are AJAX; no full page reloads.
(function () {
  var shell = document.querySelector('.nx-shell[data-csrf]');
  if (!shell) return;
  var CSRF = shell.dataset.csrf;
  var GLOBAL = shell.dataset.global === '1';
  var ROLES = ['admin', 'manager', 'analyst', 'viewer'];

  var box = document.getElementById('nx-companies');
  var state = { q: (document.querySelector('[data-company-search]') || {}).value || '', page: parseInt(box.dataset.page || '1', 10) };

  // ---- utils ----
  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  var cap = function (s) { s = String(s || ''); return s.charAt(0).toUpperCase() + s.slice(1); };
  var isPro = function (p) { return /pro|premium/i.test(String(p || '')); };
  var planLabel = function (p) { return isPro(p) ? 'Pro' : 'Free'; };
  function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }

  var toast = document.getElementById('nx-toast'); var toastTimer;
  function showToast(msg, ok) {
    if (!toast) return;
    toast.textContent = msg; toast.className = 'nx-toast nx-toast--' + (ok ? 'ok' : 'err'); toast.hidden = false;
    clearTimeout(toastTimer); toastTimer = setTimeout(function () { toast.hidden = true; }, 3500);
  }

  function api(url, opts) {
    opts = opts || {};
    opts.headers = Object.assign({ 'X-Requested-With': 'fetch', 'Accept': 'application/json' }, opts.headers || {});
    return fetch(url, opts).then(function (r) { return r.json().catch(function () { return { ok: false, msg: 'Bad response' }; }); });
  }
  function post(url, form) { return api(url, { method: 'POST', body: new FormData(form) }); }
  function postData(url, obj) {
    var fd = new FormData(); fd.append('csrf_token', CSRF);
    Object.keys(obj).forEach(function (k) { fd.append(k, obj[k]); });
    return api(url, { method: 'POST', body: fd });
  }

  // ---- markup builders ----
  function planPill(plan) {
    var pro = isPro(plan);
    return '<span class="nx-plan-pill nx-plan-pill--' + (pro ? 'pro' : 'free') + '">' +
      (pro ? '<i class="fa-solid fa-bolt"></i> ' : '') + planLabel(plan) + '</span>';
  }

  function companyCardHtml(co) {
    return '<article class="nx-co" data-org="' + esc(co.id) + '" data-name="' + esc(co.name) + '" data-plan="' + esc(planLabel(co.plan)) + '">' +
      '<header class="nx-co-head">' +
        '<button type="button" class="nx-co-toggle" data-toggle title="Show members"><i class="fa-solid fa-chevron-right"></i></button>' +
        '<span class="nx-co-name">' + esc(co.name) + '</span>' + planPill(co.plan) +
        '<span class="nx-co-count"><i class="fa-solid fa-users"></i> <span data-count="' + esc(co.id) + '">' + esc(co.member_count || 0) + '</span></span>' +
        '<button type="button" class="nx-icon-btn nx-co-editbtn" data-edit-company title="Edit company"><i class="fa-solid fa-pen"></i></button>' +
      '</header><div class="nx-co-body" data-body="' + esc(co.id) + '" hidden></div></article>';
  }

  function roleSelect(orgId, userId, current) {
    var o = ROLES.map(function (r) {
      return '<option value="' + r + '"' + (r === current ? ' selected' : '') + '>' + cap(r) + '</option>';
    }).join('');
    return '<select class="nx-mini" data-set-role data-org="' + esc(orgId) + '" data-user="' + esc(userId) + '">' + o + '</select>';
  }

  function memberRowHtml(orgId, m) {
    var platform = m.global_role === 'admin'
      ? '<span class="badge badge--role-admin">Global admin</span>' : '<span class="nx-muted">user</span>';
    var editBtn = GLOBAL ? '<button type="button" class="nx-icon-btn" data-edit-user data-user="' + esc(m.id) +
      '" data-name="' + esc(m.full_name || '') + '" data-email="' + esc(m.email) + '" data-global="' + esc(m.global_role) +
      '" title="Edit user"><i class="fa-solid fa-pen"></i></button>' : '';
    return '<tr data-user="' + esc(m.id) + '">' +
      '<td>' + esc(m.full_name || '—') + '</td><td>' + esc(m.email) + '</td><td>' + platform + '</td>' +
      '<td>' + roleSelect(orgId, m.id, m.member_role) + '</td>' +
      '<td class="nx-row-action">' + editBtn +
        '<button type="button" class="nx-icon-btn" data-remove-member data-org="' + esc(orgId) + '" data-user="' + esc(m.id) + '" title="Remove from company"><i class="fa-solid fa-user-minus"></i></button>' +
      '</td></tr>';
  }

  function membersBodyHtml(orgId, data) {
    var rows = (data.members || []).map(function (m) { return memberRowHtml(orgId, m); }).join('') ||
      '<tr data-empty><td colspan="5" class="nx-muted">No members</td></tr>';
    var cap = data.max_members
      ? '<p class="nx-cap"><i class="fa-solid fa-circle-info"></i> ' + esc(data.plan) + ' plan — up to ' + esc(data.max_members) + ' members.</p>'
      : '';
    var opts = ROLES.map(function (r) { return '<option value="' + esc(r) + '"' + (r === 'viewer' ? ' selected' : '') + '>' + esc(cap0(r)) + '</option>'; }).join('');
    return '<div class="nx-table-wrap"><table class="nx-table"><thead><tr><th>Name</th><th>Email</th><th>Platform</th><th>Role</th><th></th></tr></thead>' +
      '<tbody data-members="' + esc(orgId) + '">' + rows + '</tbody></table></div>' + cap +
      '<form class="nx-form nx-form--inline nx-ajax" data-ajax="add-member" method="POST" action="/admin/add-member">' +
        '<input type="hidden" name="csrf_token" value="' + esc(CSRF) + '"><input type="hidden" name="organization_id" value="' + esc(orgId) + '">' +
        '<input name="email" type="email" placeholder="existing user email" required>' +
        '<select name="member_role">' + opts + '</select>' +
        '<button type="submit"><i class="fa-solid fa-user-plus"></i> Add member</button></form>';
  }
  function cap0(s) { return cap(s); }

  // ---- counts ----
  function setCount(orgId, delta) {
    var span = document.querySelector('span[data-count="' + orgId + '"]');
    if (span) span.textContent = Math.max(0, parseInt(span.textContent || '0', 10) + delta);
  }
  function fixEmpty(orgId) {
    var tbody = document.querySelector('tbody[data-members="' + orgId + '"]'); if (!tbody) return;
    var has = tbody.querySelector('tr[data-user]'); var empty = tbody.querySelector('tr[data-empty]');
    if (!has && !empty) tbody.insertAdjacentHTML('beforeend', '<tr data-empty><td colspan="5" class="nx-muted">No members</td></tr>');
    if (has && empty) empty.remove();
  }

  // ---- companies list (search + pagination) ----
  function renderCompanies(data) {
    box.dataset.page = data.page; box.dataset.pages = data.pages;
    box.innerHTML = data.items.length
      ? data.items.map(companyCardHtml).join('')
      : '<p class="nx-muted" data-empty-companies>No companies found.</p>';
    var t = document.querySelector('[data-total]'); if (t) t.textContent = data.total;
    var cur = document.querySelector('[data-page-cur]'); if (cur) cur.textContent = data.page;
    var max = document.querySelector('[data-page-max]'); if (max) max.textContent = data.pages;
    var prev = document.querySelector('[data-prev]'); var next = document.querySelector('[data-next]');
    if (prev) prev.disabled = data.page <= 1;
    if (next) next.disabled = data.page >= data.pages;
  }
  function loadCompanies(page) {
    state.page = page;
    api('/admin/companies?q=' + encodeURIComponent(state.q) + '&page=' + page).then(function (d) {
      if (d.ok) renderCompanies(d);
    });
  }

  // ---- expand company (lazy members) ----
  function toggleCompany(card) {
    var orgId = card.dataset.org;
    var body = card.querySelector('.nx-co-body');
    var icon = card.querySelector('[data-toggle] i');
    if (!body.hidden) { body.hidden = true; card.classList.remove('is-open'); if (icon) icon.className = 'fa-solid fa-chevron-right'; return; }
    card.classList.add('is-open'); if (icon) icon.className = 'fa-solid fa-chevron-down';
    body.hidden = false;
    if (body.dataset.loaded) return;
    body.innerHTML = '<p class="nx-muted">Loading…</p>';
    api('/admin/members?org=' + orgId).then(function (d) {
      if (!d.ok) { body.innerHTML = '<p class="nx-muted">Cannot load members.</p>'; return; }
      body.innerHTML = membersBodyHtml(orgId, d);
      body.dataset.loaded = '1';
    });
  }

  // ---- inline edit company ----
  function editCompany(card) {
    if (card.querySelector('.nx-co-edit')) return; // already editing
    var orgId = card.dataset.org;
    var planSel = GLOBAL
      ? '<select name="plan"><option value="Free">Free</option><option value="Pro">Pro</option></select>' : '';
    var form = document.createElement('form');
    form.className = 'nx-co-edit nx-form--inline';
    form.innerHTML = '<input name="name" type="text" maxlength="120" value="' + esc(card.dataset.name) + '" required>' +
      planSel + '<button type="submit" class="nx-mini-btn">Save</button>' +
      '<button type="button" class="nx-mini-btn nx-mini-btn--ghost" data-cancel-edit>Cancel</button>';
    if (GLOBAL) { var s = form.querySelector('select'); if (s) s.value = card.dataset.plan; }
    card.querySelector('.nx-co-head').after(form);
    form.querySelector('input').focus();
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var payload = { organization_id: orgId, name: form.name.value };
      if (GLOBAL) payload.plan = form.plan.value;
      postData('/admin/update-company', payload).then(function (d) {
        showToast(d.msg, d.ok);
        if (d.ok && d.company) {
          card.dataset.name = d.company.name; card.dataset.plan = planLabel(d.company.plan);
          card.querySelector('.nx-co-name').textContent = d.company.name;
          card.querySelector('.nx-plan-pill').outerHTML = planPill(d.company.plan);
          form.remove();
        }
      });
    });
    form.querySelector('[data-cancel-edit]').addEventListener('click', function () { form.remove(); });
  }

  // ---- edit user modal ----
  function editUser(btn) {
    var modal = document.createElement('div');
    modal.className = 'nx-modal';
    modal.innerHTML = '<form class="nx-modal-card nx-form">' +
      '<h3>Edit user</h3>' +
      '<label>Full name <input name="full_name" type="text" maxlength="100" value="' + esc(btn.dataset.name) + '" required></label>' +
      '<label>Email <input name="email" type="email" maxlength="100" value="' + esc(btn.dataset.email) + '" required></label>' +
      '<label>Platform role <select name="global_role"><option value="viewer">Regular user</option><option value="admin">Global admin</option></select></label>' +
      '<label>New password <input name="password" type="password" minlength="8" maxlength="200" placeholder="leave blank to keep"></label>' +
      '<div class="nx-modal-actions"><button type="button" class="nx-mini-btn nx-mini-btn--ghost" data-close>Cancel</button><button type="submit" class="nx-mini-btn">Save</button></div></form>';
    document.body.appendChild(modal);
    modal.querySelector('select[name="global_role"]').value = (btn.dataset.global === 'admin') ? 'admin' : 'viewer';
    var close = function () { modal.remove(); };
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    modal.querySelector('[data-close]').addEventListener('click', close);
    modal.querySelector('form').addEventListener('submit', function (e) {
      e.preventDefault();
      var f = e.target;
      postData('/admin/update-user', {
        user_id: btn.dataset.user, full_name: f.full_name.value, email: f.email.value,
        global_role: f.global_role.value, password: f.password.value
      }).then(function (d) {
        showToast(d.msg, d.ok);
        if (d.ok) {
          document.querySelectorAll('tr[data-user="' + d.user_id + '"]').forEach(function (row) {
            row.children[0].textContent = d.full_name; row.children[1].textContent = d.email;
            row.children[2].innerHTML = d.global_role === 'admin' ? '<span class="badge badge--role-admin">Global admin</span>' : '<span class="nx-muted">user</span>';
            var eb = row.querySelector('[data-edit-user]');
            if (eb) { eb.dataset.name = d.full_name; eb.dataset.email = d.email; eb.dataset.global = d.global_role; }
          });
          close();
        }
      });
    });
  }

  // ---- create / add result handling ----
  function handle(d) {
    if (d.action === 'create-company' && d.company) {
      var first = box.querySelector('.nx-co'); var empty = box.querySelector('[data-empty-companies]');
      if (empty) empty.remove();
      box.insertAdjacentHTML('afterbegin', companyCardHtml(d.company));
      var t = document.querySelector('[data-total]'); if (t) t.textContent = parseInt(t.textContent || '0', 10) + 1;
    } else if ((d.action === 'create-user' || d.action === 'add-member') && d.member) {
      var tbody = document.querySelector('tbody[data-members="' + d.organization_id + '"]');
      if (tbody) {
        var ex = tbody.querySelector('tr[data-user="' + d.member.id + '"]');
        if (ex) ex.outerHTML = memberRowHtml(d.organization_id, d.member);
        else { tbody.insertAdjacentHTML('beforeend', memberRowHtml(d.organization_id, d.member)); setCount(d.organization_id, 1); }
        fixEmpty(d.organization_id);
      } else { setCount(d.organization_id, 1); } // body not open: just bump the count
    }
  }

  // ---- events ----
  document.addEventListener('submit', function (e) {
    var form = e.target.closest('form.nx-ajax'); if (!form) return;
    e.preventDefault();
    var btn = form.querySelector('button[type="submit"]'); if (btn) btn.disabled = true;
    post(form.action, form).then(function (d) {
      showToast(d.msg || (d.ok ? 'Done' : 'Error'), !!d.ok);
      if (d.ok) {
        handle(d);
        if (form.dataset.ajax === 'create-user' || form.dataset.ajax === 'create-company') form.reset();
        else if (form.dataset.ajax === 'add-member') { var em = form.querySelector('input[name="email"]'); if (em) em.value = ''; }
        var ci = form.querySelector('[data-company-id]'); if (ci) ci.value = '';
      }
    }).catch(function () { showToast('Network error', false); })
      .finally(function () { if (btn) btn.disabled = false; });
  });

  document.addEventListener('click', function (e) {
    var t;
    if ((t = e.target.closest('[data-toggle]'))) { toggleCompany(t.closest('.nx-co')); }
    else if ((t = e.target.closest('[data-edit-company]'))) { editCompany(t.closest('.nx-co')); }
    else if ((t = e.target.closest('[data-edit-user]'))) { editUser(t); }
    else if ((t = e.target.closest('[data-remove-member]'))) {
      if (!confirm('Remove this member?')) return;
      postData('/admin/remove-member', { organization_id: t.dataset.org, user_id: t.dataset.user }).then(function (d) {
        showToast(d.msg, d.ok);
        if (d.ok) { var row = document.querySelector('tbody[data-members="' + d.organization_id + '"] tr[data-user="' + d.user_id + '"]'); if (row) row.remove(); setCount(d.organization_id, -1); fixEmpty(d.organization_id); }
      });
    }
    else if ((t = e.target.closest('[data-prev]')) && !t.disabled) { loadCompanies(Math.max(1, parseInt(box.dataset.page, 10) - 1)); }
    else if ((t = e.target.closest('[data-next]')) && !t.disabled) { loadCompanies(parseInt(box.dataset.page, 10) + 1); }
  });

  document.addEventListener('change', function (e) {
    var sel = e.target.closest('[data-set-role]'); if (!sel) return;
    postData('/admin/set-role', { organization_id: sel.dataset.org, user_id: sel.dataset.user, member_role: sel.value })
      .then(function (d) { showToast(d.msg, d.ok); });
  });

  // search (debounced)
  var searchInput = document.querySelector('[data-company-search]');
  if (searchInput) searchInput.addEventListener('input', debounce(function () { state.q = searchInput.value.trim(); loadCompanies(1); }, 300));

  // create-user company typeahead
  var ta = document.querySelector('[data-company-input]');
  if (ta) {
    var hidden = document.querySelector('[data-company-id]');
    var list = document.querySelector('[data-company-results]');
    ta.addEventListener('input', debounce(function () {
      hidden.value = '';
      var q = ta.value.trim();
      api('/admin/search-companies?q=' + encodeURIComponent(q)).then(function (d) {
        if (!d.ok) return;
        list.innerHTML = d.items.map(function (c) { return '<li data-id="' + esc(c.id) + '">' + esc(c.name) + ' ' + planPill(c.plan) + '</li>'; }).join('');
        list.hidden = d.items.length === 0;
      });
    }, 250));
    list.addEventListener('click', function (e) {
      var li = e.target.closest('li[data-id]'); if (!li) return;
      hidden.value = li.dataset.id; ta.value = li.firstChild.textContent.trim(); list.hidden = true;
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.nx-typeahead')) list.hidden = true; });
  }
})();
