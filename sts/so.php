<?php
/**
 * Session Output server.
 *
 * Generated operating-session files (switch lists, waybills, print bundles) are
 * written under sts/backups/session_state (the host-mounted sts-backups volume,
 * owned by www-data so the web server can create them and so state persists
 * across container recreation). This script streams those files to the browser
 * through a clean URL so the physical storage location is never exposed:
 *
 *   so.php?f=session_3/phase_01/CK1/phase_01_mobile.html
 *
 * For HTML documents, relative links inside the file are rewritten so navigation
 * between generated pages keeps flowing through so.php, links to the per-session
 * overview go to session_overview.php, and links to app files (session.php,
 * /sts/index.html, etc.) resolve normally.
 */

require_once __DIR__ . '/session_helpers.php';

$f = isset($_GET['f']) ? (string) $_GET['f'] : '';
$f = str_replace('\\', '/', $f);
$rel = ltrim($f, '/');

if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Bad path';
    exit;
}

// The per-session overview is a real PHP page, not a static file.
if (preg_match('#^session_(\d+)/index\.(php|html)$#', $rel, $m)) {
    header('Location: session_overview.php?session=' . (int) $m[1]);
    exit;
}

// If the DB was rewound, requests for a session past the current one point at
// output that no longer represents live state — unless archived browse is on.
$req_session_nbr = null;
if (preg_match('#^session_(\d+)/#', $rel, $sm)) {
    $req_session_nbr = (int) $sm[1];
    session_redirect_if_beyond_current($req_session_nbr);
}

$fs = session_output_fs_path($rel);
$archived_output_only = $req_session_nbr !== null && session_is_archived_output_only($req_session_nbr);
// Print-all bundles are built on demand (they aren't pre-generated for every
// train/session). If a requested bundle is missing — e.g. following a prev/next
// session link to a session whose overview was never opened — build it now so
// navigation never dead-ends on "Not found". Also rebuild when the session's
// manifest is newer than the cached bundle, so a bundle always reflects the
// current "latest generation token" even while a session is being (re)generated.
// Skip on-demand rebuild for archived output (would use the live DB, not history).
if (
    !$archived_output_only
    && (
        !is_file($fs)
        || so_print_all_bundle_stale($rel, $fs)
        || so_station_report_stale($rel, $fs)
        || so_wheel_report_stale($rel, $fs)
    )
) {
    $built = so_build_print_all_on_demand($rel);
    if ($built === null) {
        $built = so_build_station_report_on_demand($rel);
    }
    if ($built === null) {
        $built = so_build_wheel_report_on_demand($rel);
    }
    if ($built !== null) {
        $fs = session_output_fs_path($built);
        $rel = $built;
    }
}
if (!is_file($fs)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found';
    exit;
}

$ext = strtolower(pathinfo($fs, PATHINFO_EXTENSION));
$types = [
    'html' => 'text/html; charset=UTF-8',
    'htm' => 'text/html; charset=UTF-8',
    'css' => 'text/css',
    'js' => 'application/javascript',
    'json' => 'application/json',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    'pdf' => 'application/pdf',
    'txt' => 'text/plain; charset=UTF-8',
];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));

if ($ext === 'html' || $ext === 'htm') {
    $dir = dirname($rel);
    $dir = ($dir === '.' || $dir === '/') ? '' : $dir;
    $html = file_get_contents($fs);
    // Waybill index pages bake their prev/next session buttons at generation
    // time, so early sessions (created before later ones existed) end up with a
    // stale or missing nav row. Refresh it against the current set of sessions
    // so navigation is consistent across every session's waybill index.
    $html = so_refresh_waybill_session_nav($html, $rel);
    $html = so_refresh_waybill_print_all_session_nav($html, $rel);
    $html = so_inject_waybill_memo_filter($html, $rel);
    $html = so_inject_waybill_phase_filter($html, $rel);
    $html = so_inject_waybill_selection_index($html, $rel);
    $html = so_inject_waybill_selection_print($html, $rel);
    $html = so_refresh_switchlist_print_all_session_nav($html, $rel);
    $html = so_refresh_switchlist_job_print_all_session_nav($html, $rel);
    $html = so_refresh_switchlist_train_print_all_session_nav($html, $rel);
    $html = so_refresh_station_report_session_nav($html, $rel);
    $html = so_refresh_wheel_report_session_nav($html, $rel);
    // Embedded mode (used by job.php's inline switch-list viewer): drop the
    // top navigation bar since the surrounding page provides navigation and the
    // style selector.
    if (!empty($_GET['embed'])) {
        $html = preg_replace('#<nav\b[^>]*>.*?</nav>#is', '', $html, 1);
    } else {
        // Add a "Phase" filter alongside the Train/Style dropdowns on the
        // combined/per-train print-all switch-list pages so an operator can view
        // one phase at a time. Injected at serve time (like the waybill memo
        // filter) so it works on every previously generated page and is dropped
        // in embed mode, where the surrounding page owns navigation.
        $html = so_inject_switchlist_phase_filter($html, $rel);
    }
    if ($archived_output_only) {
        require_once __DIR__ . '/open_db.php';
        $dbc_banner = open_db();
        $banner = session_archived_view_banner_html($req_session_nbr, session_get_db_session($dbc_banner));
        mysqli_close($dbc_banner);
        if ($banner !== '') {
            $html = preg_replace('#<body([^>]*)>#', '<body$1>' . $banner, $html, 1);
        }
    }
    // Cursor / Electron Simple Browser crashes on window.print(). Rewrite legacy
    // PRINT buttons to stsSafePrint() (injected via session_static_head_assets /
    // a serve-time script fallback below).
    if (strpos($html, 'window.print()') !== false || strpos($html, 'stsSafePrint') !== false) {
        $html = str_replace('onclick="window.print()"', 'onclick="stsSafePrint()"', $html);
        $html = str_replace("onclick='window.print()'", "onclick='stsSafePrint()'", $html);
        if (strpos($html, 'function(){if(window.stsSafePrint)') === false
            && strpos($html, 'window.stsSafePrint=') === false) {
            $html = str_replace('</head>', session_safe_print_script() . '</head>', $html);
            if (strpos($html, 'window.stsSafePrint=') === false) {
                $html = str_replace('</body>', session_safe_print_script() . '</body>', $html);
            }
        }
    }
    echo so_rewrite_html($html, $dir);
} else {
    readfile($fs);
}

