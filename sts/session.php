<?php
/**
 * Session browser — session editor entry and per-session output.
 */
$sts_dir = __DIR__;
require_once $sts_dir . '/open_db.php';
require_once $sts_dir . '/session_helpers.php';

$dbc = open_db();
$current = session_get_db_session($dbc);
mysqli_close($dbc);

$root = session_web_root();
$sessions = session_list_browser_sessions($current, $root);
$max_session = max($sessions ?: [1]);

$selected = isset($_GET['session']) ? (int) $_GET['session'] : $current;
if ($selected < 1 || !in_array($selected, $sessions, true)) {
    $selected = in_array($current, $sessions, true) ? $current : $max_session;
}

$prev_session = session_adjacent_session($sessions, $selected, 'prev');
$next_session = session_adjacent_session($sessions, $selected, 'next');

$manifest = session_load_manifest($selected, $root);
session_ensure_output_stubs($selected, $manifest, $root);
$dir = session_dir_for($selected, $root);
$has_data = is_dir($dir);
$phases = $manifest['phases'] ?? [];
$jobs_meta = $manifest['jobs'] ?? [];
$jobs = array_keys($jobs_meta);
$train_groups = session_job_group_map($selected, $manifest, $root);
$train_counts = [];
$session_print_all_rel = null;
if ($jobs) {
    $dbc_trains = open_db();
    foreach ($jobs as $job) {
        $train_counts[$job] = session_train_output_counts($dbc_trains, $selected, $job, $root);
    }
    $archived_only = session_is_archived_output_only($selected);
    $candidate = 'session_' . (int) $selected . '/print_all.html';
    if ($archived_only && is_file(session_output_fs_path($candidate, $root))) {
        $session_print_all_rel = $candidate;
    } elseif (!$archived_only) {
        $session_print_all_rel = session_build_switchlist_print_all($dbc_trains, $selected, $root);
    }
    mysqli_close($dbc_trains);
}
$selected_manifest_stats = $manifest['run_stats'] ?? [];
// Cumulative roll-up of generated output / operations across sessions 1..selected.
// Cars-moved and station tallies use THIS session's run_stats so a rebuild of
// session N does not hide its trains behind session 1's leftover summary.
$run_stats = session_aggregate_run_stats_through($selected, $root);
$own = is_array($selected_manifest_stats) ? $selected_manifest_stats : [];
if (session_run_stats_has_data($own)) {
    if (!empty($own['move_summary']) && is_array($own['move_summary'])) {
        $run_stats['move_summary'] = $own['move_summary'];
    }
    if (!empty($own['station_counts']) && is_array($own['station_counts'])) {
        $run_stats['station_counts'] = $own['station_counts'];
    }
    if (array_key_exists('on_train_count', $own)) {
        $run_stats['on_train_count'] = (int) $own['on_train_count'];
    }
    if (!empty($own['updated'])) {
        $run_stats['updated'] = $own['updated'];
    }
}
$run_stats['dashboard'] = $own['dashboard'] ?? [];
if ($selected === $current) {
    require_once $sts_dir . '/operations_stats.php';
    $dbc_stats = open_db();
    $run_stats['dashboard'] = operations_get_stats($dbc_stats);
    $run_stats['dashboard_live'] = true;
    mysqli_close($dbc_stats);
}
$has_waybills = session_waybills_bundle_ready($selected, null, $root)
    || is_file(session_waybill_dir_for($selected, null, $root) . '/index.html');
$has_switchlists = session_manifest_has_switchlists($manifest, $selected, $root);
$selected_style = session_normalize_switchlist_style(
    $_GET['style'] ?? ($manifest['preferred_switchlist_style'] ?? 'mobile')
);
$style_available = session_session_style_available($selected, $selected_style, $manifest, $root);
$switchlist_styles = session_switchlist_styles();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>All-session totals</title>
  <?php echo session_static_head_assets('session-nav.css'); ?>
