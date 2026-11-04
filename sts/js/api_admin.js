(function () {
  var API = 'api/v1';
  var ROLES = ['operator', 'editor', 'admin'];
  var me = null;
  var policy = null;

  function $(id) { return document.getElementById(id); }
  function el(tag, text, cls) {
    var e = document.createElement(tag);
    if (text !== undefined && text !== null) { e.textContent = text; }
    if (cls) { e.className = cls; }
    return e;
  }
  function show(id, on) { $(id).classList.toggle('d-none', !on); }
  function alertMsg(msg, kind) {
    var a = $('api-alert');
    a.className = 'alert alert-' + (kind || 'danger');
    a.textContent = msg;
    if (!msg) { a.className = 'alert d-none'; }
  }
  function api(method, path, body) {
    var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(API + path, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) {
          var e = new Error((j.error && j.error.message) || ('HTTP ' + r.status));
          e.status = r.status;
          throw e;
        }
        return j;
      });
    });
  }
  function fail(e) { alertMsg(e.message, 'danger'); }
  function fmt(t) { return t ? t.replace('T', ' ').replace(/\+00:00$/, ' UTC') : ''; }

  function render() {
    show('signed-out', !me);
    show('signed-in', !!me);
    var admin = !!me && me.role === 'admin';
    show('admin-area', admin);
    show('non-admin-note', !!me && !admin);
    if (me) { $('who-name').textContent = me.name; $('who-role').textContent = me.role; }
    if (admin) { loadTokens(); loadPolicy(); loadTasks(); }
  }

  function loadMe() {
    return api('GET', '/auth/me').then(function (j) { me = j.data; }, function () { me = null; }).then(render);
  }

  $('login-form').addEventListener('submit', function (ev) {
    ev.preventDefault();
    alertMsg('');
    api('POST', '/auth/login', { token: $('login-token').value.trim() }).then(function () {
      $('login-token').value = '';
      return loadMe();
    }, fail);
  });
  $('logout-btn').addEventListener('click', function () {
    api('POST', '/auth/logout', {}).then(function () { me = null; render(); }, fail);
  });

  function loadTokens() {
    api('GET', '/admin/tokens').then(function (j) {
      var tb = $('tokens-table').tBodies[0];
      tb.textContent = '';
      j.data.forEach(function (t) {
        var tr = el('tr', null, t.revoked_at ? 'api-revoked' : '');
        [t.name, t.role, fmt(t.created_at), fmt(t.last_used_at), fmt(t.revoked_at)].forEach(function (v) { tr.appendChild(el('td', v)); });
        var td = el('td');
        if (!t.revoked_at) {
          var b = el('button', 'Revoke', 'btn btn-outline-danger btn-sm');
          b.type = 'button';
          b.addEventListener('click', function () {
            if (!confirm('Revoke token "' + t.name + '"? Sessions using it end immediately.')) { return; }
            api('DELETE', '/admin/tokens/' + encodeURIComponent(t.id)).then(function () {
              if (t.name === me.name && t.role === me.role) { return loadMe(); }
              loadTokens();
            }, fail);
          });
          td.appendChild(b);
        }
        tr.appendChild(td);
        tb.appendChild(tr);
      });
    }, fail);
  }

  $('create-form').addEventListener('submit', function (ev) {
    ev.preventDefault();
    alertMsg('');
    api('POST', '/admin/tokens', { name: $('new-name').value.trim(), role: $('new-role').value }).then(function (j) {
      $('new-secret').textContent = j.data.token;
      show('new-secret-box', true);
      $('new-name').value = '';
      loadTokens();
    }, fail);
  });
  $('copy-secret').addEventListener('click', function () {
    var text = $('new-secret').textContent;
    var done = function () { alertMsg('Token copied.', 'success'); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, fail);
    } else {
      var r = document.createRange();
      r.selectNodeContents($('new-secret'));
      var s = window.getSelection();
      s.removeAllRanges();
      s.addRange(r);
      try { document.execCommand('copy'); done(); } catch (e) { alertMsg('Select the token and copy it manually.', 'warning'); }
    }
  });

  function loadPolicy() {
    api('GET', '/admin/policy').then(function (j) {
      policy = j.data;
      ['read_open', 'legacy_open', 'editor_open'].forEach(function (f) { $('flag-' + f).checked = !!policy[f]; });
      $('protected-backups').value = (policy.protected_backups || []).join(', ');
      var tb = $('policy-table').tBodies[0];
      tb.textContent = '';
      Object.keys(policy.command_roles).sort().forEach(function (id) {
        var tr = el('tr');
        tr.appendChild(el('td', id));
        var td = el('td');
        var sel = el('select', null, 'form-select form-select-sm');
        sel.dataset.command = id;
        ROLES.forEach(function (r) {
          var o = el('option', r);
          if (r === policy.command_roles[id]) { o.selected = true; }
          sel.appendChild(o);
        });
        td.appendChild(sel);
        tr.appendChild(td);
        tb.appendChild(tr);
      });
    }, fail);
  }
  $('policy-save').addEventListener('click', function () {
    var roles = {};
    Array.prototype.forEach.call($('policy-table').querySelectorAll('select'), function (s) { roles[s.dataset.command] = s.value; });
    var body = { command_roles: roles, protected_backups: $('protected-backups').value.split(',').map(function (x) { return x.trim(); }).filter(Boolean) };
    ['read_open', 'legacy_open', 'editor_open'].forEach(function (f) { body[f] = $('flag-' + f).checked; });
    alertMsg('');
    api('PUT', '/admin/policy', body).then(function () { alertMsg('Policy saved.', 'success'); loadPolicy(); }, fail);
  });

  function loadTasks() {
    api('GET', '/tasks?limit=50').then(function (j) {
      var tb = $('tasks-table').tBodies[0];
      tb.textContent = '';
      j.data.forEach(function (t) {
        var tr = el('tr');
        [t.id, t.kind, t.status, fmt(t.created_at), t.created_by || '', t.error || ''].forEach(function (v) { tr.appendChild(el('td', v)); });
        var td = el('td');
        if (t.status === 'running' || t.status === 'queued') {
          var b = el('button', 'Mark failed', 'btn btn-outline-danger btn-sm');
          b.type = 'button';
          b.addEventListener('click', function () {
            api('POST', '/admin/tasks/' + encodeURIComponent(t.id) + '/fail', { force: $('fail-force').checked }).then(loadTasks, fail);
          });
          td.appendChild(b);
        }
        tr.appendChild(td);
        tb.appendChild(tr);
      });
    }, fail);
  }
  $('tasks-refresh').addEventListener('click', loadTasks);

  loadMe();
})();