/**
 * Build a print-all bundle on demand when its static file is missing, so
 * cross-session prev/next links always resolve. Handles the session-wide combined
 * print-all, the per-style combined print-all, and the per-train print-all.
 * Returns the (possibly new) relative output path, or null when nothing built.
 */
/**
 * A switch-list print-all bundle is stale when the owning session's manifest has
 * been rewritten since the bundle was generated. Rebuilding then re-applies the
 * latest-token filter so repeated generation runs (e.g. a simulator) never leave
 * a bundle showing an old/oversized set of switch lists. Only matches the
 * cheap-to-rebuild switch-list bundles (not waybills or leaf switch-list pages).
 */
function so_print_all_bundle_stale($rel, $fs)
{
    if (!preg_match('#^session_(\d+)/(?:print_all(?:_[a-z0-9_-]+)?|train_.+\.print_all(?:_[a-z0-9_-]+)?)\.html$#', $rel, $m)) {
        return false;
    }
    if (!is_file($fs)) {
        return false;
    }
    $session_dir = session_dir_for((int) $m[1]);
    $bundle_mtime = filemtime($fs);

    $manifest = $session_dir . '/manifest.json';
    if (is_file($manifest) && filemtime($manifest) > $bundle_mtime) {
        return true;
    }

    // Per-style bundles (…print_all_<style>.html) stitch the per-leg switch-list
    // files rendered in that style. A rendering-engine change re-renders those
    // per-leg files but leaves the manifest untouched, so also rebuild when any
    // constituent per-leg file is newer than the cached bundle.
    if (preg_match('#print_all_([a-z0-9]+)\.html$#', $rel, $sm)) {
        $style = $sm[1];
        foreach (glob($session_dir . '/phase_*/*/phase_*_' . $style . '.html') ?: [] as $leg) {
            if (filemtime($leg) > $bundle_mtime) {
                return true;
            }
        }
    }

    return false;
}

function so_station_report_stale($rel, $fs)
{
    if (!preg_match('#^session_(\d+)/station_report\.html$#', $rel, $m)) {
        return false;
    }
    if (!is_file($fs)) {
        return false;
    }

    return session_station_report_stale((int) $m[1], $fs);
}

function so_build_station_report_on_demand($rel)
{
    if (!preg_match('#^session_(\d+)/station_report\.html$#', $rel, $m)) {
        return null;
    }
    require_once __DIR__ . '/open_db.php';
    $dbc = open_db();
    $built = session_build_station_report($dbc, (int) $m[1]);
    mysqli_close($dbc);

    return $built;
}

function so_wheel_report_stale($rel, $fs)
{
    if (!preg_match('#^session_(\d+)/wheel_report\.html$#', $rel, $m)) {
        return false;
    }
    if (!is_file($fs)) {
        return false;
    }

    return session_wheel_report_stale((int) $m[1], $fs);
}

function so_build_wheel_report_on_demand($rel)
{
    if (!preg_match('#^session_(\d+)/wheel_report\.html$#', $rel, $m)) {
        return null;
    }
    require_once __DIR__ . '/open_db.php';
    $dbc = open_db();
    $built = session_build_wheel_report($dbc, (int) $m[1]);
    mysqli_close($dbc);

    return $built;
}

function so_build_print_all_on_demand($rel)
{
    if (!preg_match('#^session_(\d+)/#', $rel)) {
        return null;
    }
    $built = null;
    if (preg_match('#^session_(\d+)/train_(.+)\.print_all_([a-z0-9_-]+)\.html$#', $rel, $m)) {
        require_once __DIR__ . '/open_db.php';
        $dbc = open_db();
        $built = session_build_switchlist_train_print_all_style($dbc, (int) $m[1], rawurldecode((string) $m[2]), (string) $m[3]);
        mysqli_close($dbc);
    } elseif (preg_match('#^session_(\d+)/train_(.+)\.print_all\.html$#', $rel, $m)) {
        require_once __DIR__ . '/open_db.php';
        $dbc = open_db();
        $built = session_build_switchlist_train_print_all($dbc, (int) $m[1], rawurldecode((string) $m[2]));
        mysqli_close($dbc);
    } elseif (preg_match('#^session_(\d+)/print_all_([a-z0-9_-]+)\.html$#', $rel, $m)) {
        require_once __DIR__ . '/open_db.php';
        $dbc = open_db();
        $built = session_build_switchlist_print_all_style($dbc, (int) $m[1], (string) $m[2]);
        mysqli_close($dbc);
    } elseif (preg_match('#^session_(\d+)/print_all\.html$#', $rel, $m)) {
        require_once __DIR__ . '/open_db.php';
        $dbc = open_db();
        $built = session_build_switchlist_print_all($dbc, (int) $m[1]);
        mysqli_close($dbc);
    }

    return $built;
}

