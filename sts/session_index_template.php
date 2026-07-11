<?php
/**
 * Per-session overview view. Rendered by session_overview.php?session=N and also
 * usable as a copied temp/session_N/index.php stub (session derived from GET or
 * from the containing directory name).
 */
$session = isset($_GET['session'])
    ? (int) $_GET['session']
    : (int) preg_replace('/^session_(\d+)$/', '$1', basename(dirname(__FILE__)));
if ($session < 1) {
    header('Location: /sts/session.php');
    exit;
}
$sts_dir = __DIR__;
while ($sts_dir !== '/' && !is_file($sts_dir . '/session_helpers.php')) {
    $sts_dir = dirname($sts_dir);
}
require_once $sts_dir . '/session_helpers.php';
require_once $sts_dir . '/open_db.php';
$root = session_web_root();
$manifest = session_load_manifest($session, $root);
session_ensure_output_stubs($session, $manifest, $root);
$phases = $manifest['phases'] ?? [];
$jobs = $manifest['jobs'] ?? [];
$run_stats = $manifest['run_stats'] ?? [];
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
$session_print_all_rel = $jobs ? session_build_switchlist_print_all($dbc_stats, $session, $root) : null;
mysqli_close($dbc_stats);
$browser_sessions = session_list_browser_sessions($current_session, $root);
$prev_session = session_adjacent_session($browser_sessions, $session, 'prev');
$next_session = session_adjacent_session($browser_sessions, $session, 'next');
$session_waybills = session_dir_for($session, $root) . '/waybills';
$has_session_waybills = is_file($session_waybills . '/index.html');
$has_session_wb_print = session_waybills_bundle_ready($session, null, $root);
$has_switchlists = session_manifest_has_switchlists($manifest, $session, $root);
$selected_style = session_normalize_switchlist_style(
    $_GET['style'] ?? ($manifest['preferred_switchlist_style'] ?? 'mobile')
);
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
session_render_nav_bar([
    ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
    ['href' => '/sts/session.php?session=' . (int) $session, 'label' => 'All Sessions', 'icon' => 'collection'],
    ['href' => '/sts/editor.html', 'label' => 'Session Editor', 'icon' => 'pencil-square'],
    ['href' => '/sts/session-sitemap.html', 'label' => 'Session Site Map', 'icon' => 'diagram-3'],
], 'Session ' . (int) $session);
?>
  <main>
  <h1>Session <?php echo (int) $session; ?></h1>
  <div class="session-nav-row waybill-session-nav">
    <?php if ($prev_session !== null): ?>
      <a class="btn btn-outline-dark" href="/sts/session_overview.php?session=<?php echo (int) $prev_session; ?>"><i class="bi bi-chevron-left"></i> Session <?php echo (int) $prev_session; ?></a>
    <?php else: ?>
      <span class="btn btn-outline-dark disabled" aria-disabled="true"><i class="bi bi-chevron-left"></i> Previous</span>
    <?php endif; ?>
    <?php if ($next_session !== null): ?>
      <a class="btn btn-outline-dark" href="/sts/session_overview.php?session=<?php echo (int) $next_session; ?>">Session <?php echo (int) $next_session; ?> <i class="bi bi-chevron-right"></i></a>
    <?php else: ?>
      <span class="btn btn-outline-dark disabled" aria-disabled="true">Next <i class="bi bi-chevron-right"></i></span>
    <?php endif; ?>
  </div>
  <?php if (count($jobs)): ?>
    <div class="card">
      <h2 style="margin:0 0 10px;">Trains</h2>
      <ul class="phase-list train-tiles">
        <?php foreach (array_keys($jobs) as $job): ?>
          <?php
            $tc = $train_counts[$job] ?? ['switchlists' => 0, 'waybills' => 0];
            $sw = (int) $tc['switchlists'];
            $wb = (int) $tc['waybills'];
          ?>
          <li>
            <a href="/sts/job.php?session=<?php echo (int) $session; ?>&amp;job=<?php echo urlencode($job); ?>">
              <?php echo htmlspecialchars($job); ?>
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

  <div class="card card-highlight">
    <h2 style="margin:0 0 8px;">Consolidated views</h2>
    <div class="session-quick-links">
      <?php if ($session_print_all_rel !== null): ?>
        <a class="btn btn-outline-dark btn-sm" href="<?php echo htmlspecialchars(session_output_url($session_print_all_rel)); ?>"><i class="bi bi-printer"></i> Print all switch lists</a>
      <?php endif; ?>
      <a class="btn btn-outline-dark btn-sm" href="<?php echo htmlspecialchars(session_output_url('session_' . (int) $session . '/waybills/index.html')); ?>"><i class="bi bi-file-text"></i> All waybills</a>
      <?php if ($has_session_wb_print): ?>
        <a class="btn btn-outline-dark btn-sm" href="<?php echo htmlspecialchars(session_output_url('session_' . (int) $session . '/waybills/print_all.html')); ?>"><i class="bi bi-printer"></i> Print all waybills</a>
      <?php endif; ?>
    </div>
    <?php if (!$has_switchlists): ?>
      <p class="muted" style="margin:12px 0 0;">No switch lists yet. Run <em>Generate Switch Lists</em> in the workflow.</p>
    <?php endif; ?>
    <?php if (!$has_session_waybills): ?>
      <p class="muted" style="margin:8px 0 0;">No waybill files yet. Run <em>Generate Waybill List</em> after switch lists.</p>
    <?php endif; ?>
  </div>

  <?php echo session_run_stats_updated_html($run_stats); ?>
  </main>
</body>
</html>
