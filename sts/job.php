<?php
$session = (int) ($_GET['session'] ?? 0);
$job = trim($_GET['job'] ?? '');
if ($session <= 0 || $job === '') {
    header('Location: session.php');
    exit;
}

$sts_dir = __DIR__;
require_once $sts_dir . '/open_db.php';
require_once $sts_dir . '/session_helpers.php';

$root = session_web_root();
$manifest = session_load_manifest($session, $root);
session_ensure_output_stubs($session, $manifest, $root);

$job_meta = $manifest['jobs'][$job] ?? ['phases' => []];
$phase_nums = $job_meta['phases'] ?? [];

$switchlist_styles = session_switchlist_styles();
$selected_style = session_normalize_switchlist_style(
    $_GET['style'] ?? ($manifest['preferred_switchlist_style'] ?? 'mobile')
);
$style_available = session_session_style_available($session, $selected_style, $manifest, $root);

$dbc = open_db();
$legs = session_train_switchlist_legs($dbc, $session, $job, $root);
$current_db_session = session_get_db_session($dbc);
mysqli_close($dbc);

$browser_sessions = session_list_browser_sessions($current_db_session, $root);
$prev_session = session_adjacent_session($browser_sessions, $session, 'prev');
$next_session = session_adjacent_session($browser_sessions, $session, 'next');

$phase_links = session_train_switchlist_phase_links($session, $job, $phase_nums, $root);