/**
 * Rebuild the prev/next session nav on a waybill index page from the current
 * set of sessions. Replaces an existing .waybill-session-nav row if present, or
 * inserts one right after the page <h1> when the original had none (the case
 * for a session generated while it was the only/last one).
 */
function so_refresh_waybill_session_nav($html, $rel)
{
    if (!preg_match('#^session_(\d+)/(?:phase_(\d+)/)?waybills/index\.html$#', $rel, $m)) {
        return $html;
    }
    $session_nbr = (int) $m[1];
    $phase_num = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
    $nav = session_waybill_session_nav_html($session_nbr, $phase_num);
    if ($nav === '') {
        return $html;
    }
    // Replace an existing nav row (baked at generation time) if present.
    if (strpos($html, 'waybill-session-nav') !== false) {
        return preg_replace_callback(
            '#<div class="session-nav-row waybill-session-nav">.*?</div>#s',
            static function () use ($nav) {
                return $nav;
            },
            $html,
            1
        );
    }
    // Otherwise insert it right after the first heading inside <main>.
    return preg_replace_callback(
        '#</h1>#',
        static function () use ($nav) {
            return '</h1>' . $nav;
        },
        $html,
        1
    );
}

/**
 * Rebuild the prev/next session nav on a waybills print-all page
 * (session_N/waybills/<scope>.print_all.html) from the current set of sessions,
 * disabling directions whose target print-all doesn't exist.
 */
function so_refresh_waybill_print_all_session_nav($html, $rel)
{
    if (!preg_match('#^session_(\d+)/waybills/([^/]*print_all\.html)$#', $rel, $m)) {
        return $html;
    }
    $session_nbr = (int) $m[1];
    $basename = (string) $m[2];
    $nav = session_waybill_print_all_session_nav_html($session_nbr, $basename);
    if ($nav === '') {
        return $html;
    }
    if (strpos($html, 'waybill-print-all-session-nav') !== false) {
        return preg_replace_callback(
            '#<div class="session-nav-row waybill-print-all-session-nav[^"]*">.*?</div>#s',
            static function () use ($nav) {
                return $nav;
            },
            $html,
            1
        );
    }

    return preg_replace('#</h1>#', '</h1>' . $nav, $html, 1);
}

/**
 * Add a "Hide company memos" checkbox to a waybills print-all page so operators
 * can print the freight waybills without the empty-repositioning company memos.
 *
 * Injected at serve time (rather than baked at generation) so it works on every
 * previously generated page without regeneration. The filter is client-side:
 * memo blocks are the tables headed "COMPANY MEMO". A sheet that is memo-only is
 * hidden entirely; a sheet that pairs a memo with a freight waybill keeps the
 * freight (and drops the now-needless page break before it). The preference is
 * remembered in localStorage so it carries across sessions.
 */
function so_inject_waybill_memo_filter($html, $rel)
{
    if (!preg_match('#^session_(\d+)/(?:phase_\d+/)?waybills/[^/]*print_all\.html$#', $rel)) {
        return $html;
    }
    if (strpos($html, 'wb-hide-memos') !== false) {
        return $html; // already injected
    }

    $checkbox = '<label class="noprint wb-memo-filter" style="display:inline-flex;align-items:center;gap:6px;'
        . 'margin-left:10px;font:14px system-ui,-apple-system,\'Segoe UI\',Roboto,sans-serif;">'
        . '<input type="checkbox" id="wb-hide-memos"> Hide company memos</label>';
    $html = preg_replace(
        '#(<div class="noprint waybill-print-controls">.*?)(</div>)#s',
        '$1' . $checkbox . '$2',
        $html,
        1
    );

    $style = '<style>'
        . '.waybill-print.hide-memos .wb-memo-block{display:none!important}'
        . '.waybill-print.hide-memos .wb-memo-only{display:none!important}'
        . '.waybill-print.hide-memos .waybill-break-before{page-break-before:auto!important;break-before:auto!important;margin-top:0!important}'
        . '</style>';

    $script = '<script>(function(){'
        . 'var wrap=document.querySelector(".waybill-print");'
        . 'var cb=document.getElementById("wb-hide-memos");'
        . 'if(!wrap||!cb){return;}'
        . 'wrap.querySelectorAll(".waybill-sheet").forEach(function(sheet){'
        . 'var memoTable=null,hasFreight=false;'
        . 'sheet.querySelectorAll("h3").forEach(function(h){'
        . 'var t=(h.textContent||"").trim().toUpperCase();'
        . 'if(t==="COMPANY MEMO"){var p=h.closest("table");'
        . 'while(p&&p.parentElement){var up=p.parentElement.closest("table");if(!up||!sheet.contains(up)){break;}p=up;}'
        . 'memoTable=p;}else if(t==="FREIGHT WAYBILL"){hasFreight=true;}});'
        . 'if(memoTable){memoTable.classList.add("wb-memo-block");'
        . 'sheet.classList.add(hasFreight?"wb-has-memo":"wb-memo-only");}});'
        . 'var KEY="wbHideMemos";'
        . 'try{cb.checked=localStorage.getItem(KEY)==="1";}catch(e){}'
        . 'function apply(){wrap.classList.toggle("hide-memos",cb.checked);'
        . 'try{localStorage.setItem(KEY,cb.checked?"1":"0");}catch(e){}}'
        . 'cb.addEventListener("change",apply);apply();'
        . '})();</script>';

    return str_replace('</body>', $style . $script . '</body>', $html);
}

