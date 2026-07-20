<?php
/**
 * Per-session overview view. Rendered by session_overview.php?session=N and also
 * usable as a copied session_N/index.php stub under the session output root
 * (session derived from GET or
 * from the containing directory name).
 */
$sts_dir = __DIR__;
while ($sts_dir !== '/' && !is_file($sts_dir . '/session_helpers.php')) {
    $sts_dir = dirname($sts_dir);
}
require_once $sts_dir . '/session_helpers.php';
require_once $sts_dir . '/open_db.php';

$session = isset($_GET['session'])
    ? (int) $_GET['session']
    : (int) preg_replace('/^session_(\d+)$/', '$1', basename(dirname(__FILE__)));
if ($session < 1) {
    // Default session page: fall back to the current DB session so
    // session_overview.php (no param) lands on the active session's overview.
    $dbc_default = open_db();
    $session = (int) session_get_db_session($dbc_default);
    mysqli_close($dbc_default);
}
if ($session < 1) {
    header('Location: /sts/session.php');
    exit;
}
// A rewound DB may make this session number a "future" one; when archived
// browse is enabled we extract rewind_archive output and show it read-only.
session_redirect_if_beyond_current($session);
$archived_output_only = session_is_archived_output_only($session);
$root = session_web_root();
$manifest = session_load_manifest($session, $root);
session_ensure_output_stubs($session, $manifest, $root);
$phases = $manifest['phases'] ?? [];
$jobs = $manifest['jobs'] ?? [];
$run_stats = $manifest['run_stats'] ?? [];
// Recompute the generated-output tallies from the manifest at display time so the
// "Switch lists / Phases / Trains / Waybills" cards always reflect the latest
// generation token, even if the persisted run_stats were written when the
// session's manifest had accumulated repeated runs (e.g. a simulator replaying seeds).
$run_stats['generated'] = session_count_generated_output($manifest);
$run_stats['generated']['waybills'] = session_latest_token_waybill_count($session, $manifest, $root);
$dbc_stats = open_db();
$current_session = session_get_db_session($dbc_stats);
if (empty($run_stats['station_counts']) && (int) ($run_stats['on_train_count'] ?? 0) === 0 && $session === $current_session) {
    $run_stats['station_counts'] = session_station_car_counts($dbc_stats);
    $run_stats['on_train_count'] = session_on_train_car_count($dbc_stats);
}
$train_counts = [];
foreach (array_keys($jobs) as $job) {
    $train_counts[$job] = session_train_output_counts($dbc_stats, $session, $job, $root);
}
$train_groups = session_job_group_map($session, $manifest ?? null, $root);
$session_print_all_rel = null;
if ($jobs) {
    $candidate = 'session_' . (int) $session . '/print_all.html';
    if ($archived_output_only) {
        if (is_file(session_output_fs_path($candidate, $root))) {
            $session_print_all_rel = $candidate;
        }
    } else {
        $session_print_all_rel = session_build_switchlist_print_all($dbc_stats, $session, $root);
    }
}
mysqli_close($dbc_stats);
$browser_sessions = session_list_browser_sessions($current_session, $root);
$prev_session = session_adjacent_session($browser_sessions, $session, 'prev');
$next_session = session_adjacent_session($browser_sessions, $session, 'next');
$session_waybills = session_dir_for($session, $root) . '/waybills';
$has_session_waybills = is_file($session_waybills . '/index.html');
$has_switchlists = session_manifest_has_switchlists($manifest, $session, $root);
$session_wb_print_rel = 'session_' . (int) $session . '/waybills/print_all.html';
$session_wb_index_rel = 'session_' . (int) $session . '/waybills/index.html';
$session_wb_href = is_file(session_output_fs_path($session_wb_print_rel, $root))
    ? session_output_url($session_wb_print_rel)
    : ($has_session_waybills
        ? session_output_url($session_wb_index_rel)
        : null);
$station_report_rel = 'session_' . (int) $session . '/station_report.html';
$station_report_href = session_car_report_phases($session, 'station', $root) !== []
    ? session_output_url($station_report_rel)
    : null;
$wheel_report_rel = 'session_' . (int) $session . '/wheel_report.html';
$wheel_report_href = session_car_report_phases($session, 'wheel', $root) !== []
    ? session_output_url($wheel_report_rel)
    : null;