$session_wb = session_output_url('session_' . $session . '/waybills');
$has_session_wb = is_file(session_output_fs_path('session_' . $session . '/waybills/index.html', $root));
$has_session_wb_print = session_waybills_bundle_ready($session, null, $root);
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
    ['href' => 'session.php?session=' . (int) $session, 'label' => 'All Sessions', 'icon' => 'collection'],
    ['href' => 'session_switchlists.php?session=' . (int) $session, 'label' => 'All switch lists', 'icon' => 'list-check'],
], 'Train ' . $job);
?>
  <main>
    <h1><?php echo htmlspecialchars($job); ?> — session <?php echo (int) $session; ?></h1>
    <p class="muted">Switch lists and the waybills for the cars on each phase.</p>
    <div class="session-nav-row session-nav-row-stats">
      <?php if ($prev_session !== null): ?>
        <a class="btn btn-outline-dark btn-sm" href="job.php?session=<?php echo (int) $prev_session; ?>&amp;job=<?php echo urlencode($job); ?>&amp;style=<?php echo urlencode($selected_style); ?>"><i class="bi bi-chevron-left"></i> Session <?php echo (int) $prev_session; ?></a>
      <?php else: ?>
        <span class="btn btn-outline-dark btn-sm disabled" aria-disabled="true"><i class="bi bi-chevron-left"></i> Previous</span>
      <?php endif; ?>
      <span class="job-session-current">Session <?php echo (int) $session; ?></span>
      <?php if ($next_session !== null): ?>
        <a class="btn btn-outline-dark btn-sm" href="job.php?session=<?php echo (int) $next_session; ?>&amp;job=<?php echo urlencode($job); ?>&amp;style=<?php echo urlencode($selected_style); ?>">Session <?php echo (int) $next_session; ?> <i class="bi bi-chevron-right"></i></a>
      <?php else: ?>
        <span class="btn btn-outline-dark btn-sm disabled" aria-disabled="true">Next <i class="bi bi-chevron-right"></i></span>
      <?php endif; ?>
    </div>

    <?php if (count($legs)): ?>
      <div class="card">
        <div class="job-switchlist-controls">
          <form class="session-style-form" id="switchlist-style-form" onsubmit="return false;">
            <label>
              <span>Switch list style</span>
              <select id="switchlist-style" name="style">
                <?php foreach ($switchlist_styles as $style_key => $style_label): ?>
                  <option value="<?php echo htmlspecialchars($style_key); ?>"<?php echo $style_key === $selected_style ? ' selected' : ''; ?>>
                    <?php echo htmlspecialchars($style_label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <span class="muted" id="switchlist-style-status" style="margin-left:8px;">
              <?php echo $style_available ? 'Ready' : 'Will generate on select'; ?>
            </span>
          </form>
          <div class="job-phase-nav">
            <button type="button" class="btn btn-outline-dark btn-sm" id="phase-prev"><i class="bi bi-chevron-left"></i> Prev phase</button>
            <span class="job-phase-label" id="phase-label">Phase 1 of <?php echo count($legs); ?></span>
            <button type="button" class="btn btn-outline-dark btn-sm" id="phase-next">Next phase <i class="bi bi-chevron-right"></i></button>
          </div>
        </div>

        <div id="switchlist-legs">
          <?php foreach ($legs as $i => $leg): ?>
            <?php
              $leg_open = htmlspecialchars($leg['base_href'] . '_' . $selected_style . '.html');
              $leg_src = htmlspecialchars($leg['base_href'] . '_' . $selected_style . '.html&embed=1');
            ?>
            <section class="switchlist-leg"
                     data-leg="<?php echo (int) $i; ?>"
                     data-base="<?php echo htmlspecialchars($leg['base_href']); ?>"
                     style="<?php echo $i === 0 ? '' : 'display:none;'; ?>">
              <h2 class="switchlist-leg-title">
                Phase <?php echo (int) $leg['work_leg']; ?> of <?php echo (int) $leg['work_leg_total']; ?>
                <span class="muted">— <?php echo htmlspecialchars($leg['label']); ?></span>
                <a class="switchlist-leg-open" href="<?php echo $leg_open; ?>" target="_blank" rel="noopener" data-base="<?php echo htmlspecialchars($leg['base_href']); ?>"><i class="bi bi-box-arrow-up-right"></i> Open</a>
              </h2>
              <iframe class="switchlist-frame"
                      title="Switch list phase <?php echo (int) $leg['work_leg']; ?>"
                      data-base="<?php echo htmlspecialchars($leg['base_href']); ?>"
                      src="<?php echo $leg_src; ?>"></iframe>
              <div class="switchlist-leg-waybills">
                <h3>Waybills on this switch list</h3>
                <?php if (count($leg['waybills'])): ?>
                  <ul>
                    <?php foreach ($leg['waybills'] as $wb): ?>
                      <li>
                        <?php if ($wb['href'] !== null): ?>
                          <a href="<?php echo htmlspecialchars($wb['href']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($wb['number']); ?></a>
                        <?php else: ?>
                          <?php echo htmlspecialchars($wb['number']); ?> <span class="muted">(print file not generated)</span>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <p class="muted" style="margin:0;">No waybills matched the cars on this switch list yet.</p>
                <?php endif; ?>
              </div>
            </section>
          <?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="card">
        <p class="muted">No switch lists generated for this train yet. Run <em>Generate Switch Lists</em> in the Session Editor workflow.</p>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2 style="margin:0 0 8px;font-size:16px;">Session switch lists</h2>
      <?php if (count($phase_links)): ?>
        <ul>
          <?php foreach ($phase_links as $pl): ?>
            <li>
              Phase <?php echo (int) $pl['phase']; ?>:
              <?php if ($pl['has_index']): ?>
                <a href="<?php echo htmlspecialchars($pl['index_href']); ?>">browse</a>
              <?php else: ?>
                <span class="muted">not generated</span>
              <?php endif; ?>
              <?php if ($pl['has_print']): ?>
                · <a href="<?php echo htmlspecialchars($pl['print_href']); ?>">print all switch lists</a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted" style="margin:0;">No switch-list phases for this train.</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 style="margin:0 0 8px;font-size:16px;">Session waybills</h2>
      <ul>
        <li><a href="<?php echo htmlspecialchars($session_wb . '/index.html'); ?>">All session waybills</a>
          <?php if ($has_session_wb_print): ?>
            · <a href="<?php echo htmlspecialchars($session_wb . '/print_all.html'); ?>">print all</a>
          <?php elseif (!$has_session_wb): ?>
            <span class="muted"> (none generated yet)</span>
          <?php endif; ?>
        </li>
      </ul>
    </div>
  </main>

  <script>
    (function () {
      const legs = Array.from(document.querySelectorAll('.switchlist-leg'));
      const styleSelect = document.getElementById('switchlist-style');
      const styleStatus = document.getElementById('switchlist-style-status');
      const phaseLabel = document.getElementById('phase-label');
      const prevBtn = document.getElementById('phase-prev');
      const nextBtn = document.getElementById('phase-next');
      const sessionId = <?php echo (int) $session; ?>;
      const storageKey = 'session_switchlist_style';
      let current = 0;

      const savedStyle = localStorage.getItem(storageKey);
      if (savedStyle && styleSelect && !window.location.search.includes('style=')) {
        styleSelect.value = savedStyle;
      }

      function showLeg(idx) {
        if (!legs.length) return;
        current = Math.max(0, Math.min(idx, legs.length - 1));
        legs.forEach(function (leg, i) {
          leg.style.display = i === current ? '' : 'none';
        });
        if (phaseLabel) {
          phaseLabel.textContent = 'Phase ' + (current + 1) + ' of ' + legs.length;
        }
        if (prevBtn) prevBtn.disabled = current === 0;
        if (nextBtn) nextBtn.disabled = current === legs.length - 1;
      }

      function applyStyle(style) {
        document.querySelectorAll('[data-base]').forEach(function (el) {
          const base = el.getAttribute('data-base');
          if (!base) return;
          const url = base + '_' + style + '.html';
          if (el.tagName === 'IFRAME') {
            el.setAttribute('src', url + '&embed=1');
          } else if (el.tagName === 'A') {
            el.setAttribute('href', url);
          }
        });
        localStorage.setItem(storageKey, style);
        const params = new URL(window.location.href);
        params.searchParams.set('style', style);
        window.history.replaceState({}, '', params);
      }

      prevBtn?.addEventListener('click', function () { showLeg(current - 1); });
      nextBtn?.addEventListener('click', function () { showLeg(current + 1); });

      styleSelect?.addEventListener('change', async function () {
        const style = styleSelect.value;
        if (styleStatus) styleStatus.textContent = 'Generating…';
        try {
          const resp = await fetch('operational_steps_api.php?action=rerender_session_style', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session: sessionId, style: style }),
          });
          const data = await resp.json();
          if (!data.ok) throw new Error(data.error || 'Generation failed');
          applyStyle(style);
          if (styleStatus) styleStatus.textContent = data.skipped ? 'Ready (cached)' : 'Generated';
        } catch (err) {
          if (styleStatus) styleStatus.textContent = String(err.message || err);
        }
      });

      showLeg(0);
      if (styleSelect) applyStyle(styleSelect.value);
    })();
  </script>
</body>
</html>
