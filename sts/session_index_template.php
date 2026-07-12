<?php
/**
 * Per-session overview view. Rendered by session_overview.php?session=N and also
 * usable as a copied temp/session_N/index.php stub (session derived from GET or
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
// A rewound DB may make this session number a "future" one; if so, redirect to
// the current session's overview instead of showing an empty/stale page.
session_redirect_if_beyond_current($session);
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
$session_print_all_rel = $jobs ? session_build_switchlist_print_all($dbc_stats, $session, $root) : null;
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
$overview_nav[] = [
    'href' => '/sts/session-sitemap.html',
    'label' => 'Site Map',
    'icon' => 'diagram-3',
    'right' => true,
];
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
  <div class="session-topbar">
    <div class="session-topbar-heading">
      <h1 style="margin:0;">Session <?php echo (int) $session; ?></h1>
    </div>
    <a class="btn-editor-primary" href="/sts/editor.html"><i class="bi bi-pencil-square"></i> Open Session Editor</a>
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
            Session <?php echo (int) $n; ?><?php echo $n === $current_session ? ' (current)' : ''; ?>
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
            <a class="train-link" href="/sts/so.php?f=session_<?php echo (int) $session; ?>/train_<?php echo urlencode($primary); ?>.print_all.html"<?php echo $tip !== '' ? ' title="' . htmlspecialchars($tip) . '"' : ''; ?>>
              <?php echo htmlspecialchars($group_name); ?>
              <span class="meta"><?php echo $sw . ' switchlist' . ($sw === 1 ? '' : 's') . ' · ' . $wb . ' waybill' . ($wb === 1 ? '' : 's'); ?></span>
            </a>
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
    })();
  </script>
</body>
</html>