$overview_nav = [
    ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
    ['href' => '/sts/session.php?session=' . (int) $session, 'label' => 'All-session totals', 'icon' => 'bar-chart-line'],
];
if ($has_switchlists && $session_print_all_rel !== null) {
    $overview_nav[] = [
        'href' => session_output_url($session_print_all_rel),
        'label' => 'Session switch lists',
        'icon' => 'printer',
    ];
}
if ($session_wb_href !== null) {
    $overview_nav[] = [
        'href' => $session_wb_href,
        'label' => 'Session waybills',
        'icon' => 'files',
    ];
}
if ($station_report_href !== null) {
    $overview_nav[] = [
        'href' => $station_report_href,
        'label' => 'Station report',
        'icon' => 'geo-alt',
    ];
}
if ($wheel_report_href !== null) {
    $overview_nav[] = [
        'href' => $wheel_report_href,
        'label' => 'Wheel report',
        'icon' => 'list-ol',
    ];
}
$overview_nav[] = [
    'href' => '/sts/session-sitemap.html',
    'label' => 'Site Map',
    'icon' => 'diagram-3',
    'right' => true,
];
$session_locked_names = session_locked_backup_names($session);
$session_is_locked = $session_locked_names !== [];
$can_restart = !$archived_output_only
    && !$session_is_locked
    && session_can_restart_from_overview($session, $current_session, $root);
$restart_candidates = $can_restart ? session_restart_backup_candidates($session) : [];
$restart_error = isset($_GET['restart_error']) ? (string) $_GET['restart_error'] : '';
$restart_ok = isset($_GET['restart_ok']) ? (string) $_GET['restart_ok'] : '';
$restart_prev = max(0, (int) $session - 1);
$restart_has_pre = false;
foreach ($restart_candidates as $cand) {
    if (session_backup_is_pre_for_session($cand, $session)) {
        $restart_has_pre = true;
        break;
    }
}
$restart_confirm_base = $restart_has_pre
    ? ('Restart Session '
        . (int) $session
        . '?\n\nThis restores the database from the session pre backup (start of generated session '
        . (int) $session
        . ') with no further workflow steps applied, and deletes this session\'s generated output.')
    : ('Restart Session '
        . (int) $session
        . '?\n\nThis restores the database to the start of session '
        . (int) $session
        . ' (end of session '
        . $restart_prev
        . ') with no workflow steps applied, and deletes this session\'s generated output.');
$lock_candidates = !$archived_output_only ? session_lock_backup_candidates($session) : [];
$lock_default_sources = !$archived_output_only ? session_lock_default_sources($session) : [];
$lock_error = isset($_GET['lock_error']) ? (string) $_GET['lock_error'] : '';
$lock_ok = isset($_GET['lock_ok']) ? (string) $_GET['lock_ok'] : '';
$lock_multi = count($lock_default_sources) > 1;
$lock_confirm_base = $lock_multi
    ? ('Lock backups for Session '
        . (int) $session
        . '?\n\nThis copies pre + post dumps to static *_locked companions:\n  - '
        . implode("\n  - ", $lock_default_sources)
        . "\n\nOverwrites any prior locked copies with the same names. Does not change the live database."
        . "\n\nRestart Session is disabled while a *_locked backup exists for this session.")
    : ('Lock backup for Session '
        . (int) $session
        . '?\n\nThis copies the selected dump to a static *_locked companion (and its _photos folder when present). Overwrites any prior locked copy with the same name. Does not change the live database.'
        . "\n\nRestart Session is disabled while a *_locked backup exists for this session.");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Session <?php echo (int) $session; ?></title>
  <?php echo session_static_head_assets(); ?>