/**
 * Add a "Phase" filter dropdown next to the Train dropdown on waybill print-all
 * pages (session-wide and per-train bundles). Selecting a phase shows only the
 * waybills captured for that phase; "All phases" (default) shows everything.
 *
 * Injected at serve time so it works on every previously generated page without
 * regeneration. Per-phase bundles (phase_XX_<job>.print_all.html) already show
 * a single phase and are left alone. The control hides itself when the scoped
 * page has fewer than two phases.
 */
function so_inject_waybill_phase_filter($html, $rel)
{
    if (!preg_match('#^session_(\d+)/waybills/([^/]*print_all\.html)$#', $rel, $m)) {
        return $html;
    }
    if (strpos($html, 'id="wb-phase-select"') !== false) {
        return $html; // already injected
    }

    $sess = (int) $m[1];
    $basename = (string) $m[2];
    $phase_scope_job = null;
    if ($basename === 'print_all.html') {
        // session-wide bundle
    } elseif (preg_match('#^job_(.+)\.print_all\.html$#', $basename, $jm)) {
        $phase_scope_job = rawurldecode((string) $jm[1]);
    } else {
        return $html; // per-phase bundle — already single-phase
    }

    $wb_to_phase = [];
    $phase_jobs = [];
    $store = session_waybill_store_load($sess);
    foreach (($store['groups'] ?? []) as $key => $nums) {
        [$job, $phase] = array_pad(explode('|', (string) $key, 2), 2, '');
        $phase = (int) $phase;
        if ($phase_scope_job !== null && (string) $job !== $phase_scope_job) {
            continue;
        }
        foreach ((array) $nums as $n) {
            $wb_to_phase[(string) $n] = $phase;
        }
        $phase_jobs[$phase][(string) $job] = true;
    }
    if (count($phase_jobs) < 2) {
        return $html;
    }

    ksort($phase_jobs);
    $opts = '<option value="">All phases</option>';
    foreach ($phase_jobs as $ph => $jobs) {
        $label = 'Phase ' . (int) $ph;
        if ($phase_scope_job === null) {
            $job_list = implode(', ', array_keys($jobs));
            if ($job_list !== '') {
                $label .= ' · ' . $job_list;
            }
        }
        $opts .= '<option value="' . (int) $ph . '">' . htmlspecialchars($label) . '</option>';
    }

    $phase_control = '<label for="wb-phase-select" class="text-white-50 small mb-0">Phase</label>'
        . '<select id="wb-phase-select" class="form-select form-select-sm" style="width:auto;">'
        . $opts . '</select>';

    $count = 0;
    if (strpos($html, 'wb-train-select') !== false) {
        $html = preg_replace(
            '#(<select id="wb-train-select"[^>]*>.*?</select>)#s',
            '$1' . $phase_control,
            $html,
            1,
            $count
        );
    }
    if ($count === 0) {
        $html = preg_replace(
            '#(<div class="noprint waybill-print-controls">)#',
            '<div class="noprint d-inline-flex align-items-center gap-2 me-3" style="margin-bottom:12px;">'
            . $phase_control . '</div>$1',
            $html,
            1,
            $count
        );
    }
    if ($count === 0) {
        return $html;
    }

    $map_json = json_encode($wb_to_phase, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $script = '<script>(function(){'
        . 'var sel=document.getElementById("wb-phase-select");'
        . 'if(!sel)return;'
        . 'var map=' . $map_json . ';'
        . 'var sheets=[].slice.call(document.querySelectorAll(".waybill-print .waybill-sheet"));'
        . 'var wbRaw=new URLSearchParams(location.search).get("wb");'
        . 'var wbSet=null;'
        . 'if(wbRaw){wbSet={};wbRaw.split(",").forEach(function(x){x=x.trim();if(x)wbSet[x]=1;});}'
        . 'var muted=document.querySelector("main .muted");'
        . 'var mutedDefault=muted?(muted.textContent||""):"";'
        . 'function wbNum(s){var m=(s.textContent||"").match(/WAYBILL No\\.\\s*([0-9A-Za-z\\-]+)/);return m?m[1]:"";}'
        . 'sheets.forEach(function(s){var n=wbNum(s);if(n&&map[n]!=null)s.setAttribute("data-wb-phase",String(map[n]));});'
        . 'function apply(){var v=sel.value;var vis=[];'
        . 'sheets.forEach(function(s){'
        . 'var n=wbNum(s);'
        . 'var phaseOk=(v===""||s.getAttribute("data-wb-phase")===v);'
        . 'var wbOk=!wbSet||!n||wbSet[n];'
        . 'var show=phaseOk&&wbOk;'
        . 's.style.display=show?"":"none";'
        . 'if(show)vis.push(s);});'
        . 'vis.forEach(function(s,i){var last=(i===vis.length-1);'
        . 's.style.pageBreakAfter=last?"auto":"always";s.style.breakAfter=last?"auto":"page";});'
        . 'if(muted){if(v===""){muted.textContent=mutedDefault;}'
        . 'else{muted.textContent=vis.length+" waybill"+(vis.length===1?"":"s")+" \\u00b7 each prints on its own page.";}}}'
        . 'sel.addEventListener("change",apply);'
        . '})();</script>';

    return str_replace('</body>', $script . '</body>', $html);
}

/**
 * Add a selection checkbox to each waybill on a waybill index/list page, plus a
 * "Print selected" control, so an operator can print a custom subset. Selecting
 * waybills and clicking "Print selected" opens the same scope's print-all bundle
 * with a ?wb=<comma-list> filter (handled by so_inject_waybill_selection_print()).
 *
 * Injected at serve time (like the memo/phase filters) so it works on every
 * previously generated index — session, per-phase, and per-train — without
 * regeneration. The nav bar's own <li> items are class-tagged, so the checkbox
 * transform (which matches only bare "<li><a href=...>") never touches them.
 *
 * On the session-wide list (waybills/index.html) and a per-train all-phases list
 * (waybills/job_<JOB>.index.html) a "Phase" dropdown is added too, driven by the
 * waybill store's JOB|PHASE groups. Per-phase lists already show a single phase,
 * so they get checkboxes only.
 */
function so_inject_waybill_selection_index($html, $rel)
{
    if (!preg_match('#^session_\d+/(?:phase_\d+/)?waybills/(?:[^/]*\.)?index\.html$#', $rel)) {
        return $html;
    }
    if (strpos($html, 'wb-pick') !== false) {
        return $html; // already injected
    }

    // Phase filter scope: session-wide list, or a per-train all-phases list.
    $show_phase = false;
    $sess = 0;
    $phase_scope_job = null;
    if (preg_match('#^session_(\d+)/waybills/index\.html$#', $rel, $sm)) {
        $show_phase = true;
        $sess = (int) $sm[1];
    } elseif (preg_match('#^session_(\d+)/waybills/job_(.+)\.index\.html$#', $rel, $sm)) {
        $show_phase = true;
        $sess = (int) $sm[1];
        $phase_scope_job = rawurldecode((string) $sm[2]);
    }

    // Build waybill -> phases and phase -> jobs maps from the frozen store.
    $wb_phases = [];
    $phase_jobs = [];
    if ($show_phase) {
        $store = session_waybill_store_load($sess);
        foreach (($store['groups'] ?? []) as $key => $nums) {
            [$job, $phase] = array_pad(explode('|', (string) $key, 2), 2, '');
            $phase = (int) $phase;
            if ($phase_scope_job !== null && (string) $job !== $phase_scope_job) {
                continue;
            }
            foreach ((array) $nums as $n) {
                $wb_phases[(string) $n][$phase] = true;
            }
            $phase_jobs[$phase][(string) $job] = true;
        }
        if (count($phase_jobs) < 2) {
            $show_phase = false; // nothing to filter (0 or 1 phase)
        }
    }

    $count = 0;
    $html = preg_replace_callback(
        '#<li><a href="([^"]+)">([^<]+)</a></li>#',
        static function ($mm) use ($show_phase, $wb_phases) {
            $href = $mm[1];
            $num = $mm[2];
            $data = '';
            if ($show_phase) {
                $phs = isset($wb_phases[$num]) ? array_keys($wb_phases[$num]) : [];
                sort($phs);
                $data = ' data-wb-phase="' . htmlspecialchars(implode(' ', $phs), ENT_QUOTES) . '"';
            }
            return '<li class="wb-pick-row"' . $data . '><label class="wb-pick-label">'
                . '<input type="checkbox" class="wb-pick" value="' . $num . '">'
                . '<a href="' . $href . '">' . $num . '</a></label></li>';
        },
        $html,
        -1,
        $count
    );
    if ($count === 0) {
        return $html; // no selectable waybills on this page
    }

    $phase_select = '';
    if ($show_phase) {
        ksort($phase_jobs);
        $opts = '<option value="">All phases</option>';
        foreach ($phase_jobs as $ph => $jobs) {
            $label = 'Phase ' . (int) $ph;
            if ($phase_scope_job === null) {
                $job_list = implode(', ', array_keys($jobs));
                if ($job_list !== '') {
                    $label .= ' · ' . $job_list;
                }
            }
            $opts .= '<option value="' . (int) $ph . '">' . htmlspecialchars($label) . '</option>';
        }
        $phase_select = '<label for="wb-phase-select" class="wb-phase-label">Phase</label>'
            . '<select id="wb-phase-select" class="form-select form-select-sm" style="width:auto;">'
            . $opts . '</select>';
    }

    $controls = '<div class="noprint wb-select-controls">'
        . $phase_select
        . '<button type="button" id="wb-print-selected" class="btn btn-dark btn-sm" disabled>'
        . '<i class="bi bi-printer"></i> Print selected (0)</button>'
        . '<a href="#" id="wb-select-all">Select all</a>'
        . '<a href="#" id="wb-clear">Clear</a>'
        . '</div>';
    $html = preg_replace(
        '#(<p><a href="[^"]*"><strong>Print all waybills</strong></a></p>)#',
        '$1' . $controls,
        $html,
        1
    );

    $style = '<style>'
        . '.wb-pick-label{display:inline-flex;align-items:center;gap:8px;cursor:pointer;}'
        . '.wb-pick-row{margin:3px 0;list-style:none;}'
        . '.wb-select-controls{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin:.35rem 0 .85rem;}'
        . '.wb-select-controls a{font-size:.85rem;}'
        . '.wb-phase-label{font-size:.85rem;margin-right:-8px;}'
        . '@media print{.wb-select-controls,.wb-pick{display:none!important}}'
        . '</style>';

    $script = '<script>(function(){'
        . 'var picks=[].slice.call(document.querySelectorAll(".wb-pick"));'
        . 'if(!picks.length)return;'
        . 'var printAll=null;'
        . '[].slice.call(document.querySelectorAll("a")).forEach(function(a){'
        . 'if(!printAll&&/print all waybills/i.test(a.textContent||""))printAll=a;});'
        . 'var btn=document.getElementById("wb-print-selected");'
        . 'var selAll=document.getElementById("wb-select-all");'
        . 'var clr=document.getElementById("wb-clear");'
        . 'var phaseSel=document.getElementById("wb-phase-select");'
        . 'function rowOf(c){return c.closest?c.closest("li"):c.parentNode.parentNode;}'
        . 'function visible(c){var r=rowOf(c);return !r||r.style.display!=="none";}'
        . 'function sel(){return picks.filter(function(c){return c.checked;}).map(function(c){return c.value;});}'
        . 'function upd(){var n=sel().length;if(btn){btn.disabled=n===0;'
        . 'btn.innerHTML=\'<i class="bi bi-printer"></i> Print selected (\'+n+\')\';}}'
        . 'picks.forEach(function(c){c.addEventListener("change",upd);});'
        . 'if(selAll)selAll.addEventListener("click",function(e){e.preventDefault();picks.forEach(function(c){if(visible(c))c.checked=true;});upd();});'
        . 'if(clr)clr.addEventListener("click",function(e){e.preventDefault();picks.forEach(function(c){c.checked=false;});upd();});'
        . 'if(phaseSel)phaseSel.addEventListener("change",function(){var v=phaseSel.value;'
        . 'picks.forEach(function(c){var r=rowOf(c);if(!r)return;'
        . 'var ph=(r.getAttribute("data-wb-phase")||"").split(/\\s+/);'
        . 'var show=(v===""||ph.indexOf(v)>=0);'
        . 'r.style.display=show?"":"none";if(!show&&c.checked)c.checked=false;});upd();});'
        . 'if(btn)btn.addEventListener("click",function(e){e.preventDefault();var s=sel();if(!s.length||!printAll)return;'
        . 'var base=printAll.getAttribute("href");'
        . 'var url=base+(base.indexOf("?")>=0?"&":"?")+"wb="+encodeURIComponent(s.join(","));'
        . 'window.location.href=url;});'
        . 'upd();'
        . '})();</script>';

    return str_replace('</body>', $style . $script . '</body>', $html);
}

/**
 * Companion to so_inject_waybill_selection_index(): when a waybills print-all
 * page is opened with a ?wb=<comma-list> filter, show only those waybills. Each
 * sheet is matched by its "WAYBILL No." text, so a waybill's empty-repositioning
 * sheet and its loaded sheet (which share a number) are kept together. Adds a
 * "Showing N selected · Show all" banner and relabels the print button.
 */
function so_inject_waybill_selection_print($html, $rel)
{
    if (!preg_match('#^session_\d+/(?:phase_\d+/)?waybills/[^/]*print_all\.html$#', $rel)) {
        return $html;
    }
    if (strpos($html, 'wb-selection-filter') !== false) {
        return $html;
    }

    $script = '<script id="wb-selection-filter">(function(){'
        . 'var params=new URLSearchParams(location.search);'
        . 'var raw=params.get("wb");'
        . 'if(!raw)return;'
        . 'var wrap=document.querySelector(".waybill-print");'
        . 'if(!wrap)return;'
        . 'var set={};raw.split(",").forEach(function(x){x=x.trim();if(x)set[x]=1;});'
        . 'var sheets=[].slice.call(wrap.querySelectorAll(".waybill-sheet"));'
        . 'var shown={},vis=[];'
        . 'sheets.forEach(function(s){var m=(s.textContent||"").match(/WAYBILL No\\.\\s*([0-9A-Za-z\\-]+)/);'
        . 'var n=m?m[1]:null;if(n&&set[n]){s.style.display="";shown[n]=1;vis.push(s);}else{s.style.display="none";}});'
        . 'vis.forEach(function(s,i){var last=(i===vis.length-1);'
        . 's.style.pageBreakAfter=last?"auto":"always";s.style.breakAfter=last?"auto":"page";});'
        . 'var nsel=Object.keys(shown).length;'
        . 'params.delete("wb");var showAll=location.pathname+(params.toString()?("?"+params.toString()):"");'
        . 'var banner=document.createElement("div");banner.className="noprint";'
        . 'banner.style.cssText="margin:.5rem 0;padding:.5rem .75rem;background:#fff3cd;border:1px solid #ffe69c;border-radius:6px;font:14px system-ui,-apple-system,sans-serif;";'
        . 'banner.innerHTML="Showing "+nsel+" selected waybill"+(nsel===1?"":"s")+" &middot; <a href=\\""+showAll+"\\">Show all</a>";'
        . 'wrap.parentNode.insertBefore(banner,wrap);'
        . 'var pb=document.querySelector(".waybill-print-controls button");'
        . 'if(pb)pb.innerHTML=\'<i class="bi bi-printer"></i> Print selected\';'
        . 'var cnt=document.querySelector("main .muted");'
        . 'if(cnt)cnt.textContent=nsel+" selected waybill"+(nsel===1?"":"s")+" \\u00b7 each prints on its own page.";'
        . '})();</script>';

    return str_replace('</body>', $script . '</body>', $html);
}

/**
 * Add a "Phase" filter dropdown next to the existing Train/Style dropdowns on the
 * combined and per-train print-all switch-list pages (print_all[_style].html and
 * train_<job>.print_all[_style].html). Selecting a phase shows only that switch
 * list; "All phases" (default) shows everything.
 *
 * Injected at serve time — like the waybill memo filter — so it works on every
 * previously generated page without regeneration. The options are built client
 * side from each rendered `.print-all-phase` section's heading, so the filter
 * needs no server-side phase data and stays correct as pages are regenerated.
 * The control hides itself when a page has fewer than two phases.
 */
function so_inject_switchlist_phase_filter($html, $rel)
{
    if (!preg_match('#^session_\d+/(?:print_all(?:_[a-z0-9_-]+)?|train_.+\.print_all(?:_[a-z0-9_-]+)?)\.html$#', $rel)) {
        return $html;
    }
    if (strpos($html, 'sw-phase-select') !== false) {
        return $html; // already injected
    }
    // Only inject when the Train/Style controls cluster is present (skip embed
    // and any non-standard page that lacks it).
    if (strpos($html, 'sw-style-status') === false) {
        return $html;
    }

    $control = '<label for="sw-phase-select" class="text-white-50 small mb-0">Phase</label>'
        . '<select id="sw-phase-select" class="form-select form-select-sm" style="width:auto;">'
        . '<option value="">All phases</option></select>';
    $count = 0;
    $html = preg_replace(
        '#(<span id="sw-style-status"[^>]*></span>)#',
        $control . '$1',
        $html,
        1,
        $count
    );
    if ($count === 0) {
        return $html;
    }

    $script = '<script>(function(){'
        . 'var sel=document.getElementById("sw-phase-select");'
        . 'var lab=document.querySelector("label[for=\\"sw-phase-select\\"]");'
        . 'if(!sel)return;'
        . 'var secs=Array.prototype.slice.call(document.querySelectorAll(".page .print-all-phase"));'
        . 'if(secs.length<2){sel.style.display="none";if(lab)lab.style.display="none";return;}'
        . 'secs.forEach(function(s,i){'
        . 'var h=s.querySelector("h2");'
        . 'var t=h?(h.textContent||"").trim():("Phase "+(i+1));'
        . 's.setAttribute("data-sw-phase",String(i));'
        . 'var o=document.createElement("option");o.value=String(i);o.textContent=t;sel.appendChild(o);});'
        . 'function apply(){var v=sel.value;secs.forEach(function(s){'
        . 's.style.display=(v===""||s.getAttribute("data-sw-phase")===v)?"":"none";});}'
        . 'sel.addEventListener("change",apply);apply();'
        . '})();</script>';

    return str_replace('</body>', $script . '</body>', $html);
}

/**
 * Rebuild the prev/next session nav on a switch-list print-all page from the
 * current set of sessions (combined or per-style). Replaces an existing row if
 * present, or inserts one right after the top nav when missing.
 */
function so_refresh_switchlist_print_all_session_nav($html, $rel)
{
    if (!preg_match('#^session_(\d+)/print_all(?:_([a-z0-9_-]+))?\.html$#', $rel, $m)) {
        return $html;
    }
    $session_nbr = (int) $m[1];
    $style = isset($m[2]) && $m[2] !== '' ? (string) $m[2] : '';
    $nav = session_switchlist_print_all_session_nav_html($session_nbr, $style);
    if ($nav === '') {
        return $html;
    }
    if (strpos($html, 'switchlist-print-all-session-nav') !== false) {
        return preg_replace_callback(
            '#<div class="session-nav-row switchlist-print-all-session-nav[^"]*">.*?</div>#s',
            static function () use ($nav) {
                return $nav;
            },
            $html,
            1
        );
    }

    return preg_replace(
        '#</nav>#',
        '</nav>' . $nav,
        $html,
        1
    );
}

/**
 * Rebuild prev/next session nav on a per-job switch-list print-all page
 * (session_N/phase_XX/JOB/print_all.html).
 */
function so_refresh_switchlist_job_print_all_session_nav($html, $rel)
{
    if (!preg_match('#^session_(\d+)/phase_(\d+)/([^/]+)/print_all\.html$#', $rel, $m)) {
        return $html;
    }
    $session_nbr = (int) $m[1];
    $phase_num = (int) $m[2];
    $job = rawurldecode((string) $m[3]);
    $nav = session_switchlist_job_print_all_session_nav_html($session_nbr, $phase_num, $job);
    if ($nav === '') {
        return $html;
    }
    if (strpos($html, 'switchlist-job-print-all-session-nav') !== false) {
        return preg_replace_callback(
            '#<div class="session-nav-row switchlist-job-print-all-session-nav[^"]*">.*?</div>#s',
            static function () use ($nav) {
                return $nav;
            },
            $html,
            1
        );
    }

    return preg_replace(
        '#</nav>#',
        '</nav>' . $nav,
        $html,
        1
    );
}

/**
 * Rebuild prev/next session nav on a per-train switch-list print-all page
 * (session_N/train_<job>.print_all.html).
 */
function so_refresh_switchlist_train_print_all_session_nav($html, $rel)
{
    if (!preg_match('#^session_(\d+)/train_(.+)\.print_all(?:_[a-z0-9_-]+)?\.html$#', $rel, $m)) {
        return $html;
    }
    $session_nbr = (int) $m[1];
    $job = rawurldecode((string) $m[2]);
    $nav = session_switchlist_train_print_all_session_nav_html($session_nbr, $job);
    if ($nav === '') {
        return $html;
    }
    if (strpos($html, 'switchlist-train-print-all-session-nav') !== false) {
        return preg_replace_callback(
            '#<div class="session-nav-row switchlist-train-print-all-session-nav[^"]*">.*?</div>#s',
            static function () use ($nav) {
                return $nav;
            },
            $html,
            1
        );
    }

    return preg_replace(
        '#</nav>#',
        '</nav>' . $nav,
        $html,
        1
    );
}

/**
 * Rebuild the prev/next session nav on a station report page from the current
 * set of sessions, so a report cached before later sessions existed still gets a
 * working "next" link (the report's own session manifest doesn't change when a
 * later session is added, so the cached file isn't otherwise rebuilt).
 */
function so_refresh_station_report_session_nav($html, $rel)
{
    if (!preg_match('#^session_(\d+)/station_report\.html$#', $rel, $m)) {
        return $html;
    }
    if (strpos($html, 'station-report-session-nav') === false) {
        return $html;
    }
    $nav = session_station_report_session_nav_html((int) $m[1]);
    if ($nav === '') {
        return $html;
    }

    return preg_replace(
        '#<div class="session-nav-row station-report-session-nav[^"]*">.*?</div>#s',
        $nav,
        $html,
        1
    );
}

/** Same idea as so_refresh_station_report_session_nav(), for wheel reports. */
function so_refresh_wheel_report_session_nav($html, $rel)
{
    if (!preg_match('#^session_(\d+)/wheel_report\.html$#', $rel, $m)) {
        return $html;
    }
    if (strpos($html, 'wheel-report-session-nav') === false) {
        return $html;
    }
    $nav = session_wheel_report_session_nav_html((int) $m[1]);
    if ($nav === '') {
        return $html;
    }

    return preg_replace(
        '#<div class="session-nav-row wheel-report-session-nav[^"]*">.*?</div>#s',
        $nav,
        $html,
        1
    );
}

function so_normalize_path($path)
{
    $out = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($out);
            continue;
        }
        $out[] = $seg;
    }

    return implode('/', $out);
}

