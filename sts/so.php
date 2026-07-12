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
// output that no longer represents live state. Bounce to the latest overview.
if (preg_match('#^session_(\d+)/#', $rel, $sm)) {
    session_redirect_if_beyond_current((int) $sm[1]);
}

$fs = session_output_fs_path($rel);
// Print-all bundles are built on demand (they aren't pre-generated for every
// train/session). If a requested bundle is missing — e.g. following a prev/next
// session link to a session whose overview was never opened — build it now so
// navigation never dead-ends on "Not found". Also rebuild when the session's
// manifest is newer than the cached bundle, so a bundle always reflects the
// current "latest generation token" even while a session is being (re)generated.
if (!is_file($fs) || so_print_all_bundle_stale($rel, $fs)) {
    $built = so_build_print_all_on_demand($rel);
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
    $html = so_refresh_switchlist_print_all_session_nav($html, $rel);
    $html = so_refresh_switchlist_job_print_all_session_nav($html, $rel);
    $html = so_refresh_switchlist_train_print_all_session_nav($html, $rel);
    // Embedded mode (used by job.php's inline switch-list viewer): drop the
    // top navigation bar since the surrounding page provides navigation and the
    // style selector.
    if (!empty($_GET['embed'])) {
        $html = preg_replace('#<nav\b[^>]*>.*?</nav>#is', '', $html, 1);
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
    if (!preg_match('#^session_(\d+)/waybills/(.+\.print_all\.html)$#', $rel, $m)) {
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
