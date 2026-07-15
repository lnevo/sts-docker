<?php
$session = (int) ($_GET['session'] ?? 0);
$job = trim($_GET['job'] ?? '');
if ($session <= 0 || $job === '') {
    header('Location: session_overview.php');
    exit;
}

$sts_dir = __DIR__;
require_once $sts_dir . '/open_db.php';
require_once $sts_dir . '/session_helpers.php';

// If a rewound DB no longer has this session, bounce to the latest overview.
session_redirect_if_beyond_current($session);

$root = session_web_root();
$manifest = session_load_manifest($session, $root);
session_ensure_output_stubs($session, $manifest, $root);

$job_meta = $manifest['jobs'][$job] ?? ['phases' => []];
$phase_nums = $job_meta['phases'] ?? [];

$switchlist_styles = session_switchlist_styles();
$selected_style = session_normalize_switchlist_style(
    $_GET['style'] ?? ($manifest['preferred_switchlist_style'] ?? 'mobile')
);

// Operator-facing train names (from the workflow's Generate Switch Lists titles)
// used for the train picker, page title and nav trail. Falls back to job keys.
$train_display = session_job_display_map($session, $manifest, $root);
$train_groups = session_job_group_map($session, $manifest, $root);
$job_display = $train_display[$job] ?? $job;

// A "train" here is the consolidated group of jobs that share the same display
// name (e.g. STG-DEMMLER + D749 both shown as "D749"). Merge every member's
// switch-list legs into one phase-ordered sequence so the viewer shows the whole
// train, and remember each leg's source job for its waybill links.
$train_members = session_train_group_members($session, $job, $manifest, $root);

$dbc = open_db();
// Render the requested style on demand so the inline iframes below never point at
// a style file that hasn't been generated yet (avoids "Not found" in the viewer).
$style_available = session_ensure_style_rendered($dbc, $session, $selected_style, $manifest, $root);
$legs = [];
foreach ($train_members as $member_job) {
    foreach (session_train_switchlist_legs($dbc, $session, $member_job, $root) as $leg) {
        $leg['job'] = $member_job;
        $legs[] = $leg;
    }
}
// Rank by manifest/operational order, not phase_NN folder numbers (a
// reconstructed Outbound can live in a higher-numbered folder than Return).
$phase_rank = session_phase_display_rank($manifest);
usort($legs, static function ($a, $b) use ($phase_rank) {
    $wa = (int) $a['workflow_phase'];
    $wb = (int) $b['workflow_phase'];
    $ra = $phase_rank[$wa] ?? $wa;
    $rb = $phase_rank[$wb] ?? $wb;

    return [$ra, (int) $a['work_leg']] <=> [$rb, (int) $b['work_leg']];
});
// Build/cache this train's all-phases print-all switch list while the DB handle
// is open (the "Train switch lists" nav target below).
$train_print_rel = session_build_switchlist_train_print_all($dbc, $session, $job, $root);
$current_db_session = session_get_db_session($dbc);
mysqli_close($dbc);

$browser_sessions = session_list_browser_sessions($current_db_session, $root);
$prev_session = session_adjacent_session($browser_sessions, $session, 'prev');
$next_session = session_adjacent_session($browser_sessions, $session, 'next');

// Phase print-all links across every member of the consolidated train.
$phase_links = [];
foreach ($train_members as $member_job) {
    $member_phases = $manifest['jobs'][$member_job]['phases'] ?? [];
    // Restrict to the latest generation token so re-runs that appended phases to
    // the same session don't surface stale historical copies.
    $token_phase_nums = session_job_latest_token_phase_nums($manifest, $member_job);
    if ($token_phase_nums !== []) {
        $member_phases = array_values(array_filter($member_phases, static function ($p) use ($token_phase_nums) {
            return isset($token_phase_nums[(int) $p]);
        }));
    }
    foreach (session_train_switchlist_phase_links($session, $member_job, $member_phases, $root) as $pl) {
        $phase_links[] = $pl;
    }
}

