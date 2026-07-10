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
$on_disk = session_discover_sessions($root);
$max_session = max($current, $on_disk ? max($on_disk) : 0);
$sessions = range(1, max(1, $max_session));
$sessions = array_reverse($sessions);

$selected = isset($_GET['session']) ? (int) $_GET['session'] : $current;
if ($selected < 1 || $selected > $max_session) {
    $selected = $current;
}

$manifest = session_load_manifest($selected, $root);
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
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 0; background: #f4f6f8; color: #1a1a1a; }
    .nav { background: #1f4d2e; padding: 12px 16px; }
    .nav a { color: #fff; text-decoration: none; margin-right: 12px; }
    main { max-width: 960px; margin: 0 auto; padding: 16px; }
    .card { background: #fff; border: 1px solid #d8dee4; border-radius: 10px; padding: 14px; margin-bottom: 12px; }
    .card-editor { border-color: #1f4d2e; background: #f8fbf9; }
    .card-editor a.button { display: inline-block; background: #1f4d2e; color: #fff; text-decoration: none; padding: 10px 16px; border-radius: 8px; font-weight: 600; }
    h1 { margin: 0 0 8px; font-size: 22px; }
    h2 { margin: 0 0 8px; font-size: 18px; }
    .muted { color: #666; font-size: 14px; }
    ul { margin: 8px 0 0; padding-left: 18px; }
    a { color: #1f4d2e; }
    .badge { display: inline-block; background: #e8f4ea; color: #1f4d2e; border-radius: 999px; padding: 2px 8px; font-size: 12px; margin-left: 6px; }
    .session-go-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-top: 12px; }
    .session-go-form label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: #444; min-width: 220px; flex: 1; }
    .session-go-form select { padding: 8px 10px; border: 1px solid #c8d0d8; border-radius: 8px; font-size: 14px; }
    .session-go-form button { padding: 9px 18px; border: 0; border-radius: 8px; background: #1f4d2e; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; }
  </style>
</head>
<body>
  <nav class="nav">
    <a href="/sts/index.html">STS</a>
  </nav>
  <main>
    <div class="card card-editor">
      <h2 style="margin:0 0 8px;">Session Editor</h2>
      <p class="muted" style="margin:0 0 12px;">Edit operating recipes, run the simulator, and generate switch lists and waybills.</p>
      <a class="button" href="editor.html">Open Session Editor</a>
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
        <button type="submit">Go</button>
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
