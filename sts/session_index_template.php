<?php
/** Per-session index — included or copied into session_N/index.php by generator. */
$session = (int) preg_replace('/^session_(\d+)$/', '$1', basename(dirname(__FILE__)));
$sts_dir = dirname(__DIR__, 2) . '/sts';
if (!is_dir($sts_dir)) {
    $sts_dir = dirname(__DIR__) . '/../sts';
}
require_once $sts_dir . '/session_helpers.php';
$root = session_web_root();
$manifest = session_load_manifest($session, $root);
session_ensure_output_stubs($session, $manifest, $root);
$phases = $manifest['phases'] ?? [];
$jobs = $manifest['jobs'] ?? [];
$session_waybills = session_dir_for($session, $root) . '/waybills';
$has_session_waybills = is_file($session_waybills . '/index.html');
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
    ['href' => '/sts/session.php', 'label' => 'All Sessions', 'icon' => 'collection'],
    ['href' => '/sts/editor.html', 'label' => 'Session Editor', 'icon' => 'pencil-square'],
], 'Session ' . (int) $session);
?>
  <main>
  <h1>Session <?php echo (int) $session; ?></h1>
  <div class="card card-highlight">
    <h2 style="margin:0 0 8px;">Waybills</h2>
    <?php if ($has_session_waybills && is_file($session_waybills . '/print_all.html') && filesize($session_waybills . '/print_all.html') > 800): ?>
      <p>Print every freight waybill generated for this operating session.</p>
      <p><a href="waybills/print_all.html"><strong>Print all session waybills</strong></a>
        · <a href="waybills/index.html">Browse waybill list</a></p>
    <?php else: ?>
      <p>No waybill files yet. Run <em>Generate Waybill List</em> in the workflow after switch lists.</p>
      <p><a href="waybills/index.html">Waybill index</a></p>
    <?php endif; ?>
  </div>
  <?php foreach ($phases as $phase): ?>
    <div class="card">
      <h2 style="margin:0 0 8px;">Phase <?php echo (int) ($phase['phase'] ?? 0); ?></h2>
      <p><?php echo htmlspecialchars($phase['label'] ?? 'Generate Switch Lists'); ?></p>
      <ul>
        <?php foreach ($phase['jobs'] ?? [] as $job): ?>
          <li><a href="../job.php?session=<?php echo (int) $session; ?>&amp;job=<?php echo urlencode($job); ?>"><?php echo htmlspecialchars($job); ?></a></li>
        <?php endforeach; ?>
      </ul>
      <p><a href="phase_<?php echo str_pad((int) ($phase['phase'] ?? 1), 2, '0', STR_PAD_LEFT); ?>/waybills/index.html">Waybills (phase)</a>
        <?php
          $phase_wb = session_dir_for($session, $root) . '/phase_'
            . str_pad((int) ($phase['phase'] ?? 1), 2, '0', STR_PAD_LEFT) . '/waybills/print_all.html';
          if (is_file($phase_wb) && filesize($phase_wb) > 800):
        ?>
          · <a href="phase_<?php echo str_pad((int) ($phase['phase'] ?? 1), 2, '0', STR_PAD_LEFT); ?>/waybills/print_all.html">Print all (phase)</a>
        <?php endif; ?>
      </p>
    </div>
  <?php endforeach; ?>
  <?php if (!count($phases)): ?>
    <p class="muted">No switch-list phases yet. Run the workflow generator from the editor.</p>
  <?php endif; ?>
  <?php if (count($jobs)): ?>
    <h2>Trains</h2>
    <ul>
      <?php foreach (array_keys($jobs) as $job): ?>
        <li><a href="../job.php?session=<?php echo (int) $session; ?>&amp;job=<?php echo urlencode($job); ?>"><?php echo htmlspecialchars($job); ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  </main>
</body>
</html>