// Cross-scope navigation targets. Switch-list links prefer the "print all"
// (concatenated, printable) view per the operator's request; waybill links do
// the same. Each falls back to the plain index when the print-all file is
// missing, and to the session overview as a last resort.
$session_sw_print_rel = 'session_' . $session . '/print_all.html';
$session_sw_print_href = is_file(session_output_fs_path($session_sw_print_rel, $root))
    ? session_output_url($session_sw_print_rel)
    : session_session_index_href($session);

$session_wb_print_rel = 'session_' . $session . '/waybills/print_all.html';
$session_wb_index_rel = 'session_' . $session . '/waybills/index.html';
$session_wb_href = is_file(session_output_fs_path($session_wb_print_rel, $root))
    ? session_output_url($session_wb_print_rel)
    : (is_file(session_output_fs_path($session_wb_index_rel, $root))
        ? session_output_url($session_wb_index_rel)
        : session_session_index_href($session));

// "Train switch lists" opens this train's print-all switch list — every phase the
// train runs, concatenated into one printable document (the switch-list analogue
// of the per-train waybill bundle), built above. Falls back to the session-wide
// print-all if the train has none.
$job_switchlists_print_href = $train_print_rel !== null
    ? session_output_url($train_print_rel)
    : $session_sw_print_href;

// Job-scoped waybills: prefer this train's print-all waybills, then its index.
$job_wb_print_rel = 'session_' . $session . '/waybills/job_' . $job . '.print_all.html';
$job_wb_index_rel = 'session_' . $session . '/waybills/job_' . $job . '.index.html';
$job_wb_href = is_file(session_output_fs_path($job_wb_print_rel, $root))
    ? session_output_url($job_wb_print_rel)
    : (is_file(session_output_fs_path($job_wb_index_rel, $root))
        ? session_output_url($job_wb_index_rel)
        : $session_wb_href);

// A consolidated train spans multiple job keys and has no single per-train
// waybill bundle, so its "Train waybills" link uses the complete session-scope
// waybills instead of one member's partial file. (The train switch-list print-all
// built above already spans every member, so it needs no such fallback.)
if (count($train_members) > 1) {
    $job_wb_href = $session_wb_href;
}