</head>
<body>
<?php
session_render_nav_bar([
    ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
    ['href' => session_session_index_href($selected), 'label' => 'Session ' . (int) $selected, 'icon' => 'calendar-event'],
    ['href' => 'session-sitemap.html', 'label' => 'Site Map', 'icon' => 'diagram-3', 'right' => true],
], 'All-session totals');
?>
  <main>
    <div class="session-topbar">
      <div class="session-topbar-heading">
        <h1>All-session totals</h1>
        <p class="muted">Cumulative statistics through session <strong><?php echo (int) $selected; ?></strong> · Current DB session: <strong><?php echo (int) $current; ?></strong></p>
      </div>
      <a class="btn-editor-primary" href="editor.html"><i class="bi bi-pencil-square"></i> Open Session Editor</a>
    </div>

    <div class="card card-highlight">
      <h1 style="margin:0 0 4px;">
        Session <?php echo (int) $selected; ?>
        <?php if ($selected === $current): ?><span class="badge">current</span><?php endif; ?>
        <?php if (!$has_data): ?><span class="badge">no output</span><?php endif; ?>
      </h1>
      <p class="muted" style="margin:0 0 12px;">
        <?php if (count($phases) || count($jobs)): ?>
          <?php echo count($jobs); ?> train(s) · <?php echo count($phases); ?> phase(s)
        <?php else: ?>
          No workflow output yet for this session.
        <?php endif; ?>
      </p>
      <div class="session-quick-links">
        <a class="btn btn-outline-dark btn-sm" href="<?php echo htmlspecialchars(session_session_index_href($selected)); ?>"><i class="bi bi-clipboard-data"></i> Session overview</a>
        <?php if ($session_print_all_rel !== null): ?>
          <a class="btn btn-outline-dark btn-sm" href="<?php echo htmlspecialchars(session_output_url($session_print_all_rel)); ?>"><i class="bi bi-printer"></i> Print all switch lists</a>
        <?php endif; ?>
        <?php
          $session_wb_index_rel = 'session_' . (int) $selected . '/waybills/index.html';
          $session_wb_print_rel = 'session_' . (int) $selected . '/waybills/print_all.html';
          if (is_file(session_output_fs_path($session_wb_print_rel, $root))): ?>
          <a class="btn btn-outline-dark btn-sm" href="<?php echo htmlspecialchars(session_output_url($session_wb_print_rel)); ?>"><i class="bi bi-printer"></i> Print all waybills</a>
        <?php elseif (is_file(session_output_fs_path($session_wb_index_rel, $root))): ?>
          <a class="btn btn-outline-dark btn-sm" href="<?php echo htmlspecialchars(session_output_url($session_wb_index_rel)); ?>"><i class="bi bi-file-text"></i> All waybills</a>
        <?php endif; ?>
      </div>
      <?php if (!$has_switchlists): ?>
        <p class="muted" style="margin:12px 0 0;">Switch lists not generated yet — run <em>Generate Switch Lists</em> in the Session Editor.</p>
      <?php endif; ?>
      <form method="get" class="session-picker session-picker-footer" action="session.php" id="session-select-form">
        <label class="session-picker-label" for="session-select">Jump to session</label>
        <div class="session-picker-controls">
          <?php echo session_picker_skip_link('rw', $selected, $sessions, 'session.php?session='); ?>
          <?php if ($prev_session !== null): ?>
            <a class="btn btn-outline-dark btn-sm" href="session.php?session=<?php echo (int) $prev_session; ?>" title="Session <?php echo (int) $prev_session; ?>"><i class="bi bi-chevron-left"></i></a>
          <?php else: ?>
            <span class="btn btn-outline-dark btn-sm disabled" aria-disabled="true"><i class="bi bi-chevron-left"></i></span>
          <?php endif; ?>
          <select name="session" id="session-select">
            <?php foreach ($sessions as $n): ?>
              <option value="<?php echo (int) $n; ?>"<?php echo $n === $selected ? ' selected' : ''; ?>>
                Session <?php echo (int) $n; ?><?php
                  if ($n === $current) {
                      echo ' (current)';
                  } elseif ($n > $current) {
                      echo ' (archived)';
                  }
                ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($next_session !== null): ?>
            <a class="btn btn-outline-dark btn-sm" href="session.php?session=<?php echo (int) $next_session; ?>" title="Session <?php echo (int) $next_session; ?>"><i class="bi bi-chevron-right"></i></a>
          <?php else: ?>
            <span class="btn btn-outline-dark btn-sm disabled" aria-disabled="true"><i class="bi bi-chevron-right"></i></span>
          <?php endif; ?>
          <?php echo session_picker_skip_link('ff', $selected, $sessions, 'session.php?session='); ?>
          <noscript><button type="submit" class="btn btn-outline-dark btn-sm">Go</button></noscript>
        </div>
      </form>
      <?php echo session_browse_archived_controls_html(); ?>
    </div>

    <div class="card">
      <h2 style="margin:0 0 10px;">Trains</h2>
      <?php if (count($jobs)): ?>
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
              <a href="/sts/so.php?f=session_<?php echo (int) $selected; ?>/train_<?php echo urlencode($primary); ?>.print_all_<?php echo urlencode($selected_style); ?>.html"<?php echo $tip !== '' ? ' title="' . htmlspecialchars($tip) . '"' : ''; ?>>
                <?php echo htmlspecialchars($group_name); ?>
                <span class="meta"><?php echo $sw . ' switchlist' . ($sw === 1 ? '' : 's') . ' · ' . $wb . ' waybill' . ($wb === 1 ? '' : 's'); ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted" style="margin:0;">No trains recorded yet. Run the workflow generator from the Session Editor.</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 style="margin:0 0 8px;">Statistics<?php echo $selected > 1 ? ' — sessions 1–' . (int) $selected : ''; ?></h2>
      <?php echo session_render_run_stats_block(
          $run_stats,
          'No workflow statistics recorded through this session yet. Run the workflow in the Session Editor.'
      ); ?>
    </div>
  </main>
  <script>
    (function () {
      const sessionSelect = document.getElementById('session-select');
      sessionSelect?.addEventListener('change', function () {
        document.getElementById('session-select-form')?.submit();
      });
    })();
  </script>
</body>
</html>
