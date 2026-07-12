<?php
/**
 * Session Output server.
 *
 * Generated operating-session files (switch lists, waybills, print bundles) are
 * written to sts/temp (owned by www-data so the web server can create them).
 * This script streams those files to the browser through a clean URL so the
 * temp/ location is never exposed:
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

$fs = session_output_fs_path($rel);
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
    $html = so_refresh_switchlist_print_all_session_nav($html, $rel);
    $html = so_refresh_switchlist_job_print_all_session_nav($html, $rel);
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