</head>
<body>
<?php
session_render_nav_bar($overview_nav, 'Session ' . (int) $session);
?>
  <main>
  <?php echo session_archived_view_banner_html($session, $current_session); ?>
  <?php if ($restart_ok !== ''): ?>
    <div class="session-flash session-flash-ok" role="status"><?php echo htmlspecialchars($restart_ok); ?></div>
  <?php endif; ?>
  <?php if ($restart_error !== ''): ?>
    <div class="session-flash session-flash-error" role="alert"><?php echo htmlspecialchars($restart_error); ?></div>
  <?php endif; ?>
  <?php if ($lock_ok !== ''): ?>
    <div class="session-flash session-flash-ok" role="status"><?php echo htmlspecialchars($lock_ok); ?></div>
  <?php endif; ?>
  <?php if ($lock_error !== ''): ?>
    <div class="session-flash session-flash-error" role="alert"><?php echo htmlspecialchars($lock_error); ?></div>
  <?php endif; ?>
  <div class="session-topbar">
    <div class="session-topbar-heading">
      <h1 style="margin:0;">Session <?php echo (int) $session; ?></h1>
      <?php if ($session_is_locked): ?>
        <p class="session-locked-note" title="<?php echo htmlspecialchars(implode(', ', $session_locked_names), ENT_QUOTES); ?>">
          <i class="bi bi-lock-fill"></i> Locked checkpoint
          (<?php echo htmlspecialchars(implode(', ', $session_locked_names)); ?>) — Restart Session disabled
        </p>
      <?php endif; ?>
    </div>
    <div class="session-topbar-actions">
      <a class="btn-editor-primary" href="/sts/editor.html"><i class="bi bi-pencil-square"></i> Open Session Editor</a>
      <?php if ($lock_candidates !== []): ?>
        <form method="post" action="/sts/session_lock_backup.php" class="session-restart-form" id="session-lock-form">
          <input type="hidden" name="session" value="<?php echo (int) $session; ?>">
          <?php if ($lock_multi): ?>
            <?php /* Lock Backup freezes every scheme dump (pre + post) together. */ ?>
          <?php elseif (count($lock_candidates) === 1): ?>
            <input type="hidden" name="backup" value="<?php echo htmlspecialchars($lock_candidates[0]); ?>">
          <?php else: ?>
            <label class="session-restart-backup-label" for="session-lock-backup">Backup</label>
            <select name="backup" id="session-lock-backup" class="session-restart-backup" required>
              <option value="">Select backup…</option>
              <?php foreach ($lock_candidates as $cand): ?>
                <option value="<?php echo htmlspecialchars($cand); ?>"><?php echo htmlspecialchars($cand); ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <button type="submit" class="btn-session-lock" title="<?php echo $lock_multi
              ? 'Copy pre + post dumps to static *_locked checkpoints'
              : 'Copy this session\'s dump to a static *_locked checkpoint'; ?>">
            <i class="bi bi-lock"></i> <?php
              if ($session_is_locked) {
                  echo $lock_multi ? 'Update Locked Backups' : 'Update Locked Backup';
              } else {
                  echo $lock_multi ? 'Lock Backups' : 'Lock Backup';
              }
            ?>
          </button>
        </form>
      <?php endif; ?>
      <?php if ($can_restart && $restart_candidates !== []): ?>
        <form method="post" action="/sts/session_restart.php" class="session-restart-form" id="session-restart-form">
          <input type="hidden" name="session" value="<?php echo (int) $session; ?>">
          <?php if (count($restart_candidates) === 1): ?>
            <input type="hidden" name="backup" value="<?php echo htmlspecialchars($restart_candidates[0]); ?>">
          <?php else: ?>
            <label class="session-restart-backup-label" for="session-restart-backup">Backup</label>
            <select name="backup" id="session-restart-backup" class="session-restart-backup" required>
              <option value="">Select backup…</option>
              <?php foreach ($restart_candidates as $cand): ?>
                <option value="<?php echo htmlspecialchars($cand); ?>"><?php echo htmlspecialchars($cand); ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <button type="submit" class="btn-session-restart" title="Restore DB to the start of this session and clear its output">
            <i class="bi bi-arrow-clockwise"></i> Restart Session
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <form method="get" class="session-picker session-nav-row waybill-session-nav" action="/sts/session_overview.php" id="session-select-form">
    <label class="session-picker-label" for="session-select">Jump to session</label>
    <div class="session-picker-controls">
      <?php echo session_picker_skip_link('rw', $session, $browser_sessions, '/sts/session_overview.php?session='); ?>
      <?php if ($prev_session !== null): ?>
        <a class="btn btn-outline-dark btn-sm" href="/sts/session_overview.php?session=<?php echo (int) $prev_session; ?>" title="Session <?php echo (int) $prev_session; ?>"><i class="bi bi-chevron-left"></i></a>
      <?php else: ?>
        <span class="btn btn-outline-dark btn-sm disabled" aria-disabled="true"><i class="bi bi-chevron-left"></i></span>
      <?php endif; ?>
      <select name="session" id="session-select">
        <?php foreach ($browser_sessions as $n): ?>
          <option value="<?php echo (int) $n; ?>"<?php echo $n === $session ? ' selected' : ''; ?>>
            Session <?php echo (int) $n; ?><?php
              if ($n === $current_session) {
                  echo ' (current)';
              } elseif ($n > $current_session) {
                  echo ' (archived)';
              }
            ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if ($next_session !== null): ?>
        <a class="btn btn-outline-dark btn-sm" href="/sts/session_overview.php?session=<?php echo (int) $next_session; ?>" title="Session <?php echo (int) $next_session; ?>"><i class="bi bi-chevron-right"></i></a>
      <?php else: ?>
        <span class="btn btn-outline-dark btn-sm disabled" aria-disabled="true"><i class="bi bi-chevron-right"></i></span>
      <?php endif; ?>
      <?php echo session_picker_skip_link('ff', $session, $browser_sessions, '/sts/session_overview.php?session='); ?>
      <noscript><button type="submit" class="btn btn-outline-dark btn-sm">Go</button></noscript>
    </div>
  </form>
  <?php echo session_browse_archived_controls_html(); ?>
  <?php if (count($jobs)): ?>
    <div class="card">
      <div class="job-switchlist-controls" style="margin:0 0 10px;">
        <h2 style="margin:0;">Trains</h2>
      </div>
      <ul class="phase-list train-tiles">
        <?php foreach ($train_groups as $group_name => $members): ?>
          <?php
            $sw = 0;
            $wb = 0;
            foreach ($members as $mj) {
                $tc = $train_counts[$mj] ?? ['switchlists' => 0, 'waybills' => 0];
                $sw += (int) $tc['switchlists'];
                $wb += (int) $tc['waybills'];
            }
            $primary = $members[0];
            $tip = ($group_name !== $primary || count($members) > 1) ? implode(', ', $members) : '';
          ?>
            <li>
            <?php if ($sw > 0): ?>
            <a class="train-link" href="/sts/so.php?f=session_<?php echo (int) $session; ?>/train_<?php echo urlencode($primary); ?>.print_all.html"<?php echo $tip !== '' ? ' title="' . htmlspecialchars($tip) . '"' : ''; ?>>
              <?php echo htmlspecialchars($group_name); ?>
              <span class="meta"><?php echo $sw . ' switchlist' . ($sw === 1 ? '' : 's') . ' · ' . $wb . ' waybill' . ($wb === 1 ? '' : 's'); ?></span>
            </a>
            <?php else: ?>
            <span class="train-link muted"<?php echo $tip !== '' ? ' title="' . htmlspecialchars($tip) . '"' : ''; ?>>
              <?php echo htmlspecialchars($group_name); ?>
              <span class="meta">no switch lists yet</span>
            </span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php echo session_render_run_stats_block(
      $run_stats,
      'No workflow statistics recorded for this session yet. Run the workflow in the Session Editor while this session is active.',
      false
  ); ?>

  <?php if (!count($jobs)): ?>
    <p class="muted">No trains yet. Run the workflow generator from the editor.</p>
  <?php endif; ?>

  <?php echo session_run_stats_updated_html($run_stats); ?>
  </main>
  <script>
    (function () {
      const sessionSelect = document.getElementById('session-select');
      sessionSelect?.addEventListener('change', function () {
        document.getElementById('session-select-form')?.submit();
      });

      const restartForm = document.getElementById('session-restart-form');
      const confirmBase = <?php echo json_encode($restart_confirm_base); ?>;
      restartForm?.addEventListener('submit', function (ev) {
        const backupInput = restartForm.querySelector('[name="backup"]');
        const backup = (backupInput && backupInput.value) ? String(backupInput.value).trim() : '';
        if (backupInput && backupInput.tagName === 'SELECT' && backup === '') {
          ev.preventDefault();
          alert('Select a backup to restore before restarting.');
          return;
        }
        const msg = confirmBase + (backup ? ('\n\nBackup: ' + backup) : '');
        if (!confirm(msg)) {
          ev.preventDefault();
        }
      });

      const lockForm = document.getElementById('session-lock-form');
      const lockConfirmBase = <?php echo json_encode($lock_confirm_base); ?>;
      lockForm?.addEventListener('submit', function (ev) {
        const backupInput = lockForm.querySelector('[name="backup"]');
        const backup = (backupInput && backupInput.value) ? String(backupInput.value).trim() : '';
        if (backupInput && backupInput.tagName === 'SELECT' && backup === '') {
          ev.preventDefault();
          alert('Select a backup to lock.');
          return;
        }
        const locked = backup ? (backup.endsWith('_locked') ? backup : (backup + '_locked')) : '';
        const msg = lockConfirmBase
          + (backup ? ('\n\nSource: ' + backup) : '')
          + (locked ? ('\nLocked: ' + locked) : '');
        if (!confirm(msg)) {
          ev.preventDefault();
        }
      });
    })();
  </script>
</body>
</html>
