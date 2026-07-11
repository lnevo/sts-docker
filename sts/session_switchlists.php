<?php
/**
 * Consolidated switch-list index for one operating session (layout owner view).
 */
$session = (int) ($_GET['session'] ?? 0);
if ($session < 1) {
    header('Location: session.php');
    exit;
}

$sts_dir = __DIR__;
require_once $sts_dir . '/open_db.php';
require_once $sts_dir . '/session_helpers.php';

$root = session_web_root();
$manifest = session_load_manifest($session, $root);
session_ensure_output_stubs($session, $manifest, $root);
$phases = $manifest['phases'] ?? [];
$selected_style = session_normalize_switchlist_style(
    $_GET['style'] ?? ($manifest['preferred_switchlist_style'] ?? 'mobile')
);
$session_dir = session_dir_for($session, $root);
$legacy_index = $session_dir . '/index.html';
$has_legacy = is_file($legacy_index) && filesize($legacy_index) > 400;
$has_phased = session_manifest_has_switchlists($manifest, $session, $root);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>All switch lists — session <?php echo (int) $session; ?></title>
  <?php echo session_static_head_assets('session-nav.css'); ?>
</head>
<body>
<?php
session_render_nav_bar([
    ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
    ['href' => 'session.php?session=' . (int) $session, 'label' => 'All Sessions', 'icon' => 'collection'],
    ['href' => session_session_index_href($session), 'label' => 'Session ' . (int) $session, 'icon' => 'calendar-event'],
], 'All switch lists');
?>
  <main>
    <h1>All switch lists — session <?php echo (int) $session; ?></h1>
    <p class="muted">Consolidated view of every train and workflow phase for this session. Style: <strong><?php echo htmlspecialchars(session_switchlist_styles()[$selected_style] ?? $selected_style); ?></strong></p>

    <?php if ($has_legacy): ?>
    <div class="card">
      <h2 style="margin:0 0 8px;">Session switch list index</h2>
      <p>Generated switch-list landing page for this session.</p>
      <p>
        <a href="<?php echo htmlspecialchars(session_output_url('session_' . (int) $session . '/index.html')); ?>"><strong>Browse session switch lists</strong></a>
        <?php
          $legacy_print = session_output_fs_path('session_' . (int) $session . '/print_all.html', $root);
          if (is_file($legacy_print) && filesize($legacy_print) > 800):
        ?>
          · <a href="<?php echo htmlspecialchars(session_output_url('session_' . (int) $session . '/print_all.html')); ?>">Print all</a>
        <?php endif; ?>
      </p>
    </div>
    <?php endif; ?>

    <?php if (count($phases)): ?>
      <?php foreach ($phases as $phase): ?>
        <?php
          $phase_num = (int) ($phase['phase'] ?? 0);
          if ($phase_num < 1) {
              continue;
          }
          $phase_jobs = $phase['jobs'] ?? [];
          $phase_print = session_switchlist_phase_print_href($session, $phase_num);
          $has_phase_print = is_file(session_output_fs_path('session_' . (int) $session . '/phase_' . session_phase_pad($phase_num) . '/print_all.html', $root))
            && filesize(session_output_fs_path('session_' . (int) $session . '/phase_' . session_phase_pad($phase_num) . '/print_all.html', $root)) > 800;
        ?>
        <div class="card">
          <h2 style="margin:0 0 8px;">Phase <?php echo (int) $phase_num; ?></h2>
          <p><?php echo htmlspecialchars($phase['label'] ?? 'Generate Switch Lists'); ?></p>
          <?php if (count($phase_jobs)): ?>
            <ul>
              <?php foreach ($phase_jobs as $job): ?>
                <?php
                  $job = trim((string) $job);
                  if ($job === '') {
                      continue;
                  }
                  $sw_href = session_switchlist_job_href($session, $phase_num, $job, $selected_style, 1, $root);
                  $has_sw = session_switchlist_style_file_exists($session, $phase_num, $job, $selected_style, $root);
                ?>
                <li>
                  <a href="<?php echo htmlspecialchars($sw_href); ?>"><?php echo htmlspecialchars($job); ?> switch list</a>
                  · <a href="job.php?session=<?php echo (int) $session; ?>&amp;job=<?php echo urlencode($job); ?>">train overview</a>
                  <?php if (!$has_sw): ?><span class="muted"> (not generated)</span><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="muted">No trains in this phase.</p>
          <?php endif; ?>
          <?php if ($has_phase_print): ?>
            <p><a href="<?php echo htmlspecialchars($phase_print); ?>"><strong>Print all switch lists (phase)</strong></a></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php elseif (!$has_legacy): ?>
      <div class="card">
        <p class="muted">No switch lists yet. Run <em>Generate Switch Lists</em> in the Session Editor workflow.</p>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
