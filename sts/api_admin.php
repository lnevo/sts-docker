<?php
/**
 * API Access: browser sign-in, API tokens, command roles / open flags and
 * background tasks. All data comes from /sts/api/v1 (cookie session, see
 * api/lib/session.php); this page holds no secrets.
 */
require_once 'page_chrome.php';

sts_page_start([
    'title' => 'API Access',
    'icon' => 'bi-key',
    'section' => 'maint',
    'container' => 'narrow',
    'head_extra' => '<style>
      .api-secret { font-family: monospace; word-break: break-all; background: #fff3cd; padding: 6px 8px; border-radius: 4px; }
      .api-card { border: none; box-shadow: 0 2px 6px rgba(0,0,0,.1); }
      .api-card .card-header { background: #4a90e2; color: #fff; font-weight: 600; }
      #policy-table select, #policy-table input { font-size: 16px; }
      .api-muted { color: #666; font-size: .85rem; }
      .api-revoked td { color: #999; }
    </style>',
]);
?>
<div id="api-alert" class="alert d-none" role="alert"></div>

<div class="card api-card mb-3" id="card-signin">
  <div class="card-header"><i class="bi bi-box-arrow-in-right"></i> Sign in</div>
  <div class="card-body">
    <div id="signed-out">
      <p class="api-muted mb-2">Paste an API token to sign in. The token is exchanged for a session cookie and is not stored by this page. The bootstrap admin token is in <code>sts-docker-data/config/bootstrap_admin.token</code> on the Docker host.</p>
      <form id="login-form" class="row g-2" autocomplete="off">
        <div class="col-12 col-md-9"><input type="password" id="login-token" class="form-control" placeholder="sts_..." autocomplete="off" required></div>
        <div class="col-12 col-md-3"><button class="btn btn-primary w-100" type="submit">Sign in</button></div>
      </form>
    </div>
    <div id="signed-in" class="d-none">
      <span>Signed in as <strong id="who-name"></strong> (role <strong id="who-role"></strong>)</span>
      <button class="btn btn-outline-secondary btn-sm ms-2" id="logout-btn" type="button">Sign out</button>
      <p class="api-muted mt-2 mb-0 d-none" id="non-admin-note">Only an admin token can manage tokens, policy and tasks. Sign out and use an admin token to see them.</p>
    </div>
  </div>
</div>

<div id="admin-area" class="d-none">
  <div class="card api-card mb-3">
    <div class="card-header"><i class="bi bi-key"></i> Tokens</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle" id="tokens-table">
          <thead><tr><th>Name</th><th>Role</th><th>Created</th><th>Last used</th><th>Revoked</th><th></th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <h6 class="mt-3">Create a token</h6>
      <form id="create-form" class="row g-2">
        <div class="col-12 col-md-5"><input id="new-name" class="form-control" placeholder="Name (e.g. rfid-reader)" maxlength="100" required></div>
        <div class="col-6 col-md-4">
          <select id="new-role" class="form-select">
            <option>reader</option><option selected>operator</option><option>editor</option><option>admin</option>
          </select>
        </div>
        <div class="col-6 col-md-3"><button class="btn btn-primary w-100" type="submit">Create</button></div>
      </form>
      <div id="new-secret-box" class="alert alert-warning mt-3 d-none">
        <strong>Copy this token now.</strong> It is shown once and cannot be recovered.
        <div class="api-secret my-2" id="new-secret"></div>
        <button class="btn btn-sm btn-outline-dark" id="copy-secret" type="button"><i class="bi bi-clipboard"></i> Copy</button>
      </div>
    </div>
  </div>

  <div class="card api-card mb-3">
    <div class="card-header"><i class="bi bi-shield-lock"></i> Policy</div>
    <div class="card-body">
      <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="flag-read_open"><label class="form-check-label" for="flag-read_open">Reads need no token (read_open)</label></div>
      <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="flag-legacy_open"><label class="form-check-label" for="flag-legacy_open">Legacy wagon / RFID routes need no token (legacy_open)</label></div>
      <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="flag-editor_open"><label class="form-check-label" for="flag-editor_open">Workflow editor needs no sign-in (editor_open)</label></div>
      <div class="mb-3">
        <label class="form-label" for="protected-backups">Protected backups (comma-separated name patterns; only an admin may overwrite a backup that matches)</label>
        <input class="form-control" type="text" id="protected-backups" placeholder="*seed*, *_locked*">
      </div>
      <div class="table-responsive" style="max-height: 360px; overflow-y: auto;">
        <table class="table table-sm align-middle" id="policy-table">
          <thead><tr><th>Command</th><th>Minimum role</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <button class="btn btn-primary mt-2" id="policy-save" type="button">Save policy</button>
      <span class="api-muted ms-2">Takes effect immediately.</span>
    </div>
  </div>

  <div class="card api-card mb-3">
    <div class="card-header"><i class="bi bi-list-task"></i> Background tasks</div>
    <div class="card-body">
      <div class="d-flex justify-content-between mb-2">
        <div class="form-check"><input class="form-check-input" type="checkbox" id="fail-force"><label class="form-check-label" for="fail-force">Force (process still alive)</label></div>
        <button class="btn btn-outline-secondary btn-sm" id="tasks-refresh" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle" id="tasks-table">
          <thead><tr><th>Id</th><th>Kind</th><th>Status</th><th>Created</th><th>By</th><th>Error</th><th></th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
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
</script>
<?php
sts_page_end();
