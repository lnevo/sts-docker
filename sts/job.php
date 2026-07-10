<?php
$session = (int) ($_GET['session'] ?? 0);
$job = trim($_GET['job'] ?? '');
if ($session <= 0 || $job === '') {
    header('Location: session.php');
    exit;
}

$sts_dir = __DIR__;
require_once $sts_dir . '/session_helpers.php';

$root = session_web_root();
$manifest = session_load_manifest($session, $root);
session_ensure_output_stubs($session, $manifest, $root);
$job_meta = $manifest['jobs'][$job] ?? ['phases' => []];
$phase_nums = $job_meta['phases'] ?? [];
$session_wb = 'session_' . $session . '/waybills';
$has_session_wb = is_file($root . '/' . $session_wb . '/index.html');
$has_session_wb_print = is_file($root . '/' . $session_wb . '/print_all.html')
    && filesize($root . '/' . $session_wb . '/print_all.html') > 800;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($job); ?> — session <?php echo (int) $session; ?></title>
  <?php echo session_static_head_assets('session-nav.css'); ?>
</head>
<body>
<?php
session_render_nav_bar([
    ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
    ['href' => 'session.php', 'label' => 'All Sessions', 'icon' => 'collection'],
    ['href' => 'session_' . (int) $session . '/index.php', 'label' => 'Session ' . (int) $session, 'icon' => 'calendar-event'],
], 'Train ' . $job);
?>
  <main>
    <h1><?php echo htmlspecialchars($job); ?> — session <?php echo (int) $session; ?></h1>

    <div class="card">
      <h2 style="margin:0 0 8px;font-size:16px;">Switch lists</h2>
      <?php if (count($phase_nums)): ?>
        <ul>
          <?php foreach ($phase_nums as $p): ?>
            <?php
              $phase_dir = 'session_' . $session . '/phase_' . str_pad((int) $p, 2, '0', STR_PAD_LEFT) . '/' . rawurlencode($job);
              $index = $phase_dir . '/index.html';
            ?>
            <li>Phase <?php echo (int) $p; ?> — <a href="<?php echo htmlspecialchars($index); ?>">open switch list index</a></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted">No switch-list phases for this train.</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 style="margin:0 0 8px;font-size:16px;">Waybills</h2>
      <ul>
        <li><a href="<?php echo htmlspecialchars($session_wb . '/index.html'); ?>">All session waybills</a>
          <?php if ($has_session_wb_print): ?>
            · <a href="<?php echo htmlspecialchars($session_wb . '/print_all.html'); ?>">print all</a>
          <?php else: ?>
            <span class="muted"> (none generated yet)</span>
          <?php endif; ?>
        </li>
        <?php foreach ($phase_nums as $p): ?>
          <?php
            $wb_dir = 'session_' . $session . '/phase_' . str_pad((int) $p, 2, '0', STR_PAD_LEFT) . '/waybills';
            $wb_index = $wb_dir . '/index.html';
            $wb_print = $wb_dir . '/print_all.html';
          ?>
          <li>Phase <?php echo (int) $p; ?> — <a href="<?php echo htmlspecialchars($wb_index); ?>">waybill list</a>
            <?php if (is_file($root . '/' . $wb_print) && filesize($root . '/' . $wb_print) > 800): ?>
              · <a href="<?php echo htmlspecialchars($wb_print); ?>">print all</a>
            <?php else: ?>
              <span class="muted"> (none generated yet)</span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
        <?php if (!count($phase_nums) && !$has_session_wb): ?>
          <li class="muted">No waybills yet — add a <em>Generate Waybill List</em> step after switch lists.</li>
        <?php endif; ?>
      </ul>
    </div>
  </main>
</body>
</html>
