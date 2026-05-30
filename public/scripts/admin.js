// NexusOS admin panel — interactive (no page reload).
// Intercepts .nx-ajax forms, POSTs via fetch, updates the DOM from the JSON.
(function () {
  var shell = document.querySelector('.nx-shell[data-csrf]');
  if (!shell) return;
  var CSRF = shell.dataset.csrf;

  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  var cap = function (s) { s = String(s || ''); return s.charAt(0).toUpperCase() + s.slice(1); };
  var roleBadge = function (r) { return 'badge badge--role-' + String(r || '').replace(/[^a-z]/g, ''); };

  // Toast
  var toast = document.getElementById('nx-toast');
  var toastTimer;
  function showToast(msg, ok) {
    if (!toast) return;
    toast.textContent = msg;
    toast.className = 'nx-toast nx-toast--' + (ok ? 'ok' : 'err');
    toast.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.hidden = true; }, 3500);
  }

  function countMembers(tbody) {
    return tbody.querySelectorAll('tr[data-user]').length;
  }
  function refreshCount(orgId) {
    var tbody = document.querySelector('tbody[data-members="' + orgId + '"]');
    var span = document.querySelector('span[data-count="' + orgId + '"]');
    if (!tbody || !span) return;
    var n = countMembers(tbody);
    span.textContent = n;
    var empty = tbody.querySelector('tr[data-empty]');
    if (n === 0 && !empty) {
      tbody.insertAdjacentHTML('beforeend', '<tr data-empty><td colspan="5" class="nx-muted">No members</td></tr>');
    } else if (n > 0 && empty) {
      empty.remove();
    }
  }

  function memberRowHtml(orgId, m) {
    var platform = m.global_role === 'admin'
      ? '<span class="badge badge--role-admin">Global admin</span>'
      : '<span class="nx-muted">user</span>';
    return '<tr data-user="' + m.id + '">' +
      '<td>' + esc(m.full_name || '—') + '</td>' +
      '<td>' + esc(m.email) + '</td>' +
      '<td>' + platform + '</td>' +
      '<td><span class="' + roleBadge(m.member_role) + '">' + esc(cap(m.member_role)) + '</span></td>' +
      '<td class="nx-row-action">' +
        '<form class="nx-ajax" data-ajax="remove-member" method="POST" action="/admin/remove-member">' +
          '<input type="hidden" name="csrf_token" value="' + esc(CSRF) + '">' +
          '<input type="hidden" name="organization_id" value="' + orgId + '">' +
          '<input type="hidden" name="user_id" value="' + m.id + '">' +
          '<button type="submit" class="nx-icon-btn" title="Remove member"><i class="fa-solid fa-user-minus"></i></button>' +
        '</form>' +
      '</td></tr>';
  }

  function upsertMemberRow(orgId, m) {
    var tbody = document.querySelector('tbody[data-members="' + orgId + '"]');
    if (!tbody) return;
    var existing = tbody.querySelector('tr[data-user="' + m.id + '"]');
    if (existing) {
      existing.outerHTML = memberRowHtml(orgId, m);
    } else {
      tbody.insertAdjacentHTML('beforeend', memberRowHtml(orgId, m));
    }
    refreshCount(orgId);
  }

  function companyPanelHtml(org) {
    return '<section class="nx-panel" data-org-panel="' + org.id + '">' +
      '<div class="nx-panel-head"><div><h2>' + esc(org.name) + '</h2>' +
        '<small>' + esc(org.plan) + ' · <span data-count="' + org.id + '">0</span> members</small></div></div>' +
      '<table class="nx-table"><thead><tr><th>Name</th><th>Email</th><th>Platform</th><th>Company role</th><th></th></tr></thead>' +
      '<tbody data-members="' + org.id + '"><tr data-empty><td colspan="5" class="nx-muted">No members</td></tr></tbody></table>' +
      '<form class="nx-form nx-form--inline nx-ajax" data-ajax="add-member" method="POST" action="/admin/add-member">' +
        '<input type="hidden" name="csrf_token" value="' + esc(CSRF) + '">' +
        '<input type="hidden" name="organization_id" value="' + org.id + '">' +
        '<input name="email" type="email" placeholder="existing user email" required>' +
        '<select name="member_role"><option value="admin">Admin</option><option value="manager">Manager</option>' +
          '<option value="analyst">Analyst</option><option value="viewer" selected>Viewer</option></select>' +
        '<button type="submit"><i class="fa-solid fa-user-plus"></i> Add member</button>' +
      '</form></section>';
  }

  function handle(data) {
    if (data.action === 'create-company' && data.company) {
      var box = document.getElementById('nx-companies');
      if (box) box.insertAdjacentHTML('beforeend', companyPanelHtml(data.company));
      var sel = document.querySelector('select[data-company-select]');
      if (sel) {
        var opt = document.createElement('option');
        opt.value = data.company.id; opt.textContent = data.company.name;
        sel.appendChild(opt);
        var form = sel.closest('form'); var note = document.querySelector('[data-no-company]');
        if (form) form.hidden = false; if (note) note.hidden = true;
      }
    } else if ((data.action === 'create-user' || data.action === 'add-member') && data.member) {
      upsertMemberRow(data.organization_id, data.member);
    } else if (data.action === 'remove-member') {
      var row = document.querySelector('tbody[data-members="' + data.organization_id + '"] tr[data-user="' + data.user_id + '"]');
      if (row) row.remove();
      refreshCount(data.organization_id);
    }
  }

  document.addEventListener('submit', function (e) {
    var form = e.target.closest('form.nx-ajax');
    if (!form) return;
    e.preventDefault();
    var btn = form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;

    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        showToast(data.msg || (data.ok ? 'Done' : 'Error'), !!data.ok);
        if (data.ok) {
          handle(data);
          if (form.dataset.ajax === 'create-user' || form.dataset.ajax === 'create-company') {
            form.reset();
          } else if (form.dataset.ajax === 'add-member') {
            var em = form.querySelector('input[name="email"]'); if (em) em.value = '';
          }
        }
      })
      .catch(function () { showToast('Network error', false); })
      .finally(function () { if (btn) btn.disabled = false; });
  });
})();
