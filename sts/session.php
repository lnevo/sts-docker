<?php
/**
 * Session browser — session editor entry and per-session output.
 */
$sts_dir = __DIR__;
require_once $sts_dir . '/open_db.php';
require_once $sts_dir . '/session_helpers.php';

$dbc = open_db();
$current = (int) warm_start_get_session($dbc);
mysqli_close($dbc);

$root = session_web_root();
$max_session = max(1, $current);
$sessions = range(1, $max_session);

$selected = isset($_GET['session']) ? (int) $_GET['session'] : $current;
if ($selected < 1 || $selected > $current) {
    $selected = $current;
}

$manifest = session_load_manifest($selected, $root);
session_ensure_output_stubs($selected, $manifest, $root);
$dir = session_dir_for($selected, $root);
$has_data = is_dir($dir);
$phases = $manifest['phases'] ?? [];
$jobs = array_keys($manifest['jobs'] ?? []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Operating Sessions</title>
  <?php echo session_static_head_assets('session-nav.css'); ?>
</head>
<body>
<?php
session_render_nav_bar([
    ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
], 'Operating Sessions');
?>
  <main>
    <div class="card card-editor">
      <h2 style="margin:0 0 8px;">Session Editor</h2>
      <p class="muted" style="margin:0 0 12px;">Edit operating recipes, run the simulator, and generate switch lists and waybills.</p>
      <a class="btn btn-outline-dark btn-sm" href="editor.html"><i class="bi bi-pencil-square"></i> Open Session Editor</a>
    </div>

    <h1>Operating sessions</h1>
    <p class="muted">Current DB session: <strong><?php echo (int) $current; ?></strong>. Each session may have multiple switch-list phases from the workflow generator.</p>

    <div class="card">
      <form method="get" class="session-go-form" action="session.php">
        <label>
          <span>Session</span>
          <select name="session">
            <?php foreach ($sessions as $n): ?>
              <option value="<?php echo (int) $n; ?>"<?php echo $n === $selected ? ' selected' : ''; ?>>
                Session <?php echo (int) $n; ?><?php echo $n === $current ? ' (current)' : ''; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" class="btn btn-outline-dark btn-sm">Go</button>
      </form>
    </div>

    <div class="card">
      <h2>
        <a href="session_<?php echo (int) $selected; ?>/index.php">Session <?php echo (int) $selected; ?></a>
        <?php if ($selected === $current): ?><span class="badge">current</span><?php endif; ?>
        <?php if (!$has_data): ?><span class="badge">no output</span><?php endif; ?>
      </h2>
      <?php if (count($phases)): ?>
        <p class="muted"><?php echo count($phases); ?> phase(s) · <?php echo count($jobs); ?> train(s)</p>
        <ul>
          <?php foreach ($jobs as $job): ?>
            <li><a href="job.php?session=<?php echo (int) $selected; ?>&amp;job=<?php echo htmlspecialchars($job); ?>"><?php echo htmlspecialchars($job); ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted">No phases generated yet.</p>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
