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

<script src="/sts/js/api_admin.js?v=1"></script>
<?php
sts_page_end();