function so_public_to_href($resolved, $query, $hash)
{
    if (preg_match('#^session_(\d+)/index\.(php|html)$#', $resolved, $mm)) {
        $href = 'session_overview.php?session=' . (int) $mm[1];
        if ($query !== '') {
            $href .= '&' . ltrim($query, '?');
        }
        return $href . $hash;
    }
    if (preg_match('#^session_\d+/#', $resolved)) {
        $href = 'so.php?f=' . $resolved;
        if ($query !== '') {
            $href .= '&' . ltrim($query, '?');
        }
        return $href . $hash;
    }

    // App-level file (session.php, index.html, session-nav.css, editor.html, ...).
    return '/sts/' . $resolved . $query . $hash;
}

function so_rewrite_html($html, $dir)
{
    return preg_replace_callback(
        '/\b(href|src)\s*=\s*"([^"]*)"/i',
        static function ($mm) use ($dir) {
            $attr = $mm[1];
            $url = $mm[2];
            if ($url === '' || preg_match('#^(https?:|//|/|\#|mailto:|tel:|data:|javascript:)#i', $url)) {
                return $mm[0];
            }
            $path = $url;
            $hash = '';
            $query = '';
            if (($hp = strpos($path, '#')) !== false) {
                $hash = substr($path, $hp);
                $path = substr($path, 0, $hp);
            }
            if (($qp = strpos($path, '?')) !== false) {
                $query = substr($path, $qp);
                $path = substr($path, 0, $qp);
            }
            if ($path === '') {
                return $mm[0];
            }
            $resolved = so_normalize_path(($dir !== '' ? $dir . '/' : '') . $path);
            if ($resolved === '') {
                return $mm[0];
            }
            $href = so_public_to_href($resolved, $query, $hash);

            return $attr . '="' . htmlspecialchars($href, ENT_QUOTES) . '"';
        },
        $html
    );
}
