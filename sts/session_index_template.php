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
mysqli_close($dbc_stats);
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
], 'Session ' . (int) $session);
?>
  <main>
  <h1>Session <?php echo (int) $session; ?></h1>
  <?php echo session_render_run_stats_block(
      $run_stats,
      'No workflow statistics recorded for this session yet. Run the workflow in the Session Editor while this session is active.'
  ); ?>

  <div class="card card-highlight">
    <h2 style="margin:0 0 8px;">Consolidated views</h2>
    <div class="session-quick-links">
      <a class="btn btn-outline-dark btn-sm" href="/sts/session_switchlists.php?session=<?php echo (int) $session; ?>&amp;style=<?php echo urlencode($selected_style); ?>"><i class="bi bi-list-check"></i> All switch lists</a>
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

  <?php if (count($jobs)): ?>
    <h2>Trains</h2>
    <?php foreach (array_keys($jobs) as $job): ?>
      <div class="card">
        <h2 style="margin:0 0 8px;font-size:16px;"><?php echo htmlspecialchars($job); ?></h2>
        <p style="margin:0;">
          <a href="/sts/job.php?session=<?php echo (int) $session; ?>&amp;job=<?php echo urlencode($job); ?>"><strong>Switch lists &amp; waybills</strong></a>
          <?php
            $job_phases = $jobs[$job]['phases'] ?? [];
            if (count($job_phases)):
          ?>
            <span class="muted"> · <?php echo count($job_phases); ?> phase(s)</span>
          <?php endif; ?>
        </p>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php foreach ($phases as $phase): ?>
    <div class="card">
      <h2 style="margin:0 0 8px;">Phase <?php echo (int) ($phase['phase'] ?? 0); ?></h2>
      <p><?php echo htmlspecialchars($phase['label'] ?? 'Generate Switch Lists'); ?></p>
      <ul>
        <?php foreach ($phase['jobs'] ?? [] as $job): ?>
          <li><a href="/sts/job.php?session=<?php echo (int) $session; ?>&amp;job=<?php echo urlencode($job); ?>"><?php echo htmlspecialchars($job); ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php $phase_pad = session_phase_pad((int) ($phase['phase'] ?? 1)); ?>
      <p><a href="<?php echo htmlspecialchars(session_output_url('session_' . (int) $session . '/phase_' . $phase_pad . '/waybills/index.html')); ?>">All waybills (phase)</a>
        <?php if (session_waybills_bundle_ready($session, (int) ($phase['phase'] ?? 1), $root)): ?>
          · <a href="<?php echo htmlspecialchars(session_output_url('session_' . (int) $session . '/phase_' . $phase_pad . '/waybills/print_all.html')); ?>">Print all (phase)</a>
        <?php endif; ?>
      </p>
    </div>
  <?php endforeach; ?>
  <?php if (!count($phases) && !count($jobs)): ?>
    <p class="muted">No switch-list phases yet. Run the workflow generator from the editor.</p>
  <?php endif; ?>
  </main>
</body>
</html>
