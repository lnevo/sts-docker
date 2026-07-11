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
$run_stats = $manifest['run_stats'] ?? [];
$run_stats['generated'] = session_count_generated_output($manifest);
if ($selected === $current) {
    require_once $sts_dir . '/operations_stats.php';
    $dbc_stats = open_db();
    if (empty($run_stats['station_counts']) && (int) ($run_stats['on_train_count'] ?? 0) === 0) {
        $run_stats['station_counts'] = session_station_car_counts($dbc_stats);
        $run_stats['on_train_count'] = session_on_train_car_count($dbc_stats);
    }
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
    <div class="session-topbar">
      <div class="session-topbar-heading">
        <h1>Operating sessions</h1>
        <p class="muted">Current DB session: <strong><?php echo (int) $current; ?></strong></p>
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
        <a class="btn btn-outline-dark btn-sm" id="all-switchlists-link" href="session_switchlists.php?session=<?php echo (int) $selected; ?>&amp;style=<?php echo urlencode($selected_style); ?>"><i class="bi bi-list-check"></i> All switch lists</a>
        <a class="btn btn-outline-dark btn-sm" href="<?php echo htmlspecialchars(session_output_url('session_' . (int) $selected . '/waybills/index.html')); ?>"><i class="bi bi-file-text"></i> All waybills</a>
        <?php if (session_waybills_bundle_ready($selected, null, $root)): ?>
          <a class="btn btn-outline-dark btn-sm" href="<?php echo htmlspecialchars(session_output_url('session_' . (int) $selected . '/waybills/print_all.html')); ?>"><i class="bi bi-printer"></i> Print all waybills</a>
        <?php endif; ?>
        <form class="session-style-form" id="switchlist-style-form">
          <label>
            <span>Style</span>
            <select id="switchlist-style" name="style">
              <?php foreach ($switchlist_styles as $style_key => $style_label): ?>
                <option value="<?php echo htmlspecialchars($style_key); ?>"<?php echo $style_key === $selected_style ? ' selected' : ''; ?>>
                  <?php echo htmlspecialchars($style_label); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <span class="muted" id="switchlist-style-status"><?php echo $style_available ? 'Ready' : 'Will generate on select'; ?></span>
        </form>
      </div>
      <?php if (!$has_switchlists): ?>
        <p class="muted" style="margin:12px 0 0;">Switch lists not generated yet — run <em>Generate Switch Lists</em> in the Session Editor.</p>
      <?php endif; ?>
      <form method="get" class="session-picker session-picker-footer" action="session.php" id="session-select-form">
        <label class="session-picker-label" for="session-select">Jump to session</label>
        <div class="session-picker-controls">
          <?php if ($prev_session !== null): ?>
            <a class="btn btn-outline-dark btn-sm" href="session.php?session=<?php echo (int) $prev_session; ?>" title="Session <?php echo (int) $prev_session; ?>"><i class="bi bi-chevron-left"></i></a>
          <?php else: ?>
            <span class="btn btn-outline-dark btn-sm disabled" aria-disabled="true"><i class="bi bi-chevron-left"></i></span>
          <?php endif; ?>
          <select name="session" id="session-select">
            <?php foreach ($sessions as $n): ?>
              <option value="<?php echo (int) $n; ?>"<?php echo $n === $selected ? ' selected' : ''; ?>>
                Session <?php echo (int) $n; ?><?php echo $n === $current ? ' (current)' : ''; ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($next_session !== null): ?>
            <a class="btn btn-outline-dark btn-sm" href="session.php?session=<?php echo (int) $next_session; ?>" title="Session <?php echo (int) $next_session; ?>"><i class="bi bi-chevron-right"></i></a>
          <?php else: ?>
            <span class="btn btn-outline-dark btn-sm disabled" aria-disabled="true"><i class="bi bi-chevron-right"></i></span>
          <?php endif; ?>
          <noscript><button type="submit" class="btn btn-outline-dark btn-sm">Go</button></noscript>
        </div>
      </form>
    </div>

    <div class="card">
      <h2 style="margin:0 0 10px;">Trains</h2>
      <?php if (count($jobs)): ?>
        <ul class="phase-list train-tiles">
          <?php foreach ($jobs as $job): ?>
            <?php $job_phase_count = count($jobs_meta[$job]['phases'] ?? []); ?>
            <li>
              <a href="job.php?session=<?php echo (int) $selected; ?>&amp;job=<?php echo urlencode($job); ?>">
                <?php echo htmlspecialchars($job); ?>
                <span class="meta">Switch lists &amp; waybills<?php echo $job_phase_count ? ' · ' . $job_phase_count . ' phase(s)' : ''; ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted" style="margin:0;">No trains recorded yet. Run the workflow generator from the Session Editor.</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 style="margin:0 0 8px;">Session <?php echo (int) $selected; ?> statistics</h2>
      <?php echo session_render_run_stats_block(
          $run_stats,
          'No workflow statistics recorded for this session yet. Run the workflow in the Session Editor while this session is active.'
      ); ?>
    </div>
  </main>
  <script>
    (function () {
      const sessionSelect = document.getElementById('session-select');
      const styleSelect = document.getElementById('switchlist-style');
      const styleStatus = document.getElementById('switchlist-style-status');
      const allLink = document.getElementById('all-switchlists-link');
      const sessionId = <?php echo (int) $selected; ?>;
      const storageKey = 'session_switchlist_style';

      sessionSelect?.addEventListener('change', function () {
        document.getElementById('session-select-form')?.submit();
      });

      const savedStyle = localStorage.getItem(storageKey);
      if (savedStyle && styleSelect && !window.location.search.includes('style=')) {
        styleSelect.value = savedStyle;
      }

      function applyStyleToLinks(style) {
        if (allLink) {
          allLink.href = 'session_switchlists.php?session=' + sessionId + '&style=' + encodeURIComponent(style);
        }
        const url = new URL(window.location.href);
        url.searchParams.set('style', style);
        window.history.replaceState({}, '', url);
        localStorage.setItem(storageKey, style);
      }

      styleSelect?.addEventListener('change', async function () {
        const style = styleSelect.value;
        applyStyleToLinks(style);
        if (styleStatus) {
          styleStatus.textContent = 'Generating…';
        }
        try {
          const resp = await fetch('operational_steps_api.php?action=rerender_session_style', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session: sessionId, style: style }),
          });
          const data = await resp.json();
          if (!data.ok) {
            throw new Error(data.error || 'Generation failed');
          }
          if (styleStatus) {
            styleStatus.textContent = data.skipped ? 'Ready (cached)' : 'Generated';
          }
        } catch (err) {
          if (styleStatus) {
            styleStatus.textContent = String(err.message || err);
          }
        }
      });

      if (styleSelect) {
        applyStyleToLinks(styleSelect.value);
      }
    })();
  </script>
</body>
</html>