// Preselect the work-leg when arriving from a phase index link
// (job.php?...&wp=<workflow phase>&leg=<work leg>).
$req_wp = isset($_GET['wp']) ? (int) $_GET['wp'] : 0;
$req_leg = isset($_GET['leg']) ? (int) $_GET['leg'] : 0;
$initial_leg = 0;
if ($req_wp > 0 && $req_leg > 0) {
    foreach ($legs as $i => $lg) {
        if ((int) ($lg['workflow_phase'] ?? 0) === $req_wp && (int) ($lg['work_leg'] ?? 0) === $req_leg) {
            $initial_leg = $i;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($job_display); ?> — session <?php echo (int) $session; ?></title>
  <?php echo session_static_head_assets('session-nav.css'); ?>
</head>
<body>
<?php
session_render_nav_bar([
    ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
    ['href' => session_session_index_href($session), 'label' => 'Session ' . (int) $session, 'icon' => 'calendar-event'],
    ['href' => $job_switchlists_print_href, 'label' => 'Train switch lists', 'icon' => 'printer'],
    ['href' => $job_wb_href, 'label' => 'Train waybills', 'icon' => 'file-text'],
    ['href' => $session_sw_print_href, 'label' => 'Session switch lists', 'icon' => 'list-task'],
    ['href' => $session_wb_href, 'label' => 'Session waybills', 'icon' => 'files'],
], 'Train ' . $job_display);
?>
  <main>
    <h1>Session Overview</h1>
    <p class="muted">Switch lists and the waybills for the cars on each phase.</p>
    <div class="session-nav-row session-nav-row-stats">
      <?php if ($prev_session !== null): ?>
        <?php echo session_nav_chevron_btn_html('prev', session_train_browse_href($prev_session, $job_display, $selected_style, $root), $prev_session); ?>
      <?php else: ?>
        <?php echo session_nav_chevron_btn_html('prev', null); ?>
      <?php endif; ?>
      <label class="job-session-select-label">
        <span class="visually-hidden">Session</span>
        <select class="form-select form-select-sm job-session-select" onchange="if (this.value) window.location.href = this.value;">
          <?php foreach ($browser_sessions as $s): ?>
            <option value="<?php echo htmlspecialchars(session_train_browse_href($s, $job_display, $selected_style, $root)); ?>"<?php echo $s === $session ? ' selected' : ''; ?>>Session <?php echo (int) $s; ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($next_session !== null): ?>
        <?php echo session_nav_chevron_btn_html('next', session_train_browse_href($next_session, $job_display, $selected_style, $root), $next_session); ?>
      <?php else: ?>
        <?php echo session_nav_chevron_btn_html('next', null); ?>
      <?php endif; ?>
      <label class="job-train-select-label">
        <span>Train</span>
        <select class="form-select form-select-sm job-train-select" onchange="if (this.value) window.location.href = this.value;">
          <?php foreach ($train_groups as $group_name => $members): ?>
            <?php $group_primary = $members[0]; $group_current = in_array($job, $members, true); ?>
            <option value="job.php?session=<?php echo (int) $session; ?>&amp;job=<?php echo urlencode($group_primary); ?>&amp;style=<?php echo urlencode($selected_style); ?>"<?php echo $group_current ? ' selected' : ''; ?>><?php echo htmlspecialchars($group_name); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
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
            <button type="button" class="btn btn-outline-dark btn-sm" id="switchlist-reload" style="margin-left:8px;" title="Re-render and reload the switch lists"><i class="bi bi-arrow-clockwise"></i> Reload</button>
          </form>
          <div class="job-phase-nav">
            <button type="button" class="btn btn-outline-dark btn-sm" id="phase-prev"><i class="bi bi-chevron-left"></i> Prev phase</button>
            <?php
              $initial_info = trim((string) ($legs[$initial_leg]['info'] ?? ''));
              $initial_phase_label = $initial_info !== ''
                  ? $initial_info
                  : ('Phase ' . ((int) $initial_leg + 1) . ' of ' . count($legs));
            ?>
            <span class="job-phase-label" id="phase-label"><?php echo htmlspecialchars($initial_phase_label); ?></span>
            <button type="button" class="btn btn-outline-dark btn-sm" id="phase-next">Next phase <i class="bi bi-chevron-right"></i></button>
          </div>
        </div>

        <div id="switchlist-legs">
          <?php foreach ($legs as $i => $leg): ?>
            <?php
              $leg_open = htmlspecialchars($leg['base_href'] . '_' . $selected_style . '.html');
              $leg_src = htmlspecialchars($leg['base_href'] . '_' . $selected_style . '.html&embed=1');
              $leg_info = trim((string) ($leg['info'] ?? ''));
              $leg_title = ($leg_info !== '' && (int) ($leg['work_leg_total'] ?? 0) <= 1)
                  ? $leg_info
                  : ('Phase ' . (int) $leg['work_leg'] . ' of ' . (int) $leg['work_leg_total']);
            ?>
            <section class="switchlist-leg"
                     data-leg="<?php echo (int) $i; ?>"
                     data-base="<?php echo htmlspecialchars($leg['base_href']); ?>"
                     data-info="<?php echo htmlspecialchars($leg_info, ENT_QUOTES); ?>"
                     style="<?php echo $i === $initial_leg ? '' : 'display:none;'; ?>">
              <h2 class="switchlist-leg-title">
                <?php echo htmlspecialchars($leg_title); ?>
                <span class="muted">— <?php echo htmlspecialchars($leg['label']); ?></span>
                <a class="switchlist-leg-open" href="<?php echo $leg_open; ?>" target="_blank" rel="noopener" data-base="<?php echo htmlspecialchars($leg['base_href']); ?>"><i class="bi bi-box-arrow-up-right"></i> Open</a>
              </h2>
              <iframe class="switchlist-frame"
                      title="Switch list phase <?php echo (int) $leg['work_leg']; ?>"
                      data-base="<?php echo htmlspecialchars($leg['base_href']); ?>"
                      src="<?php echo $leg_src; ?>"></iframe>
              <div class="switchlist-leg-waybills">
                <?php
                  $wb_print_href = 'waybills_print.php?session=' . (int) $session
                      . '&job=' . urlencode($leg['job'] ?? $job)
                      . '&wp=' . (int) $leg['workflow_phase']
                      . '&leg=' . (int) $leg['work_leg']
                      . '&style=' . urlencode($selected_style);
                ?>
                <div class="switchlist-leg-waybills-head">
                  <h3>Waybills on this switch list</h3>
                  <?php if (count($leg['waybills'])): ?>
                    <a class="btn btn-outline-dark btn-sm switchlist-leg-print" href="<?php echo htmlspecialchars($wb_print_href); ?>" target="_blank" rel="noopener"><i class="bi bi-printer"></i> Print waybills</a>
                  <?php endif; ?>
                </div>
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
  </main>

  <script>
    (function () {
      const legs = Array.from(document.querySelectorAll('.switchlist-leg'));
      const styleSelect = document.getElementById('switchlist-style');
      const styleStatus = document.getElementById('switchlist-style-status');
      const phaseLabel = document.getElementById('phase-label');
      const prevBtn = document.getElementById('phase-prev');
      const nextBtn = document.getElementById('phase-next');
      const reloadBtn = document.getElementById('switchlist-reload');
      const sessionId = <?php echo (int) $session; ?>;
      const storageKey = 'session_switchlist_style';
      let current = <?php echo (int) $initial_leg; ?>;

      // Reload one iframe (cache-busted) so a freshly (re)rendered style file is
      // fetched instead of a stale response.
      function reloadFrame(frame) {
        const src = frame.getAttribute('src');
        if (!src) return;
        const bust = (src.indexOf('&_=') === -1 && src.indexOf('?_=') === -1)
          ? src + '&_=' + Date.now()
          : src.replace(/([?&])_=\d+/, '$1_=' + Date.now());
        frame.setAttribute('src', bust);
      }

      // When an inline switch-list frame comes back as the "Not found" placeholder
      // (style file not yet written), regenerate the style once and reload it so
      // the operator never has to hand-refresh.
      function guardFrame(frame) {
        if (frame.dataset.recovered === '1') return;
        try {
          const doc = frame.contentDocument;
          if (!doc) return;
          const text = (doc.body ? doc.body.textContent : '').trim();
          if (text === 'Not found' || text === 'Bad path') {
            frame.dataset.recovered = '1';
            const style = styleSelect ? styleSelect.value : '<?php echo htmlspecialchars($selected_style, ENT_QUOTES); ?>';
            fetch('operational_steps_api.php?action=rerender_session_style', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ session: sessionId, style: style }),
            }).then(function () { reloadFrame(frame); });
          }
        } catch (e) { /* cross-origin or blank: ignore */ }
      }

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
          const info = (legs[current] && legs[current].dataset.info) ? legs[current].dataset.info.trim() : '';
          phaseLabel.textContent = info !== ''
            ? info
            : ('Phase ' + (current + 1) + ' of ' + legs.length);
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

      document.querySelectorAll('.switchlist-frame').forEach(function (frame) {
        frame.addEventListener('load', function () { guardFrame(frame); });
      });

      reloadBtn?.addEventListener('click', async function () {
        const style = styleSelect ? styleSelect.value : '<?php echo htmlspecialchars($selected_style, ENT_QUOTES); ?>';
        if (styleStatus) styleStatus.textContent = 'Reloading…';
        try {
          await fetch('operational_steps_api.php?action=rerender_session_style', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session: sessionId, style: style, force: true }),
          });
        } catch (e) { /* reload anyway */ }
        document.querySelectorAll('.switchlist-frame').forEach(reloadFrame);
        if (styleStatus) styleStatus.textContent = 'Reloaded';
      });

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

      showLeg(current);
      if (styleSelect) applyStyle(styleSelect.value);
    })();
  </script>
</body>
</html>
