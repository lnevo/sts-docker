<?php
/**
 * Session output (/sts/), phase manifest, waybills, recipe control flow.
 */

require_once __DIR__ . '/session_runtime.php';
session_runtime_bootstrap();

function session_merge_runtime_config(array $config = [])
{
    if (function_exists('warm_start_merge_config')) {
        return warm_start_merge_config($config);
    }
    return $config;
}

function session_job_id($dbc, $job_name)
{
    if (function_exists('warm_start_job_id')) {
        return (int) warm_start_job_id($dbc, $job_name);
    }
    $job_name = trim((string) $job_name);
    if ($job_name === '') {
        return 0;
    }
    $rs = mysqli_query(
        $dbc,
        'SELECT id FROM jobs WHERE name = "' . mysqli_real_escape_string($dbc, $job_name) . '" LIMIT 1'
    );
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return 0;
    }
    return (int) mysqli_fetch_array($rs)['id'];
}

function session_location_id_by_code($dbc, $code)
{
    if (function_exists('operational_steps_location_id_by_code')) {
        return operational_steps_location_id_by_code($dbc, $code);
    }
    if (function_exists('warm_start_location_id_by_code')) {
        return (int) warm_start_location_id_by_code($dbc, $code);
    }
    $code = strtoupper(trim((string) $code));
    if ($code === '') {
        return 0;
    }
    $rs = mysqli_query(
        $dbc,
        'SELECT id FROM locations WHERE code = "' . mysqli_real_escape_string($dbc, $code) . '" LIMIT 1'
    );
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return 0;
    }
    return (int) mysqli_fetch_array($rs)['id'];
}

function session_staging_job_names($dbc, array $config = [])
{
    if (function_exists('operational_steps_staging_job_names')) {
        return operational_steps_staging_job_names($dbc, $config);
    }
    if (function_exists('warm_start_staging_job_names')) {
        return warm_start_staging_job_names($dbc, $config);
    }
    $jobs = [];
    $rs = mysqli_query($dbc, 'SELECT name FROM jobs WHERE name LIKE "STG-%" ORDER BY name');
    while ($rs && ($row = mysqli_fetch_array($rs))) {
        $name = (string) ($row['name'] ?? '');
        if ($name !== '') {
            $jobs[] = $name;
        }
    }
    return $jobs;
}

/** STS PHP application root (read-only code; not session output). */
function session_app_root()
{
    return __DIR__;
}

/**
 * Writable filesystem root for generated session output (switch lists, waybills,
 * manifests). Uses sts/backups/session_state/sessions: sts/backups is the
 * host-mounted sts-backups volume (~/sts/sts-backups), so session state persists
 * across container recreation without adding another volume. www-data owns the
 * mount inside the container, so this subdirectory is created on demand and stays
 * writable. Per-session output lives at sts/backups/session_state/sessions/
 * session_N/... and phase output at .../session_N/phase_PP/... .
 */
function session_web_root()
{
    return session_app_root() . '/backups/session_state/sessions';
}

/** www-data uid/gid for session output dirs (Apache user in the Docker image). */
function session_output_owner_ids()
{
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $ids = ['uid' => null, 'gid' => null];
    if (function_exists('posix_getpwnam')) {
        $pw = posix_getpwnam('www-data');
        if (is_array($pw)) {
            $ids['uid'] = (int) $pw['uid'];
            $ids['gid'] = (int) $pw['gid'];
        }
    }

    return $ids;
}

/**
 * Create a session output directory and ensure it is writable by www-data.
 * When PHP runs as root (docker exec without -u www-data), newly created dirs
 * are chowned to www-data so the web UI can write switch lists afterward.
 */
function session_ensure_writable_dir($path)
{
    if ($path === '' || $path === '.' || $path === '/') {
        throw new InvalidArgumentException('Invalid session output directory: ' . $path);
    }
    if (!is_dir($path)) {
        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to create directory: ' . $path);
        }
    }
    $owner = session_output_owner_ids();
    if (function_exists('posix_geteuid') && posix_geteuid() === 0 && $owner['uid'] !== null) {
        @chown($path, $owner['uid']);
        @chgrp($path, $owner['gid']);
        @chmod($path, 0775);
    }
    if (!is_writable($path)) {
        $run_as = 'php';
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            if (is_array($pw) && !empty($pw['name'])) {
                $run_as = $pw['name'];
            }
        }
        $dir_owner = fileowner($path);
        $dir_owner_name = (string) $dir_owner;
        if (function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid($dir_owner);
            if (is_array($pw) && !empty($pw['name'])) {
                $dir_owner_name = $pw['name'];
            }
        }
        throw new RuntimeException(
            'Session output directory is not writable: ' . $path
            . ' (running as ' . $run_as . ', directory owned by ' . $dir_owner_name . ').'
            . ' Fix: docker exec -u root sts-docker-web-1'
            . ' chown -R www-data:www-data /var/www/html/sts/backups/session_state'
        );
    }

    return $path;
}

/** Ensure the writable output root exists. */
function session_ensure_output_root($root = null)
{
    $root = $root ?? session_web_root();
    $parent = dirname($root);
    if ($parent !== '' && $parent !== '.' && $parent !== '/' && !is_dir($parent)) {
        session_ensure_writable_dir($parent);
    }

    return session_ensure_writable_dir($root);
}

/**
 * Public browser URL for a generated session output file. Files physically live
 * under sts/backups/session_state/sessions (see session_web_root(); writable by
 * www-data), but are served to the browser through so.php, so the storage path
 * never appears in a URL. Example:
 *   session_output_url('session_3/phase_01/CK1/phase_01_mobile.html')
 *   => 'so.php?f=session_3/phase_01/CK1/phase_01_mobile.html'
 */
function session_output_url($relative_path = '')
{
    return 'so.php?f=' . ltrim(str_replace('\\', '/', (string) $relative_path), '/');
}

/** Public URL for a per-session overview page (a real PHP page, not static output). */
function session_session_index_href($session_nbr)
{
    return 'session_overview.php?session=' . (int) $session_nbr;
}

/**
 * Guard against viewing a session that no longer exists. Simulations rewind the
 * live session counter, so links/URLs captured earlier can point past the current
 * session. When the requested session exceeds the current DB session, redirect to
 * the latest live session — preferring the same so.php relative path (switch list,
 * train bundle, waybill page, etc.) so the browser stays in that view. Falls back
 * to the session overview when no path is known. Returns the current DB session
 * number for callers that want it (when no redirect was needed).
 *
 * @param int          $session_nbr Requested session.
 * @param mysqli|null  $dbc         Optional open handle (a fresh one is used if null).
 * @param bool         $exit        When true (default) send the redirect and exit.
 * @param string|null  $rel_path    Optional so.php relative path (session_N/…).
 */
function session_redirect_if_beyond_current($session_nbr, $dbc = null, $exit = true, $rel_path = null)
{
    $session_nbr = (int) $session_nbr;
    if ($dbc === null) {
        require_once __DIR__ . '/open_db.php';
        $dbc = open_db();
    }
    $current = (int) session_get_db_session($dbc);
    if ($current >= 1 && $session_nbr > $current) {
        if (session_browse_archived_enabled()) {
            session_ensure_archived_session_extracted($session_nbr);
            if (is_dir(session_dir_for($session_nbr))) {
                return $current;
            }
        }
        if ($exit) {
            $rel_path = ltrim(str_replace('\\', '/', (string) $rel_path), '/');
            if ($rel_path !== '' && preg_match('#^session_\d+/(.*)$#', $rel_path, $m) && $m[1] !== '') {
                header('Location: /sts/so.php?f=session_' . $current . '/' . $m[1]);
            } else {
                header('Location: /sts/session_overview.php?session=' . $current);
            }
            exit;
        }
        return $current;
    }

    return $current;
}

/**
 * Master switch for the rewind-archive browse/extract UI.
 * Leave supporting helpers in place; flip to true to re-enable.
 */
function session_rewind_archive_feature_enabled()
{
    return false;
}

/** True when the operator has opted in to browsing archived session output. */
function session_browse_archived_enabled()
{
    if (!session_rewind_archive_feature_enabled()) {
        return false;
    }
    if (isset($_GET['archive'])) {
        return (string) $_GET['archive'] !== '' && (string) $_GET['archive'] !== '0';
    }

    return !empty($_COOKIE['sts_browse_archived']) && (string) $_COOKIE['sts_browse_archived'] !== '0';
}

/**
 * Directory where rewind_session.sh archives removed session output trees.
 * Sits alongside session_state under sts/backups/.
 */
function session_rewind_archive_dir($root = null)
{
    $root = $root ?? session_web_root();

    return dirname(dirname($root)) . '/rewind_archive';
}

/**
 * Session numbers whose output was archived to rewind_archive/session_N_*.tar.gz
 * (present on disk even when the live session_state/session_N tree was removed).
 *
 * @return list<int>
 */
function session_archived_output_numbers($root = null)
{
    if (!session_rewind_archive_feature_enabled()) {
        return [];
    }
    $dir = session_rewind_archive_dir($root);
    if (!is_dir($dir)) {
        return [];
    }
    $nums = [];
    foreach (glob($dir . '/session_*.tar.gz') ?: [] as $f) {
        if (preg_match('#/session_(\d+)_#', $f, $m)) {
            $nums[] = (int) $m[1];
        }
    }
    sort($nums, SORT_NUMERIC);

    return array_values(array_unique($nums));
}

/**
 * Extract a rewind-archived session output tree back into session_state/sessions
 * so so.php can serve it. Idempotent when the folder already exists. Returns
 * false when no matching tarball is found.
 */
function session_ensure_archived_session_extracted($session_nbr, $root = null)
{
    if (!session_rewind_archive_feature_enabled()) {
        return false;
    }
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    if ($session_nbr < 1) {
        return false;
    }
    if (is_dir(session_dir_for($session_nbr, $root))) {
        return true;
    }
    $matches = glob(session_rewind_archive_dir($root) . '/session_' . $session_nbr . '_*.tar.gz') ?: [];
    if (!$matches) {
        return false;
    }
    usort($matches, static function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });
    $tarball = $matches[0];
    $dest = session_ensure_output_root($root);
    try {
        $phar = new PharData($tarball);
        $phar->extractTo($dest, null, true);
    } catch (Throwable $e) {
        return false;
    }

    return is_dir(session_dir_for($session_nbr, $root));
}

/**
 * True when a session's generated output is being viewed from archives while the
 * live DB is still at an earlier session (read-only paperwork browse).
 */
function session_is_archived_output_only($session_nbr, $dbc = null)
{
    $session_nbr = (int) $session_nbr;
    if ($session_nbr < 1 || !session_browse_archived_enabled()) {
        return false;
    }
    if ($dbc === null) {
        require_once __DIR__ . '/open_db.php';
        $dbc = open_db();
    }
    $current = (int) session_get_db_session($dbc);

    return $session_nbr > $current;
}

/** Warning banner for pages showing archived session output (DB is behind). */
function session_archived_view_banner_html($session_nbr, $current_session)
{
    if (!session_rewind_archive_feature_enabled()) {
        return '';
    }
    $session_nbr = (int) $session_nbr;
    $current_session = (int) $current_session;
    if ($session_nbr <= $current_session) {
        return '';
    }

    return '<div class="session-archived-banner noprint" role="status">'
        . 'Viewing <strong>archived</strong> session ' . $session_nbr . ' output. '
        . 'The live database is at session ' . $current_session . ' — switch lists and waybills here are historical paperwork only.'
        . '</div>';
}

/**
 * Checkbox + script to toggle archived-session browsing (cookie-backed).
 * Shown on session overview / totals pickers after a DB rewind.
 */
function session_browse_archived_controls_html()
{
    if (!session_rewind_archive_feature_enabled()) {
        return '';
    }
    $checked = session_browse_archived_enabled();
    $archived = session_archived_output_numbers();
    if (!$archived) {
        return '';
    }
    $list = implode(', ', array_map(static function ($n) {
        return (string) (int) $n;
    }, $archived));

    return '<div class="session-browse-archived-controls noprint">'
        . '<label class="session-browse-archived-label">'
        . '<input type="checkbox" id="sts-browse-archived"' . ($checked ? ' checked' : '') . '> '
        . 'Show archived sessions</label>'
        . '<span class="text-muted small"> (rewind archive: ' . htmlspecialchars($list) . ')</span>'
        . '</div>'
        . '<script>(function(){'
        . 'var cb=document.getElementById("sts-browse-archived");'
        . 'if(!cb)return;'
        . 'cb.addEventListener("change",function(){'
        . 'if(cb.checked){document.cookie="sts_browse_archived=1;path=/sts;max-age=31536000";}'
        . 'else{document.cookie="sts_browse_archived=0;path=/sts;max-age=0";}'
        . 'location.reload();});})();</script>';
}

/**
 * Normalize an output-relative segment. Also strips a leading legacy `temp/`
 * prefix so any old baked link (from before session output moved to
 * backups/session_state/sessions) still resolves. Current output never carries
 * a temp/ prefix.
 */
function session_output_rel_strip($href)
{
    return ltrim(preg_replace('#^temp/#', '', (string) $href), '/');
}

/** Absolute filesystem path for session output (output-relative segment). */
function session_output_fs_path($relative_path, $root = null)
{
    $root = $root ?? session_web_root();

    return rtrim($root, '/') . '/' . session_output_rel_strip($relative_path);
}

/**
 * Relative ../ chain from a generated file's directory up to /sts/, computed from
 * the file's PUBLIC path (session_N/...), not its physical storage path, so nav
 * links in static output resolve correctly under the rewritten URLs.
 */
function session_relative_prefix_from_app($dir)
{
    $out = rtrim(str_replace('\\', '/', session_web_root()), '/');
    $app = rtrim(str_replace('\\', '/', session_app_root()), '/');
    $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');

    if ($dir === $out || $dir === $app) {
        return '';
    }
    if (strpos($dir, $out . '/') === 0) {
        $public_rel = substr($dir, strlen($out) + 1);
    } elseif (strpos($dir, $app . '/') === 0) {
        $public_rel = substr($dir, strlen($app) + 1);
    } else {
        return '../';
    }

    $depth = $public_rel === '' ? 0 : count(explode('/', $public_rel));

    return str_repeat('../', $depth);
}

function session_repo_root($helpers_root = null)
{
    if ($helpers_root === null) {
        $helpers_root = __DIR__;
    }
    return rtrim($helpers_root, '/');
}

function session_dir_for($session_nbr, $root = null)
{
    $root = session_ensure_output_root($root ?? session_web_root());

    return rtrim($root, '/') . '/session_' . (int) $session_nbr;
}

function session_manifest_path($session_nbr, $root = null)
{
    return session_dir_for($session_nbr, $root) . '/manifest.json';
}

function session_load_manifest($session_nbr, $root = null)
{
    $path = session_manifest_path($session_nbr, $root);
    if (!is_readable($path)) {
        return ['session' => (string) $session_nbr, 'phases' => [], 'jobs' => []];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : ['session' => (string) $session_nbr, 'phases' => [], 'jobs' => []];
}

function session_save_manifest($session_nbr, array $manifest, $root = null)
{
    $dir = session_dir_for($session_nbr, $root);
    session_ensure_writable_dir($dir);
    $manifest['session'] = (string) $session_nbr;
    $manifest['updated'] = date('c');
    file_put_contents(session_manifest_path($session_nbr, $root), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    session_write_session_index($session_nbr, $root);
}

function session_run_stats_has_data(array $run_stats)
{
    $operations = $run_stats['operations'] ?? [];
    if (is_array($operations)) {
        foreach ($operations as $count) {
            if ((int) $count > 0) {
                return true;
            }
        }
    }
    $move = $run_stats['move_summary'] ?? [];
    foreach (['picked_up', 'set_out'] as $kind) {
        if (!empty($move[$kind]) && is_array($move[$kind])) {
            foreach ($move[$kind] as $count) {
                if ((int) $count > 0) {
                    return true;
                }
            }
        }
    }
    if (!empty($run_stats['station_counts']) && is_array($run_stats['station_counts'])) {
        return true;
    }
    if ((int) ($run_stats['on_train_count'] ?? 0) > 0) {
        return true;
    }
    $generated = $run_stats['generated'] ?? [];
    if (is_array($generated)) {
        foreach (['waybills', 'switchlists', 'phases', 'trains'] as $key) {
            if ((int) ($generated[$key] ?? 0) > 0) {
                return true;
            }
        }
    }
    $dashboard = $run_stats['dashboard'] ?? [];
    if (is_array($dashboard)) {
        foreach ($dashboard as $count) {
            if ((int) $count > 0) {
                return true;
            }
        }
    }

    return false;
}

function session_manifest_merge_run_stats(array $manifest, array $run_stats, array $meta = [])
{
    return session_manifest_record_run_stats($manifest, $run_stats, $meta);
}

/**
 * Tally the output generated for a session from its manifest: waybills, switch
 * lists (one per train per phase), phases, and trains.
 *
 * @return array{waybills:int, switchlists:int, phases:int, trains:int}
 */
function session_count_generated_output(array $manifest, $root = null)
{
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $root = $root ?? session_web_root();
    $session_nbr = (int) ($manifest['session'] ?? 0);
    // Count only the latest generation token's phases so repeated recipe runs
    // that appended to the same session (e.g. a simulator replaying seeds) don't
    // inflate the "switch lists"/"phases" tallies with stale historical copies.
    $phases = session_latest_token_phases($manifest);
    $jobs_meta = is_array($manifest['jobs'] ?? null) ? $manifest['jobs'] : [];

    // "Switch lists" are counted as work legs (switch-list sections) per train per
    // phase, matching the per-train totals shown in the overview's train tiles
    // (session_train_output_counts -> session_train_switchlist_legs). A single
    // phase can yield several legs for a train, so counting (phase,job) pairs
    // would disagree with the tiles; counting sections keeps the dashboard total
    // equal to the sum of the tile counts.
    $switchlists = 0;
    foreach ($phases as $phase) {
        if (!is_array($phase)) {
            continue;
        }
        $pnum = (int) ($phase['phase'] ?? 0);
        if ($pnum < 1) {
            continue;
        }
        $phase_dir = session_phase_output_dir($session_nbr, $pnum, $root);
        foreach ((array) ($phase['jobs'] ?? []) as $job) {
            $job = trim((string) $job);
            if ($job === '') {
                continue;
            }
            $sections = master_sw_load_sections_cache($phase_dir, $job, $session_nbr);
            $switchlists += is_array($sections) ? count($sections) : 0;
        }
    }

    $waybills = 0;
    if (isset($manifest['waybills']['count'])) {
        $waybills = (int) $manifest['waybills']['count'];
    } else {
        foreach ($phases as $phase) {
            if (is_array($phase) && isset($phase['waybills']['count'])) {
                $waybills += (int) $phase['waybills']['count'];
            }
        }
    }

    return [
        'waybills' => $waybills,
        'switchlists' => $switchlists,
        'phases' => count($phases),
        'trains' => count($jobs_meta),
    ];
}

/**
 * Roll up run statistics across sessions 1..$through into a single stats array:
 * operations, cars moved by train, cars by station, on-train count, and
 * generated-output counts are all summed. The operations dashboard is NOT
 * aggregated here — callers keep the selected session's snapshot so it reflects
 * the state at that point in time.
 *
 * @return array run_stats-shaped array (no 'dashboard' key)
 */
function session_aggregate_run_stats_through($through, $root = null)
{
    $root = $root ?? session_web_root();
    $through = (int) $through;

    $operations = ['generated' => 0, 'filled' => 0, 'repositioned' => 0, 'load_unload' => 0];
    $generated = ['waybills' => 0, 'switchlists' => 0, 'phases' => 0, 'trains' => 0];
    $picked_up = [];
    $set_out = [];
    $station_map = [];
    $on_train_total = 0;
    $latest_updated = null;
    $runs = 0;

    for ($s = 1; $s <= $through; $s++) {
        $manifest = session_load_manifest($s, $root);
        $gen = session_count_generated_output($manifest);
        // Waybills are counted from the store scoped to the latest token, matching
        // the per-session overview and excluding accumulated re-run copies.
        $gen['waybills'] = session_latest_token_waybill_count($s, $manifest, $root);
        foreach ($generated as $k => $v) {
            $generated[$k] += (int) ($gen[$k] ?? 0);
        }

        $rs = $manifest['run_stats'] ?? [];
        if (!session_run_stats_has_data($rs)) {
            continue;
        }
        $runs++;
        foreach ($operations as $k => $v) {
            $operations[$k] += (int) ($rs['operations'][$k] ?? 0);
        }
        foreach (['picked_up', 'set_out'] as $kind) {
            foreach ((array) ($rs['move_summary'][$kind] ?? []) as $job => $cnt) {
                $job = trim((string) $job);
                if ($job === '') {
                    continue;
                }
                if ($kind === 'picked_up') {
                    $picked_up[$job] = ($picked_up[$job] ?? 0) + (int) $cnt;
                } else {
                    $set_out[$job] = ($set_out[$job] ?? 0) + (int) $cnt;
                }
            }
        }
        foreach ((array) ($rs['station_counts'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['station_id'] ?? 0);
            $name = trim((string) ($row['station_name'] ?? '')) ?: 'Unknown station';
            $key = $id > 0 ? 'id:' . $id : 'name:' . $name;
            if (!isset($station_map[$key])) {
                $station_map[$key] = [
                    'station_id' => $id,
                    'station_name' => $name,
                    'sort_seq' => $row['sort_seq'] ?? null,
                    'car_count' => 0,
                ];
            }
            $station_map[$key]['car_count'] += (int) ($row['car_count'] ?? 0);
        }
        $on_train_total += (int) ($rs['on_train_count'] ?? 0);
        if (!empty($rs['updated'])) {
            $latest_updated = $rs['updated'];
        }
    }

    ksort($picked_up, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($set_out, SORT_NATURAL | SORT_FLAG_CASE);
    usort($station_map, static function ($a, $b) {
        $sa = $a['sort_seq'];
        $sb = $b['sort_seq'];
        if ($sa !== null && $sb !== null && (int) $sa !== (int) $sb) {
            return (int) $sa <=> (int) $sb;
        }
        return strnatcasecmp((string) $a['station_name'], (string) $b['station_name']);
    });

    return [
        'operations' => $operations,
        'generated' => $generated,
        'move_summary' => ['picked_up' => $picked_up, 'set_out' => $set_out],
        'station_counts' => array_values($station_map),
        'on_train_count' => $on_train_total,
        'updated' => $latest_updated,
        'aggregated_through' => $through,
        'aggregated_runs' => $runs,
    ];
}

function session_manifest_record_run_stats(array $manifest, array $run_stats, array $meta = [])
{
    $snapshot = array_merge([
        'at' => date('c'),
        'operations' => $run_stats['operations'] ?? [],
        'move_summary' => $run_stats['move_summary'] ?? ['picked_up' => [], 'set_out' => []],
        'station_counts' => $run_stats['station_counts'] ?? [],
        'on_train_count' => (int) ($run_stats['on_train_count'] ?? 0),
        'dashboard' => is_array($run_stats['dashboard'] ?? null) ? $run_stats['dashboard'] : [],
    ], $meta);

    foreach (['picked_up', 'set_out'] as $kind) {
        if (!isset($snapshot['move_summary'][$kind]) || !is_array($snapshot['move_summary'][$kind])) {
            $snapshot['move_summary'][$kind] = [];
        }
        ksort($snapshot['move_summary'][$kind], SORT_NATURAL | SORT_FLAG_CASE);
    }

    $history = $manifest['run_stats']['history'] ?? [];
    if (session_run_stats_has_data($run_stats)) {
        $history[] = $snapshot;
    }

    $manifest['run_stats'] = [
        'operations' => $snapshot['operations'],
        'move_summary' => $snapshot['move_summary'],
        'station_counts' => $snapshot['station_counts'] ?? [],
        'on_train_count' => (int) ($snapshot['on_train_count'] ?? 0),
        'generated' => is_array($run_stats['generated'] ?? null) ? $run_stats['generated'] : [],
        'dashboard' => $snapshot['dashboard'] ?? [],
        'updated' => date('c'),
        'last_run' => $snapshot,
        'history' => $history,
    ];

    return $manifest;
}

function session_aggregate_run_stats_by_session(array $log, $start_session)
{
    $start_session = (int) $start_session;
    $buckets = [];
    $current = $start_session > 0 ? $start_session : 0;

    $ensure_bucket = static function ($session_nbr) use (&$buckets) {
        $session_nbr = (int) $session_nbr;
        if ($session_nbr < 1) {
            return;
        }
        if (!isset($buckets[$session_nbr])) {
            $buckets[$session_nbr] = ['log' => []];
        }
    };

    if ($current > 0) {
        $ensure_bucket($current);
    }

    foreach ($log as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (!empty($entry['session'])) {
            $next_session = (int) $entry['session'];
            if ($next_session > 0) {
                $current = $next_session;
                $ensure_bucket($current);
            }
        }
        if ($current < 1) {
            continue;
        }
        $ensure_bucket($current);
        $buckets[$current]['log'][] = $entry;
    }

    $result = [];
    foreach ($buckets as $session_nbr => $bucket) {
        $stats = session_simulator_aggregate_run_stats([['log' => $bucket['log']]]);
        if (session_run_stats_has_data($stats)) {
            $result[(int) $session_nbr] = $stats;
        }
    }

    ksort($result);

    return $result;
}

function session_persist_recipe_run_stats($dbc, array $log, array $meta, $start_session, $root = null)
{
    $root = $root ?? session_web_root();
    require_once __DIR__ . '/operations_stats.php';
    $dashboard_snapshot = operations_get_stats($dbc);
    $stats_by_session = session_aggregate_run_stats_by_session($log, $start_session);
    if ($stats_by_session === []) {
        $final_session = function_exists('warm_start_get_session')
            ? (int) warm_start_get_session($dbc)
            : (int) session_get_db_session($dbc);
        if ($final_session > 0) {
            $stats = session_simulator_aggregate_run_stats([['log' => $log]]);
            if (session_run_stats_has_data($stats)) {
                $stats['station_counts'] = session_station_car_counts($dbc);
                $stats['on_train_count'] = session_on_train_car_count($dbc);
                $stats_by_session[$final_session] = $stats;
            }
        }
    }

    foreach ($stats_by_session as $session_nbr => $stats) {
        $stats['station_counts'] = session_station_car_counts($dbc);
        $stats['on_train_count'] = session_on_train_car_count($dbc);
        $stats['dashboard'] = $dashboard_snapshot;
        $manifest = session_load_manifest($session_nbr, $root);
        $stats['generated'] = session_count_generated_output($manifest);
        $manifest = session_manifest_record_run_stats($manifest, $stats, array_merge($meta, [
            'started_session' => (int) $start_session,
        ]));
        session_save_manifest($session_nbr, $manifest, $root);
    }

    return array_keys($stats_by_session);
}

function session_list_browser_sessions($current, $root = null, $include_archived = null)
{
    $current = max(1, (int) $current);
    $root = $root ?? session_web_root();
    if ($include_archived === null) {
        $include_archived = session_browse_archived_enabled();
    }
    $max = $current;
    if ($include_archived) {
        foreach (session_archived_output_numbers($root) as $n) {
            $max = max($max, (int) $n);
        }
        foreach (glob(session_ensure_output_root($root) . '/session_*', GLOB_ONLYDIR) ?: [] as $d) {
            if (preg_match('#/session_(\d+)$#', $d, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
    }
    // Only sessions up to the current DB session are browsable unless archived
    // output browse is enabled (rewind left session_N folders in rewind_archive).
    $sessions = range(1, $max);
    sort($sessions, SORT_NUMERIC);

    return $sessions;
}

/**
 * Cars physically placed at each station (current_location_id > 0), stations with count > 0 only.
 *
 * @return list<array{station_id: int, station_name: string, car_count: int}>
 */
function session_station_car_counts($dbc)
{
    $rs = mysqli_query(
        $dbc,
        'SELECT routing.id AS station_id,
                routing.station AS station_name,
                routing.sort_seq,
                COUNT(*) AS car_count
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         LEFT JOIN routing ON routing.id = loc.station
         WHERE cars.current_location_id > 0
         GROUP BY routing.id, routing.station, routing.sort_seq
         HAVING car_count > 0
         ORDER BY routing.sort_seq ASC, routing.station ASC'
    );
    if (!$rs) {
        return [];
    }

    $rows = [];
    while ($row = mysqli_fetch_array($rs)) {
        $name = trim((string) ($row['station_name'] ?? ''));
        if ($name === '') {
            $name = 'Unknown station';
        }
        $rows[] = [
            'station_id' => (int) ($row['station_id'] ?? 0),
            'station_name' => $name,
            'car_count' => (int) ($row['car_count'] ?? 0),
        ];
    }

    return $rows;
}

function session_on_train_car_count($dbc)
{
    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         WHERE cars.current_location_id = 0
           AND cars.handled_by_job_id > 0'
    );
    if (!$rs) {
        return 0;
    }
    $row = mysqli_fetch_array($rs);

    return (int) ($row['c'] ?? 0);
}

/** Standalone "Updated <date> · N workflow runs" line for a stats block. */
function session_run_stats_updated_html(array $run_stats)
{
    if (empty($run_stats['updated'])) {
        return '';
    }
    $when = strtotime((string) $run_stats['updated']);
    $when_label = $when ? date('M j, Y g:i A', $when) : (string) $run_stats['updated'];
    $html = '<p class="srs-updated">Updated ' . htmlspecialchars($when_label);
    $history = $run_stats['history'] ?? [];
    if (count($history) > 1) {
        $html .= ' · ' . count($history) . ' workflow runs recorded';
    }
    $html .= '</p>';

    return $html;
}

function session_render_run_stats_block(array $run_stats, $empty_message = '', $show_updated = true)
{
    if (!session_run_stats_has_data($run_stats)) {
        if ($empty_message === '') {
            return '';
        }

        return '<div class="srs srs-empty">'
            . '<p class="muted">' . htmlspecialchars($empty_message) . '</p>'
            . '</div>';
    }

    $operations = $run_stats['operations'] ?? [];
    $move = $run_stats['move_summary'] ?? [];

    $html = '<div class="srs">';

    // --- Metric cards: generated output + session operations ---
    $generated = is_array($run_stats['generated'] ?? null) ? $run_stats['generated'] : [];
    $metric_defs = [
        ['switchlists', 'Switch lists', $generated],
        ['waybills', 'Waybills', $generated],
        ['phases', 'Phases', $generated],
        ['trains', 'Trains', $generated],
        ['generated', 'Orders generated', $operations],
        ['filled', 'Orders filled', $operations],
        ['repositioned', 'Empties repositioned', $operations],
        ['load_unload', 'Loads/unloads', $operations],
    ];
    $metric_cards = [];
    foreach ($metric_defs as [$key, $label, $source]) {
        if (!is_array($source) || !array_key_exists($key, $source)) {
            continue;
        }
        $value = (int) $source[$key];
        $metric_cards[] = '<div class="srs-card">'
            . '<span class="srs-card-num">' . $value . '</span>'
            . '<span class="srs-card-cap">' . htmlspecialchars($label) . '</span>'
            . '</div>';
    }
    if ($metric_cards !== []) {
        $html .= '<div class="srs-metrics">' . implode('', $metric_cards) . '</div>';
    }

    // --- Detail panels: moves, stations, dashboard ---
    $panels = [];

    $picked_up = $move['picked_up'] ?? [];
    $set_out = $move['set_out'] ?? [];
    $jobs = array_values(array_unique(array_merge(array_keys($picked_up), array_keys($set_out))));
    sort($jobs, SORT_NATURAL | SORT_FLAG_CASE);
    if ($jobs !== []) {
        $rows = '';
        foreach ($jobs as $job) {
            $picked = (int) ($picked_up[$job] ?? 0);
            $setout = (int) ($set_out[$job] ?? 0);
            if ($picked === 0 && $setout === 0) {
                continue;
            }
            $rows .= '<tr><td>' . htmlspecialchars($job) . '</td>'
                . '<td>' . ($picked > 0 ? $picked : '—') . '</td>'
                . '<td>' . ($setout > 0 ? $setout : '—') . '</td></tr>';
        }
        if ($rows !== '') {
            $panels[] = '<section class="srs-panel">'
                . '<h4 class="srs-panel-title">Cars moved by train</h4>'
                . '<table class="srs-table"><thead><tr>'
                . '<th>Train</th><th class="srs-num-col">Picked up</th><th class="srs-num-col">Set out</th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table></section>';
        }
    }

    $station_counts = is_array($run_stats['station_counts'] ?? null) ? $run_stats['station_counts'] : [];
    $on_train_count = (int) ($run_stats['on_train_count'] ?? 0);
    if ($station_counts !== [] || $on_train_count > 0) {
        $rows = '';
        foreach ($station_counts as $row) {
            if (!is_array($row)) {
                continue;
            }
            $count = (int) ($row['car_count'] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $name = trim((string) ($row['station_name'] ?? '')) ?: 'Unknown station';
            $rows .= '<tr><td>' . htmlspecialchars($name) . '</td>'
                . '<td class="srs-num-col">' . $count . '</td></tr>';
        }
        if ($on_train_count > 0) {
            $rows .= '<tr><td>On trains (not at track)</td>'
                . '<td class="srs-num-col">' . $on_train_count . '</td></tr>';
        }
        if ($rows !== '') {
            $panels[] = '<section class="srs-panel">'
                . '<h4 class="srs-panel-title">Cars by station</h4>'
                . '<table class="srs-table"><thead><tr>'
                . '<th>Station</th><th class="srs-num-col">Cars</th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table></section>';
        }
    }

    $dashboard = is_array($run_stats['dashboard'] ?? null) ? $run_stats['dashboard'] : [];
    if ($dashboard !== []) {
        require_once __DIR__ . '/operations_stats.php';
        $dash_rows = '';
        foreach (operations_dashboard_condition_variables() as $var) {
            $key = $var['key'] ?? '';
            if ($key === '' || $key === 'session_nbr' || $key === 'scale_to_weigh'
                || !array_key_exists($key, $dashboard)) {
                continue;
            }
            $dash_rows .= '<div class="srs-dash-row">'
                . '<dt>' . htmlspecialchars((string) ($var['label'] ?? $key)) . '</dt>'
                . '<dd>' . (int) $dashboard[$key] . '</dd></div>';
        }
        if ($dash_rows !== '') {
            $dash_live = !empty($run_stats['dashboard_live']);
            $badge = '<span class="srs-badge srs-badge-' . ($dash_live ? 'live' : 'end') . '">'
                . ($dash_live ? 'live' : 'end of session') . '</span>';
            $panels[] = '<section class="srs-panel srs-panel-wide">'
                . '<h4 class="srs-panel-title">Operations dashboard ' . $badge . '</h4>'
                . '<dl class="srs-dash">' . $dash_rows . '</dl></section>';
        }
    }

    if ($panels !== []) {
        $html .= '<div class="srs-panels">' . implode('', $panels) . '</div>';
    }

    if ($show_updated) {
        $html .= session_run_stats_updated_html($run_stats);
    }

    $html .= '</div>';

    return $html;
}

function session_simulator_aggregate_train_moves(array $cycles)
{
    $picked_up = [];
    $set_out = [];

    $add_counts = static function (array &$totals, $job, $count) {
        $job = trim((string) $job);
        if ($job === '' || $count <= 0) {
            return;
        }
        $totals[$job] = ($totals[$job] ?? 0) + $count;
    };

    foreach ($cycles as $cycle) {
        foreach ($cycle['log'] ?? [] as $entry) {
            if (!is_array($entry) || !empty($entry['skipped'])) {
                continue;
            }

            if (isset($entry['stats']) && is_array($entry['stats'])) {
                $job = trim((string) ($entry['job'] ?? ''));
                if ($job !== '') {
                    $add_counts($picked_up, $job, (int) ($entry['stats']['picked_up'] ?? 0));
                    $add_counts($set_out, $job, (int) ($entry['stats']['set_out'] ?? 0));
                }
            }

            if (($entry['dispatch'] ?? '') === 'pick_up_cars' && array_key_exists('picked_up', $entry)) {
                if (!empty($entry['picked_up_by_job']) && is_array($entry['picked_up_by_job'])) {
                    foreach ($entry['picked_up_by_job'] as $job => $count) {
                        $add_counts($picked_up, $job, (int) $count);
                    }
                } else {
                    $picked = $entry['picked_up'];
                    $count = is_array($picked) ? count($picked) : (int) $picked;
                    $add_counts($picked_up, $entry['job'] ?? '', $count);
                }
            }

            if (($entry['dispatch'] ?? '') === 'set_out_cars' && array_key_exists('set_out', $entry)) {
                if (!empty($entry['set_out_by_job']) && is_array($entry['set_out_by_job'])) {
                    foreach ($entry['set_out_by_job'] as $job => $count) {
                        $add_counts($set_out, $job, (int) $count);
                    }
                } else {
                    $set_out_val = $entry['set_out'];
                    $count = is_array($set_out_val) ? count($set_out_val) : (int) $set_out_val;
                    $add_counts($set_out, $entry['job'] ?? '', $count);
                }
            }
        }
    }

    ksort($picked_up);
    ksort($set_out);

    return [
        'picked_up' => $picked_up,
        'set_out' => $set_out,
    ];
}

function session_simulator_aggregate_run_stats(array $cycles)
{
    $operations = [
        'generated' => 0,
        'filled' => 0,
        'repositioned' => 0,
        'load_unload' => 0,
    ];

    foreach ($cycles as $cycle) {
        foreach ($cycle['log'] ?? [] as $entry) {
            if (!is_array($entry) || !empty($entry['skipped'])) {
                continue;
            }
            if (array_key_exists('generated', $entry)) {
                $operations['generated'] += (int) $entry['generated'];
            }
            if (array_key_exists('filled', $entry)) {
                $operations['filled'] += (int) $entry['filled'];
            }
            if (array_key_exists('repositioned', $entry)) {
                $operations['repositioned'] += (int) $entry['repositioned'];
            }
            if (array_key_exists('load_unload', $entry)) {
                $operations['load_unload'] += (int) $entry['load_unload'];
            }
            if (isset($entry['stats']) && is_array($entry['stats']) && !empty($entry['stats']['load_unload'])) {
                $operations['load_unload'] += (int) $entry['stats']['load_unload'];
            }
        }
    }

    return [
        'operations' => $operations,
        'move_summary' => session_simulator_aggregate_train_moves($cycles),
    ];
}

function session_nav_stylesheet_path()
{
    return '/sts/session-nav.css';
}

function session_bootstrap_head_links()
{
    return '<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">'
        . '<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">';
}

function session_nav_stylesheet_link($href = null)
{
    $href = $href ?? session_nav_stylesheet_path();

    // Cache-bust local stylesheets by file mtime so browsers pick up CSS
    // changes immediately instead of serving a stale cached copy.
    if (is_string($href) && strpos($href, '//') === false && strpos($href, '?') === false) {
        $basename = basename(parse_url($href, PHP_URL_PATH) ?: $href);
        $fs_path = __DIR__ . '/' . $basename;
        if (is_file($fs_path)) {
            $href .= '?v=' . filemtime($fs_path);
        }
    }

    return '<link rel="stylesheet" href="' . htmlspecialchars((string) $href) . '">';
}

/**
 * Safe print helper for generated pages. Cursor / VS Code Simple Browser
 * (Electron) often crashes the whole window on window.print(); steer those
 * clients to an external browser instead of calling print().
 */
function session_safe_print_script()
{
    return '<script>(function(){if(window.stsSafePrint)return;'
        . 'window.stsSafePrint=function(){try{'
        . 'var ua=navigator.userAgent||"";'
        . 'if(/Electron|Cursor|VSCode|Code\\/\\d|Simple Browser/i.test(ua)'
        . '||typeof window.acquireVsCodeApi==="function"){'
        . 'alert("Printing from Cursor\u2019s preview can close the window. '
        . 'Copy this page\u2019s URL into Chrome or Safari, then Print.");'
        . 'return;}'
        . 'window.print();'
        . '}catch(e){alert("Print failed \u2014 open this page in an external browser.");}};'
        . '})();</script>';
}

function session_static_head_assets($css_href = null)
{
    return session_bootstrap_head_links()
        . session_nav_stylesheet_link($css_href)
        . session_safe_print_script();
}

function session_nav_icon_for_label($label)
{
    $key = strtolower(trim((string) $label));
    static $map = [
        'sts main menu' => 'house',
        'session editor' => 'pencil-square',
        'all sessions' => 'collection',
        'sessions' => 'collection',
        'prev' => 'chevron-left',
        'next' => 'chevron-right',
        'mobile' => 'phone',
        'full sheet' => 'file-earmark',
        'dot matrix' => 'printer',
        'work order' => 'clipboard-check',
        'x2010' => 'file-earmark-spreadsheet',
        'waybills' => 'file-text',
        'waybill list' => 'file-text',
        'csv' => 'filetype-csv',
        'json' => 'filetype-json',
    ];
    if (preg_match('/^session \d+$/', $key)) {
        return 'calendar-event';
    }
    if (str_contains($key, ' index') || str_contains($key, 'print all')) {
        return 'list-ul';
    }

    return $map[$key] ?? '';
}

/** @param list<array{href: string, label: string, icon?: string, active?: bool}> $links */
function session_nav_bar_html(array $links, $trail = '', $extra_class = 'noprint')
{
    $class = 'navbar navbar-dark';
    if ($extra_class !== '') {
        $class .= ' ' . $extra_class;
    }
    // Split into left- and right-aligned groups. Links flagged 'right' => true are
    // rendered at the right edge of the bar (after the trail text).
    $left = [];
    $right = [];
    foreach ($links as $link) {
        if (!empty($link['right'])) {
            $right[] = $link;
        } else {
            $left[] = $link;
        }
    }

    $render_link = static function (array $link, $with_spacer) {
        $href = trim((string) ($link['href'] ?? ''));
        $label = trim((string) ($link['label'] ?? ''));
        if ($href === '' || $label === '') {
            return '';
        }
        $icon = trim((string) ($link['icon'] ?? session_nav_icon_for_label($label)));
        $btn_class = 'btn btn-outline-light btn-sm';
        if ($with_spacer) {
            $btn_class .= ' ms-auto';
        }
        if (!empty($link['active'])) {
            $btn_class .= ' active';
        }
        $out = '<a href="' . htmlspecialchars($href) . '" class="' . $btn_class . '">';
        if ($icon !== '') {
            $out .= '<i class="bi bi-' . htmlspecialchars($icon) . '"></i> ';
        }
        return $out . htmlspecialchars($label) . '</a>';
    };

    $html = '<nav class="' . $class . '" style="background-color: #343a40;">';
    $html .= '<div class="container-fluid"><div class="d-flex flex-wrap align-items-center gap-2 w-100">';
    // The ms-auto spacer goes on the first element after the left group: the trail
    // if present, otherwise the first right-aligned link.
    $spacer_used = false;
    foreach ($left as $link) {
        $html .= $render_link($link, false);
    }
    if ($trail !== '') {
        $html .= '<span class="navbar-text text-white-50 small ms-auto">' . htmlspecialchars($trail) . '</span>';
        $spacer_used = true;
    }
    foreach ($right as $link) {
        $html .= $render_link($link, !$spacer_used);
        $spacer_used = true;
    }
    $html .= '</div></div></nav>';

    return $html;
}

/** @param list<array{href: string, label: string, icon?: string, active?: bool}> $links */
function session_render_nav_bar(array $links, $trail = '')
{
    echo session_nav_bar_html($links, $trail);
}

function session_write_session_index($session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $dir = session_dir_for($session_nbr, $root);
    session_ensure_writable_dir($dir);
    $template = __DIR__ . '/session_index_template.php';
    if (is_readable($template)) {
        copy($template, $dir . '/index.php');
    }
    $manifest = session_load_manifest($session_nbr, $root);
    session_ensure_output_stubs($session_nbr, $manifest, $root);
}

/** Relative href from a waybill index to another session's waybill index (same phase when set). */
function session_waybill_index_rel_href($session_nbr, $phase_num = null)
{
    if ($phase_num !== null) {
        return '../../../session_' . (int) $session_nbr
            . '/phase_' . session_phase_pad($phase_num)
            . '/waybills/index.html';
    }

    return '../../session_' . (int) $session_nbr . '/waybills/index.html';
}

/** Relative href from a waybills print-all page to the same-scope print-all in another session. */
function session_waybill_print_all_rel_href($session_nbr, $basename)
{
    $basename = trim((string) $basename);
    if ($basename === '') {
        return '';
    }

    return '../../session_' . (int) $session_nbr . '/waybills/' . $basename;
}

/**
 * Icon-only prev/next session button matching session_overview.php:
 * chevron with title="Session N" (no "Session N" text in the button).
 *
 * @param 'prev'|'next' $direction
 * @param string|null   $href      null/empty → disabled
 * @param int|null      $session_nbr Target session for title attribute
 * @param string        $btn_class
 */
function session_nav_chevron_btn_html($direction, $href, $session_nbr = null, $btn_class = 'btn btn-outline-dark btn-sm')
{
    $direction = $direction === 'next' ? 'next' : 'prev';
    $icon = $direction === 'next' ? 'chevron-right' : 'chevron-left';
    $btn_class = trim((string) $btn_class);
    if ($btn_class === '') {
        $btn_class = 'btn btn-outline-dark btn-sm';
    }
    $icon_html = '<i class="bi bi-' . $icon . '"></i>';
    if ($href === null || $href === '') {
        return '<span class="' . htmlspecialchars($btn_class, ENT_QUOTES) . ' disabled" aria-disabled="true">'
            . $icon_html . '</span>';
    }
    $title = $session_nbr !== null
        ? ('Session ' . (int) $session_nbr)
        : ($direction === 'next' ? 'Next session' : 'Previous session');

    return '<a class="' . htmlspecialchars($btn_class, ENT_QUOTES) . '" href="'
        . htmlspecialchars((string) $href, ENT_QUOTES)
        . '" title="' . htmlspecialchars($title, ENT_QUOTES) . '">'
        . $icon_html . '</a>';
}

/**
 * Absolute /sts/so.php?f=… URL for a session-relative output path.
 * Used for <select> option values (not rewritten by so.php's href rewriter).
 */
function session_so_f_href($session_rel)
{
    $session_rel = ltrim(str_replace('\\', '/', (string) $session_rel), '/');
    if ($session_rel === '') {
        return '';
    }

    return '/sts/' . session_output_url($session_rel);
}

/**
 * Relative href from a page under session_from/ to session_to/<suffix>.
 * so.php rewrites these to so.php?f=… at serve time.
 */
function session_sibling_rel_href($to_session, $suffix_under_session)
{
    $to_session = (int) $to_session;
    $suffix = ltrim(str_replace('\\', '/', (string) $suffix_under_session), '/');
    if ($to_session < 1 || $suffix === '') {
        return '';
    }

    return '../session_' . $to_session . '/' . $suffix;
}

/**
 * Full session picker for so.php pages: skip-start, prev, select, next, skip-end
 * (same controls as session_overview / station report). Stays on the same view
 * via $suffix_under_session (path after session_N/, e.g. print_all.html,
 * train_D749.print_all.html, waybills/print_all.html).
 *
 * Optional $suffix_by_session overrides the suffix per target session (used when
 * a train's job key differs across sessions). Empty override disables that target.
 *
 * @param array<int,string>|null $suffix_by_session
 */
function session_nav_row_picker_html(
    $session_nbr,
    array $sessions,
    $current_db,
    $suffix_under_session,
    $wrapper_class,
    $btn_class = 'btn btn-outline-dark btn-sm',
    $suffix_by_session = null
)
{
    $session_nbr = (int) $session_nbr;
    $sessions = array_values(array_map('intval', $sessions));
    $default_suffix = ltrim(str_replace('\\', '/', (string) $suffix_under_session), '/');
    if ($session_nbr < 1 || count($sessions) < 2 || $default_suffix === '') {
        return '';
    }

    $rel_for = static function ($n) use ($default_suffix, $suffix_by_session) {
        $n = (int) $n;
        $suffix = $default_suffix;
        if (is_array($suffix_by_session)) {
            if (!array_key_exists($n, $suffix_by_session)) {
                return '';
            }
            $suffix = ltrim(str_replace('\\', '/', (string) $suffix_by_session[$n]), '/');
            if ($suffix === '') {
                return '';
            }
        }

        return session_sibling_rel_href($n, $suffix);
    };
    $abs_for = static function ($n) use ($default_suffix, $suffix_by_session) {
        $n = (int) $n;
        $suffix = $default_suffix;
        if (is_array($suffix_by_session)) {
            if (!array_key_exists($n, $suffix_by_session)) {
                return '';
            }
            $suffix = ltrim(str_replace('\\', '/', (string) $suffix_by_session[$n]), '/');
            if ($suffix === '') {
                return '';
            }
        }

        return session_so_f_href('session_' . $n . '/' . $suffix);
    };

    $prev = session_adjacent_session($sessions, $session_nbr, 'prev');
    $next = session_adjacent_session($sessions, $session_nbr, 'next');
    list($first, $last) = session_edge_sessions($sessions);
    $skip_first = ($first !== null && (int) $first !== $session_nbr) ? (int) $first : null;
    $skip_last = ($last !== null && (int) $last !== $session_nbr) ? (int) $last : null;

    $skip_btn = static function ($target, $icon) use ($rel_for, $btn_class) {
        $href = $target !== null ? $rel_for($target) : '';
        $title = $target !== null
            ? (($icon === 'skip-start-fill' ? 'First' : 'Last') . ' session (' . (int) $target . ')')
            : ($icon === 'skip-start-fill' ? 'First session' : 'Last session');
        if ($target === null || $href === '') {
            return '<span class="' . htmlspecialchars($btn_class, ENT_QUOTES)
                . ' session-skip-btn disabled" aria-disabled="true" title="'
                . htmlspecialchars($title, ENT_QUOTES) . '"><i class="bi bi-'
                . htmlspecialchars($icon, ENT_QUOTES) . '"></i></span>';
        }

        return '<a class="' . htmlspecialchars($btn_class, ENT_QUOTES)
            . ' session-skip-btn" href="' . htmlspecialchars($href, ENT_QUOTES)
            . '" title="' . htmlspecialchars($title, ENT_QUOTES) . '"><i class="bi bi-'
            . htmlspecialchars($icon, ENT_QUOTES) . '"></i></a>';
    };

    $options = '';
    foreach ($sessions as $n) {
        $abs = $abs_for($n);
        if ($abs === '') {
            continue;
        }
        $label = 'Session ' . $n . ($n === (int) $current_db ? ' (current)' : '');
        $options .= '<option value="' . htmlspecialchars($abs, ENT_QUOTES) . '"'
            . ($n === $session_nbr ? ' selected' : '') . '>'
            . htmlspecialchars($label) . '</option>';
    }
    if ($options === '') {
        return '';
    }

    $html = '<div class="session-nav-row ' . htmlspecialchars($wrapper_class, ENT_QUOTES) . '">';
    $html .= $skip_btn($skip_first, 'skip-start-fill');
    $html .= session_nav_chevron_btn_html('prev', $prev !== null ? $rel_for($prev) : null, $prev, $btn_class);
    $html .= '<select class="form-select form-select-sm session-nav-select" style="width:auto;display:inline-block;"'
        . ' title="Jump to same view in another session"'
        . ' onchange="if(this.value){window.location.href=this.value;}">'
        . $options . '</select>';
    $html .= session_nav_chevron_btn_html('next', $next !== null ? $rel_for($next) : null, $next, $btn_class);
    $html .= $skip_btn($skip_last, 'skip-end-fill');
    $html .= '</div>';

    return $html;
}

/**
 * Prev/next session buttons for a waybills print-all page. Links to the same-scope
 * print-all file (session/job) in adjacent sessions; a direction is disabled when
 * that session has no such file (e.g. a job that didn't run there). Intended to be
 * refreshed at serve time by so.php so existence checks reflect the current set.
 */
function session_waybill_print_all_session_nav_html($session_nbr, $basename, $dbc = null, $root = null)
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    $basename = trim((string) $basename);
    if ($session_nbr < 1 || $basename === '') {
        return '';
    }
    if ($dbc === null) {
        require_once __DIR__ . '/open_db.php';
        $dbc = open_db();
    }
    $current_db = session_get_db_session($dbc);
    $sessions = session_list_browser_sessions($current_db, $root);
    if (count($sessions) < 2) {
        return '';
    }

    // Prefer sessions that already have this print-all; still offer others so
    // so.php can build on demand / show not-found rather than dumping to overview.
    $suffix = 'waybills/' . $basename;
    $suffix_by = [];
    foreach ($sessions as $n) {
        $n = (int) $n;
        $suffix_by[$n] = $suffix;
    }

    return session_nav_row_picker_html(
        $session_nbr,
        $sessions,
        $current_db,
        $suffix,
        'waybill-print-all-session-nav noprint',
        'btn btn-outline-dark btn-sm',
        $suffix_by
    );
}

/** Session picker for a waybill index page (keeps so.php waybill-index view). */
function session_waybill_session_nav_html($session_nbr, $phase_num = null, $dbc = null, $root = null)
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    if ($session_nbr < 1) {
        return '';
    }
    if ($dbc === null) {
        require_once __DIR__ . '/open_db.php';
        // open_db() returns a shared static connection; never close it here or we
        // would break callers that are still using the same handle.
        $dbc = open_db();
    }
    $current_db = session_get_db_session($dbc);
    $sessions = session_list_browser_sessions($current_db, $root);
    if (count($sessions) < 2) {
        return '';
    }
    $phase_num = $phase_num !== null ? (int) $phase_num : null;
    $suffix = ($phase_num !== null && $phase_num > 0)
        ? ('phase_' . session_phase_pad($phase_num) . '/waybills/index.html')
        : 'waybills/index.html';

    // Waybill index pages sit one level deeper when phased; sibling href from
    // session_N/waybills/ is ../session_M/…, but from session_N/phase_XX/waybills/
    // it needs ../../session_M/…. session_sibling_rel_href assumes one ../ —
    // build per-depth suffix via relative helper for the phase case.
    if ($phase_num !== null && $phase_num > 0) {
        $suffix_by = [];
        foreach ($sessions as $n) {
            $suffix_by[(int) $n] = 'phase_' . session_phase_pad($phase_num) . '/waybills/index.html';
        }
        // Custom relative depth: from phase_XX/waybills → ../../session_N/...
        $html = session_nav_row_picker_html(
            $session_nbr,
            $sessions,
            $current_db,
            $suffix,
            'waybill-session-nav',
            'btn btn-outline-dark',
            $suffix_by
        );
        // Fix relative depth: picker emits ../session_N/… but we need ../../session_N/…
        return str_replace('href="../session_', 'href="../../session_', $html);
    }

    return session_nav_row_picker_html(
        $session_nbr,
        $sessions,
        $current_db,
        $suffix,
        'waybill-session-nav',
        'btn btn-outline-dark'
    );
}

/** Relative href from a job print-all page to the same train/phase in another session. */
function session_switchlist_job_print_all_rel_href($session_nbr, $phase_num, $job)
{
    $job = trim((string) $job);
    if ($job === '') {
        return '';
    }

    return '../../../session_' . (int) $session_nbr
        . '/phase_' . session_phase_pad($phase_num)
        . '/' . rawurlencode($job)
        . '/print_all.html';
}

/** Session picker for a per-job switch-list print-all page. */
function session_switchlist_job_print_all_session_nav_html($session_nbr, $phase_num, $job, $dbc = null, $root = null)
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    $phase_num = (int) $phase_num;
    $job = trim((string) $job);
    if ($session_nbr < 1 || $phase_num < 1 || $job === '') {
        return '';
    }
    if ($dbc === null) {
        require_once __DIR__ . '/open_db.php';
        $dbc = open_db();
    }
    $current_db = session_get_db_session($dbc);
    $sessions = session_list_browser_sessions($current_db, $root);
    if (count($sessions) < 2) {
        return '';
    }
    $suffix = 'phase_' . session_phase_pad($phase_num) . '/' . $job . '/print_all.html';
    // Job print-all lives at session_N/phase_XX/JOB/ — two levels deeper than
    // session_N/, so sibling links need ../../../session_M/…
    $html = session_nav_row_picker_html(
        $session_nbr,
        $sessions,
        $current_db,
        $suffix,
        'switchlist-job-print-all-session-nav noprint'
    );

    return str_replace('href="../session_', 'href="../../../session_', $html);
}

/** Relative href from a per-train print-all page to the same train's print-all in another session. */
function session_switchlist_train_print_all_rel_href($session_nbr, $job)
{
    $job = trim((string) $job);
    if ($job === '') {
        return '';
    }

    return '../session_' . (int) $session_nbr . '/train_' . rawurlencode($job) . '.print_all.html';
}

/**
 * Resolve the print-all rel href for the "same" train in another session. Trains
 * are matched by their operator-facing display name (stable across sessions even
 * when the underlying job key differs), so this maps the current train's display
 * name to the target session's matching primary job key. Returns '' when the
 * target session has no such train (button is then disabled by the caller).
 */
function session_switchlist_train_print_all_rel_href_by_name($target_session, $display_name, $root = null)
{
    $target_session = (int) $target_session;
    $display_name = trim((string) $display_name);
    if ($target_session < 1 || $display_name === '') {
        return '';
    }
    $groups = session_job_group_map($target_session, null, $root);
    if (!isset($groups[$display_name]) || count($groups[$display_name]) === 0) {
        return '';
    }

    return session_switchlist_train_print_all_rel_href($target_session, $groups[$display_name][0]);
}

/** Session picker for a per-train switch-list print-all page. */
function session_switchlist_train_print_all_session_nav_html($session_nbr, $job, $dbc = null, $root = null, $style = '')
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    $job = trim((string) $job);
    if ($session_nbr < 1 || $job === '') {
        return '';
    }
    if ($dbc === null) {
        require_once __DIR__ . '/open_db.php';
        $dbc = open_db();
    }
    $current_db = session_get_db_session($dbc);
    $sessions = session_list_browser_sessions($current_db, $root);
    if (count($sessions) < 2) {
        return '';
    }

    $style = trim((string) $style);
    $style_suffix = $style !== '' ? ('_' . session_normalize_switchlist_style($style)) : '';

    // Match the same train across sessions by display name (job key may differ).
    $display = session_job_display_map($session_nbr, null, $root);
    $display_name = $display[$job] ?? $job;
    $suffix_by = [];
    $fallback_suffix = 'train_' . $job . '.print_all' . $style_suffix . '.html';
    foreach ($sessions as $n) {
        $n = (int) $n;
        $groups = session_job_group_map($n, null, $root);
        if (!isset($groups[$display_name]) || count($groups[$display_name]) === 0) {
            // Keep a best-effort same-key path so navigation still tries so.php
            // (on-demand build) rather than falling back to session_overview.
            $suffix_by[$n] = $fallback_suffix;
            continue;
        }
        $suffix_by[$n] = 'train_' . $groups[$display_name][0] . '.print_all' . $style_suffix . '.html';
    }

    return session_nav_row_picker_html(
        $session_nbr,
        $sessions,
        $current_db,
        $fallback_suffix,
        'switchlist-train-print-all-session-nav noprint',
        'btn btn-outline-dark btn-sm',
        $suffix_by
    );
}

/** Relative href from a session print-all page to another session's print-all (same style when set). */
function session_switchlist_print_all_rel_href($session_nbr, $style = '')
{
    $rel = 'session_' . (int) $session_nbr . '/print_all';
    if ($style !== '') {
        $rel .= '_' . session_normalize_switchlist_style($style);
    }

    return '../' . $rel . '.html';
}

/** Session picker for a switch-list print-all page (combined or per-style). */
function session_switchlist_print_all_session_nav_html($session_nbr, $style = '', $dbc = null, $root = null)
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    if ($session_nbr < 1) {
        return '';
    }
    if ($dbc === null) {
        require_once __DIR__ . '/open_db.php';
        $dbc = open_db();
    }
    $current_db = session_get_db_session($dbc);
    $sessions = session_list_browser_sessions($current_db, $root);
    if (count($sessions) < 2) {
        return '';
    }
    $suffix = 'print_all';
    $style = trim((string) $style);
    if ($style !== '') {
        $suffix .= '_' . session_normalize_switchlist_style($style);
    }
    $suffix .= '.html';

    return session_nav_row_picker_html(
        $session_nbr,
        $sessions,
        $current_db,
        $suffix,
        'switchlist-print-all-session-nav noprint'
    );
}

/** Header nav items for a generated waybill index page. */
function session_waybill_index_nav_items($session_nbr, $back_href, $back_label)
{
    $session_nbr = (int) $session_nbr;
    $items = [
        ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
    ];
    // Back navigation is normalized to the per-session overview (session_overview
    // .php) so waybill pages match the switch-list pages. The legacy '../index.php'
    // back link only ever redirected there anyway; a custom label is preserved.
    if ($session_nbr >= 1) {
        $label = (trim((string) $back_label) !== '' && $back_label !== 'All Sessions')
            ? (string) $back_label
            : 'Session ' . $session_nbr;
        $items[] = ['href' => '/sts/session_overview.php?session=' . $session_nbr, 'label' => $label, 'icon' => 'calendar-event'];
    } elseif (!empty($back_href) && trim((string) $back_label) !== '' && $back_label !== 'All Sessions') {
        $items[] = ['href' => $back_href, 'label' => $back_label, 'icon' => 'calendar-event'];
    }

    return $items;
}

function session_write_empty_waybill_index($out_dir, array $options = [])
{
    if (is_file(rtrim($out_dir, '/') . '/index.html')) {
        return ['path' => rtrim($out_dir, '/') . '/index.html', 'count' => 0, 'skipped' => true];
    }
    session_ensure_writable_dir($out_dir);
    $title = $options['title'] ?? 'Waybills';
    $back = $options['back_href'] ?? '../session.php';
    $back_label = $options['back_label'] ?? 'All Sessions';
    $message = $options['message'] ?? 'No waybills available yet. Run Generate Waybill List in the workflow after switch lists.';
    $session_nbr = isset($options['session_nbr']) ? (int) $options['session_nbr'] : 0;
    $phase_num = array_key_exists('phase_num', $options) ? $options['phase_num'] : null;
    $index_html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . session_static_head_assets()
        . '</head><body>';
    $index_html .= session_nav_bar_html(
        session_waybill_index_nav_items($session_nbr, $back, $back_label),
        $title
    );
    $nav_row = $session_nbr >= 1
        ? session_waybill_session_nav_html(
            $session_nbr,
            $phase_num,
            $options['dbc'] ?? null,
            $options['root'] ?? null
        )
        : '';
    $index_html .= '<main><h1>' . htmlspecialchars($title) . '</h1>'
        . $nav_row
        . '<div class="card"><p>' . htmlspecialchars($message) . '</p>'
        . '<ul><li>No waybills available.</li></ul></div></main></body></html>';
    file_put_contents($out_dir . '/index.html', $index_html);
    $print_all = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . ' — print all</title>'
        . session_static_head_assets()
        . '</head><body>';
    $print_all .= session_nav_bar_html([
        ['href' => 'index.html', 'label' => 'Waybill list', 'icon' => 'file-text'],
    ], 'Print all');
    $print_all .= '<main><h1>' . htmlspecialchars($title) . '</h1>'
        . '<div class="card"><p>No waybills to print.</p></div></main></body></html>';
    file_put_contents($out_dir . '/print_all.html', $print_all);
    return [
        'path' => $out_dir . '/index.html',
        'print_all' => $out_dir . '/print_all.html',
        'count' => 0,
    ];
}

function session_ensure_output_stubs($session_nbr, array $manifest, $root = null)
{
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $root = $root ?? session_web_root();
    $session_dir = session_dir_for($session_nbr, $root);
    session_ensure_writable_dir($session_dir);

    // The canonical session page is index.php. A stale/legacy index.html (e.g. the
    // old "HART Switchlists" root stub) would otherwise be reached from links that
    // point at session_N/index.html. Replace it with a redirect to index.php.
    $session_index_html = $session_dir . '/index.html';
    $existing_index = is_file($session_index_html) ? (string) @file_get_contents($session_index_html) : '';
    if ($existing_index === '' || strpos($existing_index, 'url=index.php') === false) {
        @file_put_contents(
            $session_index_html,
            '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta http-equiv="refresh" content="0; url=index.php">'
            . '<link rel="canonical" href="index.php">'
            . '<title>Session ' . (int) $session_nbr . '</title></head>'
            . '<body><p><a href="index.php">Go to session ' . (int) $session_nbr . '</a></p></body></html>'
        );
    }

    session_write_empty_waybill_index(session_waybill_dir_for($session_nbr, null, $root), [
        'title' => 'Waybills — session ' . (int) $session_nbr,
        'back_href' => '../index.php',
        'back_label' => 'Session ' . (int) $session_nbr,
        'session_nbr' => $session_nbr,
        'phase_num' => null,
        'root' => $root,
    ]);

    foreach ($manifest['phases'] ?? [] as $phase) {
        $phase_num = (int) ($phase['phase'] ?? 0);
        if ($phase_num < 1) {
            continue;
        }
        $phase_dir = session_phase_output_dir($session_nbr, $phase_num, $root);
        session_ensure_writable_dir($phase_dir);
        session_write_empty_waybill_index(session_waybill_dir_for($session_nbr, $phase_num, $root), [
            'title' => 'Waybills — session ' . (int) $session_nbr . ', phase ' . $phase_num,
            'back_href' => '../../index.php',
            'back_label' => 'Session ' . (int) $session_nbr,
            'session_nbr' => $session_nbr,
            'phase_num' => $phase_num,
            'root' => $root,
        ]);
        foreach ($phase['jobs'] ?? [] as $job) {
            $job = trim((string) $job);
            if ($job === '') {
                continue;
            }
            $job_dir = master_sw_job_output_dir($phase_dir, $job);
            if (!is_file(master_sw_job_index_path($job_dir))) {
                master_sw_render_empty_job_index(
                    null,
                    $job,
                    $job_dir,
                    (string) $session_nbr,
                    'No switch list files found for this train and phase. Run Generate Switch Lists in the workflow.'
                );
            }
        }
    }

    foreach (array_keys($manifest['jobs'] ?? []) as $job) {
        $job = trim((string) $job);
        if ($job === '') {
            continue;
        }
        foreach ($manifest['jobs'][$job]['phases'] ?? [] as $phase_num) {
            $phase_dir = session_phase_output_dir($session_nbr, (int) $phase_num, $root);
            $job_dir = master_sw_job_output_dir($phase_dir, $job);
            if (!is_file(master_sw_job_index_path($job_dir))) {
                master_sw_render_empty_job_index(
                    null,
                    $job,
                    $job_dir,
                    (string) $session_nbr,
                    'No switch list files found for this train and phase. Run Generate Switch Lists in the workflow.'
                );
            }
        }
    }
}

function session_condition_variables()
{
    require_once __DIR__ . '/operations_stats.php';

    return operations_dashboard_condition_variables();
}

function session_condition_variable_label($key)
{
    require_once __DIR__ . '/operations_stats.php';

    return operations_dashboard_condition_label($key);
}

function session_condition_operators()
{
    return ['=', '!=', '<', '<=', '>', '>='];
}

function session_evaluate_context($dbc, array $config = [])
{
    require_once __DIR__ . '/operations_stats.php';

    $session = function_exists('warm_start_get_session')
        ? warm_start_get_session($dbc)
        : session_get_db_session($dbc);
    $session_int = (int) $session;

    return array_merge(operations_get_stats($dbc), [
        'session_nbr' => $session_int,
        'session_is_odd' => $session_int % 2 === 1 ? 1 : 0,
        'session_is_even' => $session_int % 2 === 0 ? 1 : 0,
        '_dbc' => $dbc,
        '_config' => $config,
    ]);
}

function session_evaluate_condition(array $ctx, $variable, $operator, $value, array $extra = [])
{
    $aliases = [
        'unfilled_count' => 'unfilled_orders',
        'awaiting_assignment' => 'unassigned',
    ];
    if (isset($aliases[$variable])) {
        $variable = $aliases[$variable];
    }

    $allowed = array_column(session_condition_variables(), 'key');
    if (!in_array($variable, $allowed, true)) {
        return false;
    }

    $left = (float) ($ctx[$variable] ?? 0);
    $right = (float) $value;
    switch ($operator) {
        case '=': return $left == $right;
        case '!=': return $left != $right;
        case '<': return $left < $right;
        case '<=': return $left <= $right;
        case '>': return $left > $right;
        case '>=': return $left >= $right;
        default: return true;
    }
}

function session_manual_generate_shipment($dbc, $shipment_code)
{
    $session = function_exists('warm_start_get_session')
        ? warm_start_get_session($dbc)
        : session_get_db_session($dbc);
    $code_esc = mysqli_real_escape_string($dbc, $shipment_code);
    $rs = mysqli_query($dbc, 'SELECT id, min_amount, max_amount FROM shipments WHERE code = "' . $code_esc . '" LIMIT 1');
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return ['generated' => 0, 'error' => 'Shipment not found: ' . $shipment_code];
    }
    $ship = mysqli_fetch_array($rs);
    $shipment_id = (int) $ship['id'];
    mysqli_query($dbc, 'UPDATE shipments SET last_ship_date = ' . (int) $session . ' WHERE id = "' . $shipment_id . '"');

    $rs = mysqli_query($dbc, 'SELECT max(substr(waybill_number, 6, 2)) FROM car_orders WHERE waybill_number LIKE "' . str_pad($session, 3, '0', STR_PAD_LEFT) . '-M__"');
    $row = mysqli_fetch_row($rs);
    $order_counter = (int) ($row[0] ?? 0);

    $min_amount = (float) $ship['min_amount'];
    $max_amount = (float) $ship['max_amount'];
    $num_cars = max(1, (int) round(mt_rand((int) ($min_amount * 100), (int) ($max_amount * 100)) / 100));

    $generated = 0;
    for ($j = 0; $j < $num_cars; $j++) {
        $order_counter++;
        $wb = str_pad($session, 3, '0', STR_PAD_LEFT) . '-M' . str_pad($order_counter, 2, '0', STR_PAD_LEFT);
        if (!mysqli_query($dbc, 'INSERT INTO car_orders (waybill_number, shipment, car) VALUES ("' . mysqli_real_escape_string($dbc, $wb) . '", "' . $shipment_id . '", "0")')) {
            break;
        }
        $generated++;
    }
    return ['generated' => $generated, 'shipment' => $shipment_code, 'session' => $session];
}

function session_resolve_jobs_param($jobs_param, $dbc = null)
{
    $jobs_param = trim((string) $jobs_param);
    if ($jobs_param === '' || strtolower($jobs_param) === 'all') {
        if ($dbc === null) {
            return [];
        }
        return session_list_switchlist_job_names($dbc);
    }
    return array_values(array_filter(array_map('trim', explode(',', $jobs_param))));
}

function session_list_switchlist_job_names($dbc)
{
    $staging = session_staging_job_names($dbc, session_merge_runtime_config([]));
    $staging_set = array_flip($staging);
    $jobs = [];
    $rs = mysqli_query($dbc, 'SELECT name FROM jobs ORDER BY name');
    while ($rs && ($row = mysqli_fetch_array($rs))) {
        $name = (string) ($row['name'] ?? '');
        if ($name === '' || isset($staging_set[$name])) {
            continue;
        }
        $jobs[] = $name;
    }
    return $jobs;
}

function session_phase_output_dir($session_nbr, $phase_num, $root = null)
{
    return session_dir_for($session_nbr, $root) . '/phase_' . str_pad((int) $phase_num, 2, '0', STR_PAD_LEFT);
}

function session_register_phase(array &$manifest, $phase_num, array $meta)
{
    $manifest['phases'] = $manifest['phases'] ?? [];
    $manifest['phases'][] = array_merge(['phase' => (int) $phase_num], $meta);
    foreach ($meta['jobs'] ?? [] as $job) {
        if (!isset($manifest['jobs'][$job])) {
            $manifest['jobs'][$job] = ['phases' => []];
        }
        if (!in_array((int) $phase_num, $manifest['jobs'][$job]['phases'], true)) {
            $manifest['jobs'][$job]['phases'][] = (int) $phase_num;
        }
    }
}

function session_waybill_dir_for($session_nbr, $phase_num = null, $root = null)
{
    $root = $root ?? session_web_root();
    if ($phase_num === null) {
        return session_dir_for($session_nbr, $root) . '/waybills';
    }
    return session_phase_output_dir($session_nbr, $phase_num, $root) . '/waybills';
}

/** Recursively remove a file or directory. */
function session_rrmdir($path)
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        session_rrmdir($path . '/' . $entry);
    }
    @rmdir($path);
}

/**
 * Purge previously generated output for a session so a fresh generation does not
 * leave stale phase directories, waybills, caches, or index files behind. The
 * session directory itself and its index.php page are preserved.
 *
 * @return list<string> Names of the entries that were removed.
 */
function session_reset_output($session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $dir = session_dir_for($session_nbr, $root);
    if (!is_dir($dir)) {
        return [];
    }

    $removed = [];
    foreach (glob($dir . '/phase_*', GLOB_ONLYDIR) ?: [] as $phase_dir) {
        session_rrmdir($phase_dir);
        $removed[] = basename($phase_dir);
    }
    if (is_dir($dir . '/waybills')) {
        session_rrmdir($dir . '/waybills');
        $removed[] = 'waybills';
    }
    foreach (glob($dir . '/*_master.json') ?: [] as $cache) {
        @unlink($cache);
        $removed[] = basename($cache);
    }
    foreach (['index.html', 'print_all.html'] as $legacy) {
        if (is_file($dir . '/' . $legacy)) {
            @unlink($dir . '/' . $legacy);
            $removed[] = $legacy;
        }
    }

    return $removed;
}

/**
 * Slot key identifying a logical switch-list phase independent of the volatile
 * recipe step number: the trains it covers plus the operator-facing title/info.
 * Two generations of e.g. the "CK1 Inbound" phase share this key even when the
 * recipe step index shifted between runs, so they collapse to one slot.
 */
function session_phase_slot_key(array $phase)
{
    $jobs = array_values(array_filter(array_map('trim', (array) ($phase['jobs'] ?? []))));
    sort($jobs, SORT_NATURAL | SORT_FLAG_CASE);

    return implode(',', $jobs)
        . '|' . trim((string) ($phase['title'] ?? ''))
        . '|' . trim((string) ($phase['info'] ?? ''));
}

/**
 * Compact a session's generated output to exactly one phase per logical slot
 * (see session_phase_slot_key), keeping the most recent generation of each.
 *
 * This is the root-cause fix for accumulation: when the same session is
 * regenerated repeatedly (e.g. a simulator replaying the recipe), every run used
 * to append fresh phases, inflating the manifest and leaving stale phase_NN
 * directories and cached print-all bundles behind. Compacting after each run (and
 * as a one-time cleanup) keeps the manifest, the on-disk phase directories, and
 * every derived count in lockstep with a single clean generation.
 *
 * Mutates $manifest in place (phases + jobs). Does NOT save it — callers persist.
 *
 * @return array{removed_phases:int, removed_dirs:list<string>}
 */
function session_compact_session_output(array &$manifest, $session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $phases = is_array($manifest['phases'] ?? null) ? $manifest['phases'] : [];

    // Keep the LAST occurrence of each slot (most recently generated wins).
    // Preserve manifest array order (not phase_NN folder numbers) so a
    // reconstructed earlier leg (e.g. NVL Outbound as phase_06 listed before
    // Return as phase_04) stays in authorial / operational sequence.
    $latest = [];
    $order = [];
    $i = 0;
    foreach ($phases as $phase) {
        if (!is_array($phase) || (int) ($phase['phase'] ?? 0) < 1) {
            continue;
        }
        $key = session_phase_slot_key($phase);
        $latest[$key] = $phase;
        $order[$key] = $i;
        $i++;
    }
    $survivors = array_values($latest);
    usort($survivors, static function ($a, $b) use ($order) {
        return ($order[session_phase_slot_key($a)] ?? 0)
            <=> ($order[session_phase_slot_key($b)] ?? 0);
    });

    $removed_phases = count($phases) - count($survivors);

    // Rebuild phases + jobs map from survivors.
    $manifest['phases'] = $survivors;
    $keep_nums = [];
    $jobs = [];
    foreach ($survivors as $phase) {
        $pn = (int) $phase['phase'];
        $keep_nums[$pn] = true;
        foreach ((array) ($phase['jobs'] ?? []) as $job) {
            $job = trim((string) $job);
            if ($job === '') {
                continue;
            }
            if (!isset($jobs[$job])) {
                $jobs[$job] = ['phases' => []];
            }
            if (!in_array($pn, $jobs[$job]['phases'], true)) {
                $jobs[$job]['phases'][] = $pn;
            }
        }
    }
    $manifest['jobs'] = $jobs;

    // Delete phase_NN directories no longer referenced by the surviving manifest.
    $dir = session_dir_for($session_nbr, $root);
    $removed_dirs = [];
    foreach (glob($dir . '/phase_*', GLOB_ONLYDIR) ?: [] as $phase_dir) {
        if (preg_match('/phase_(\d+)$/', $phase_dir, $m) && !isset($keep_nums[(int) $m[1]])) {
            session_rrmdir($phase_dir);
            $removed_dirs[] = basename($phase_dir);
        }
    }

    // Drop cached print-all bundles so they rebuild from the compacted manifest.
    foreach (array_merge(
        glob($dir . '/print_all*.html') ?: [],
        glob($dir . '/train_*.print_all*.html') ?: []
    ) as $bundle) {
        @unlink($bundle);
    }

    return ['removed_phases' => $removed_phases, 'removed_dirs' => $removed_dirs];
}

/**
 * Purge persisted output for every session (manifests, run-stats + history,
 * phase output, waybills, caches). Used when the database is reset via
 * restore_database so the cumulative session statistics start clean for the new
 * campaign instead of rolling up run_stats left over from a previous one.
 *
 * @return list<string> Names of the session directories that were removed.
 */
function session_reset_all_output($root = null)
{
    $root = $root ?? session_web_root();
    if (!is_dir($root)) {
        return [];
    }

    $removed = [];
    foreach (glob(rtrim($root, '/') . '/session_*', GLOB_ONLYDIR) ?: [] as $dir) {
        session_rrmdir($dir);
        $removed[] = basename($dir);
    }

    return $removed;
}

function session_write_waybill_bundle($dbc, $out_dir, array $waybill_numbers, array $options = [])
{
    session_ensure_writable_dir($out_dir);
    $settings = waybill_print_settings($dbc);
    $written = [];
    $list_items = '';
    $bundle_sheets = '';

    // Remove stale per-waybill files from prior runs so waybills that no longer
    // qualify (e.g. orders that became unfilled) don't linger on disk.
    $keep = ['index.html' => true, 'print_all.html' => true];
    foreach ($waybill_numbers as $wb_keep) {
        $keep[waybill_print_safe_filename($wb_keep) . '.html'] = true;
    }
    foreach (glob(rtrim($out_dir, '/') . '/*.html') ?: [] as $existing) {
        if (!isset($keep[basename($existing)])) {
            @unlink($existing);
        }
    }

    foreach ($waybill_numbers as $waybill_number) {
        $safe = waybill_print_safe_filename($waybill_number);
        $file = $safe . '.html';
        $page = waybill_print_render_page($dbc, $waybill_number, [
            'settings' => $settings,
            'show_controls' => true,
            'nav_html' => '<a href="index.html">Waybill list</a>',
        ]);
        if ($page === '') {
            continue;
        }
        file_put_contents($out_dir . '/' . $file, $page);
        $written[] = $waybill_number;
        $body = waybill_print_render_body($dbc, $waybill_number, $settings);
        $bundle_sheets .= waybill_print_wrap_sheets($body);
        $list_items .= '<li><a href="' . htmlspecialchars($file) . '">' . htmlspecialchars($waybill_number) . '</a></li>';
    }

    $title = $options['title'] ?? 'Waybills';
    $back = $options['back_href'] ?? '../session.php';
    $back_label = $options['back_label'] ?? 'All Sessions';
    $session_nbr = isset($options['session_nbr']) ? (int) $options['session_nbr'] : 0;
    $phase_num = array_key_exists('phase_num', $options) ? $options['phase_num'] : null;
    $index_html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . session_static_head_assets()
        . '</head><body>';
    $index_html .= session_nav_bar_html(
        session_waybill_index_nav_items($session_nbr, $back, $back_label),
        $title
    );
    $nav_row = $session_nbr >= 1
        ? session_waybill_session_nav_html($session_nbr, $phase_num, $dbc, $options['root'] ?? null)
        : '';
    $index_html .= '<main><h1>' . htmlspecialchars($title) . '</h1>'
        . $nav_row
        . '<div class="card"><p>' . count($written) . ' printable waybill(s) generated from the current database.</p>'
        . ($written ? '<p><a href="print_all.html"><strong>Print all waybills</strong></a></p>' : '')
        . '<ul>' . ($list_items !== '' ? $list_items : '<li>No waybills available.</li>') . '</ul></div>'
        . '</main></body></html>';
    file_put_contents($out_dir . '/index.html', $index_html);

    $print_all = waybill_print_render_bundle_page(
        $title . ' — print all',
        $bundle_sheets,
        ['back_href' => 'index.html']
    );
    file_put_contents($out_dir . '/print_all.html', $print_all);

    return [
        'path' => $out_dir . '/index.html',
        'print_all' => $out_dir . '/print_all.html',
        'count' => count($written),
        'waybills' => $written,
    ];
}

/**
 * Resolve the waybill number for a switchlist car row by matching the exact
 * car order (car + consignment/shipment), falling back to the car's most recent
 * waybill. Waybills are keyed to the car on the switch list, so this may return
 * a waybill issued in an earlier session (e.g. an order carried forward).
 */
function session_waybill_number_for_car_row($dbc, array $row)
{
    require_once __DIR__ . '/master_switchlist_helpers.php';
    // Prefer the waybill number frozen into the switchlist snapshot at
    // generation time. The section cache captures the car's waybill while the
    // DB still reflects that phase's state, so later reads (after the live DB
    // has advanced or rewound) stay pinned to the correct historical waybill.
    $frozen = trim((string) ($row['waybill_number'] ?? ''));
    if ($frozen !== '') {
        return $frozen;
    }
    $marks = trim((string) ($row['reporting_marks'] ?? ''));
    if ($marks === '') {
        return '';
    }
    $car_id = master_sw_car_id_by_marks($dbc, $marks);
    if ($car_id <= 0) {
        return '';
    }
    $consignment = (int) ($row['consignment_id'] ?? 0);
    if ($consignment > 0) {
        $rs = mysqli_query(
            $dbc,
            'SELECT co.waybill_number
             FROM car_orders co
             JOIN shipments s ON s.id = co.shipment
             WHERE co.car = ' . $car_id . '
               AND s.consignment = ' . $consignment . '
               AND co.waybill_number IS NOT NULL AND co.waybill_number != ""
             ORDER BY co.waybill_number DESC
             LIMIT 1'
        );
        if ($rs && mysqli_num_rows($rs) > 0) {
            return (string) mysqli_fetch_row($rs)[0];
        }
    }
    $rs = mysqli_query(
        $dbc,
        'SELECT waybill_number FROM car_orders
         WHERE car = ' . $car_id . '
           AND waybill_number IS NOT NULL AND waybill_number != ""
         ORDER BY waybill_number DESC
         LIMIT 1'
    );
    if ($rs && mysqli_num_rows($rs) > 0) {
        return (string) mysqli_fetch_row($rs)[0];
    }

    return '';
}

/** Ordered distinct waybill numbers for the cars in a job's switchlist sections. */
function session_waybill_numbers_for_sections($dbc, array $sections)
{
    $numbers = [];
    foreach ($sections as $section) {
        foreach ($section['cars'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $wb = session_waybill_number_for_car_row($dbc, $row);
            if ($wb !== '' && !in_array($wb, $numbers, true)) {
                $numbers[] = $wb;
            }
        }
    }

    return $numbers;
}

function session_waybill_store_path($session_nbr, $root = null)
{
    return session_waybill_dir_for($session_nbr, null, $root) . '/waybills.json';
}

function session_waybill_store_load($session_nbr, $root = null)
{
    $path = session_waybill_store_path($session_nbr, $root);
    if (is_readable($path)) {
        $data = json_decode((string) file_get_contents($path), true);
        if (is_array($data)) {
            $data['bodies'] = $data['bodies'] ?? [];
            $data['order'] = $data['order'] ?? [];
            $data['groups'] = $data['groups'] ?? [];
            return $data;
        }
    }
    return ['session' => (int) $session_nbr, 'bodies' => [], 'order' => [], 'groups' => []];
}

function session_waybill_store_save($session_nbr, array $store, $root = null)
{
    $dir = session_waybill_dir_for($session_nbr, null, $root);
    session_ensure_writable_dir($dir);
    file_put_contents(session_waybill_store_path($session_nbr, $root), json_encode($store));
}

/** Subset of the store's global order filtered to a set of waybill numbers. */
function session_waybill_order_subset(array $store, array $subset)
{
    $set = array_flip($subset);
    $out = [];
    foreach ($store['order'] as $num) {
        if (isset($set[$num])) {
            $out[] = $num;
        }
    }
    return $out;
}

function session_waybill_render_single_page($session_nbr, $waybill_number, $body)
{
    $title = 'Waybill ' . $waybill_number;
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title><style>'
        . waybill_print_page_styles() . '</style></head><body>'
        . '<div class="noprint"><button type="button" onclick="window.print()">Print</button>'
        . ' &nbsp; <a href="index.html">Waybill list</a><br /><br /></div>'
        . waybill_print_wrap_sheets($body) . '</body></html>';
}

function session_waybill_render_index_page($session_nbr, $title, array $numbers, array $store, array $options = [])
{
    $back = $options['back_href'] ?? '../index.php';
    $back_label = $options['back_label'] ?? ('Session ' . (int) $session_nbr);
    $phase_num = $options['phase_num'] ?? null;
    $print_all_file = $options['print_all_file'] ?? 'print_all.html';
    $dbc = $options['dbc'] ?? null;

    $list_items = '';
    foreach ($numbers as $num) {
        $file = waybill_print_safe_filename($num) . '.html';
        $list_items .= '<li><a href="' . htmlspecialchars($file) . '">' . htmlspecialchars($num) . '</a></li>';
    }

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . session_static_head_assets()
        . '</head><body>';
    $html .= session_nav_bar_html(
        session_waybill_index_nav_items($session_nbr, $back, $back_label),
        $title
    );
    $nav_row = ($phase_num === null && $session_nbr >= 1)
        ? session_waybill_session_nav_html($session_nbr, null, $dbc, $options['root'] ?? null)
        : '';
    $html .= '<main><h1>' . htmlspecialchars($title) . '</h1>' . $nav_row
        . '<div class="card"><p>' . count($numbers) . ' waybill(s).</p>'
        . ($numbers ? '<p><a href="' . htmlspecialchars($print_all_file) . '"><strong>Print all waybills</strong></a></p>' : '')
        . '<ul>' . ($list_items !== '' ? $list_items : '<li>No waybills available.</li>') . '</ul></div>'
        . '</main></body></html>';

    return $html;
}

function session_waybill_render_print_all_page($session_nbr, $title, array $numbers, array $store, array $options = [])
{
    $session_nbr = (int) $session_nbr;
    $count = 0;
    $sheets = '';
    foreach ($numbers as $num) {
        $body = $store['bodies'][$num] ?? '';
        if ($body !== '') {
            $sheets .= waybill_print_wrap_sheets($body);
            $count++;
        }
    }

    $back_href = (string) ($options['back_href'] ?? '../index.php');
    $back_label = (string) ($options['back_label'] ?? ('Session ' . $session_nbr));
    $index_file = (string) ($options['index_file'] ?? 'index.html');
    $basename = (string) ($options['print_all_basename'] ?? 'print_all.html');
    $phase_num = array_key_exists('phase_num', $options) ? $options['phase_num'] : null;
    $train_job = trim((string) ($options['train_job'] ?? ''));

    // Minimal top nav: STS Main Menu, back to the session overview, and a single
    // "Switch lists" link whose scope follows the current train selection (a
    // specific train -> that train's switch-list print-all; all -> the session
    // switch lists). The Train dropdown (added below) handles hopping between
    // trains / all, so no per-scope switch-list/waybill buttons are needed.
    $nav_items = session_waybill_index_nav_items($session_nbr, $back_href, $back_label);
    if ($train_job !== '') {
        $train_sw_primary = session_train_print_all_primary_job($session_nbr, $train_job, $options['root'] ?? null);
        $switchlist_href = '../train_' . rawurlencode($train_sw_primary) . '.print_all.html';
    } else {
        $switchlist_href = '../print_all.html';
    }
    $nav_items[] = [
        'href' => $switchlist_href,
        'label' => 'Switch lists',
        'icon' => 'list-task',
    ];
    $nav_html = session_nav_bar_html($nav_items, '');

    // Train dropdown: narrow to one train's waybill bundle or back to the
    // session-wide "All waybills" bundle. Mirrors the switch-list print-all
    // Train control so operators can hop between scopes without going back to
    // an index. Values are the raw job keys the waybill store is grouped by; the
    // page navigates through so.php (building the target bundle on demand).
    $wb_train_jobs = array_values(array_filter(array_map('strval', (array) ($options['train_jobs'] ?? []))));
    sort($wb_train_jobs, SORT_NATURAL | SORT_FLAG_CASE);
    if ($wb_train_jobs) {
        $wb_train_options = '<option value="">All waybills</option>';
        foreach ($wb_train_jobs as $wb_job) {
            $wb_sel = ($train_job !== '' && $wb_job === $train_job) ? ' selected' : '';
            $wb_train_options .= '<option value="' . htmlspecialchars($wb_job, ENT_QUOTES) . '"' . $wb_sel . '>'
                . htmlspecialchars($wb_job) . '</option>';
        }
        $wb_cluster = '<div class="d-flex align-items-center gap-2 ms-auto">'
            . '<label for="wb-train-select" class="text-white-50 small mb-0">Train</label>'
            . '<select id="wb-train-select" class="form-select form-select-sm" style="width:auto;">'
            . $wb_train_options . '</select></div>';
        $nav_html = str_replace('</div></div></nav>', $wb_cluster . '</div></div></nav>', $nav_html);
        $wb_script = '<script>(function(){'
            . 'var el=document.getElementById("wb-train-select");if(!el)return;'
            . 'var sid=' . $session_nbr . ';'
            . 'el.addEventListener("change",function(){var job=el.value;'
            . 'var f=job===""?("session_"+sid+"/waybills/print_all.html"):("session_"+sid+"/waybills/job_"+encodeURIComponent(job)+".print_all.html");'
            . 'window.location.href="/sts/so.php?f="+encodeURIComponent(f).replace(/%2F/g,"/");});'
            . '})();</script>';
    } else {
        $wb_script = '';
    }

    // Prev/next session nav (same scope), refreshed at serve time by so.php.
    $session_nav = session_waybill_print_all_session_nav_html(
        $session_nbr,
        $basename,
        $options['dbc'] ?? null,
        $options['root'] ?? null
    );

    $controls = '<div class="noprint waybill-print-controls">'
        . '<button type="button" class="btn btn-dark" onclick="window.print()"><i class="bi bi-printer"></i> Print all waybills</button>'
        . ' <a class="btn btn-outline-dark" href="' . htmlspecialchars($index_file) . '"><i class="bi bi-list-ul"></i> Waybill list</a>'
        . '</div>';

    // Waybill sheet formatting is scoped under .waybill-print so it doesn't
    // override the shared nav-bar chrome from session_static_head_assets().
    $scoped_styles = '.waybill-print .waybill-sheet{margin-bottom:32px}'
        . '.waybill-print .waybill-break-before{margin-top:32px}'
        . '.waybill-print table{border-collapse:collapse;font:normal 18px Verdana,Arial,sans-serif}'
        . '.waybill-print tr{vertical-align:top}'
        . '.waybill-print th,.waybill-print td{border:1px solid #000;padding:10px}'
        . '.waybill-print-controls{margin:0 0 16px}'
        . '@media print{nav,.noprint{display:none!important}'
        . '.waybill-print .waybill-sheet{page-break-after:always;break-after:page}'
        . '.waybill-print .waybill-sheet:last-child{page-break-after:auto;break-after:auto}'
        . '.waybill-print .waybill-break-before{page-break-before:always;break-before:page;margin-top:0}}';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . ' — print all</title>'
        . session_static_head_assets()
        . '<style>' . $scoped_styles . '</style>'
        . '</head><body>'
        . $nav_html
        . '<main><div class="noprint"><h1>' . htmlspecialchars($title) . '</h1>'
        . $session_nav
        . '<p class="muted">' . $count . ' waybill' . ($count === 1 ? '' : 's') . ' · each prints on its own page.</p></div>'
        . $controls
        . '<div class="waybill-print">' . ($sheets !== '' ? $sheets : '<div class="card"><p>No waybills to print.</p></div>') . '</div>'
        . '</main>'
        . $wb_script
        . '</body></html>';
}

/**
 * Rebuild every waybill page (session, per-job, per-phase-train) plus the
 * individual snapshot pages, from the frozen store. Pure aggregation — no new
 * rendering — so previously captured snapshots stay unchanged.
 */
function session_waybill_rebuild_pages($dbc, $session_nbr, array $store, $root = null)
{
    require_once __DIR__ . '/waybill_print_helpers.php';
    $root = $root ?? session_web_root();
    $dir = session_waybill_dir_for($session_nbr, null, $root);
    session_ensure_writable_dir($dir);

    // Distinct train (job) keys with waybills — populates the Train dropdown on
    // every waybills print-all page so operators can hop between scopes.
    $all_train_jobs = [];
    foreach ($store['groups'] as $group_key => $group_nums) {
        [$group_job] = array_pad(explode('|', (string) $group_key, 2), 2, '');
        $group_job = (string) $group_job;
        if ($group_job !== '') {
            $all_train_jobs[$group_job] = true;
        }
    }
    $all_train_jobs = array_keys($all_train_jobs);

    // Individual snapshot pages + cleanup of stale ones.
    $keep = ['index.html' => true, 'print_all.html' => true];
    foreach ($store['order'] as $num) {
        $safe = waybill_print_safe_filename($num);
        $keep[$safe . '.html'] = true;
        file_put_contents(
            $dir . '/' . $safe . '.html',
            session_waybill_render_single_page($session_nbr, $num, $store['bodies'][$num] ?? '')
        );
    }

    // Session scope.
    file_put_contents($dir . '/index.html', session_waybill_render_index_page(
        $session_nbr,
        'Waybills — session ' . (int) $session_nbr,
        $store['order'],
        $store,
        ['back_href' => '../index.php', 'back_label' => 'Session ' . (int) $session_nbr, 'dbc' => $dbc, 'root' => $root]
    ));
    file_put_contents($dir . '/print_all.html', session_waybill_render_print_all_page(
        $session_nbr,
        'Waybills — session ' . (int) $session_nbr,
        $store['order'],
        $store,
        [
            'back_href' => '../index.php',
            'back_label' => 'Session ' . (int) $session_nbr,
            'index_file' => 'index.html',
            'print_all_basename' => 'print_all.html',
            'phase_num' => null,
            'train_jobs' => $all_train_jobs,
            'dbc' => $dbc,
            'root' => $root,
        ]
    ));
    $keep['waybills.json'] = true;

    // Per-job and per-phase-train scopes.
    $by_job = [];
    foreach ($store['groups'] as $key => $nums) {
        // key = "JOB|PHASE"
        [$job, $phase] = array_pad(explode('|', $key, 2), 2, '');
        $job = (string) $job;
        $phase = (int) $phase;
        $by_job[$job] = array_values(array_unique(array_merge($by_job[$job] ?? [], $nums)));

        $phase_numbers = session_waybill_order_subset($store, $nums);
        $pfile = 'phase_' . session_phase_pad($phase) . '_' . $job;
        $keep[$pfile . '.index.html'] = true;
        $keep[$pfile . '.print_all.html'] = true;
        $title = $job . ' waybills — session ' . (int) $session_nbr . ', phase ' . $phase;
        file_put_contents($dir . '/' . $pfile . '.index.html', session_waybill_render_index_page(
            $session_nbr,
            $title,
            $phase_numbers,
            $store,
            ['back_href' => '../index.php', 'back_label' => 'Session ' . (int) $session_nbr, 'phase_num' => $phase, 'print_all_file' => $pfile . '.print_all.html', 'dbc' => $dbc, 'root' => $root]
        ));
        file_put_contents($dir . '/' . $pfile . '.print_all.html', session_waybill_render_print_all_page(
            $session_nbr,
            $title,
            $phase_numbers,
            $store,
            [
                'back_href' => '../index.php',
                'back_label' => 'Session ' . (int) $session_nbr,
                'index_file' => $pfile . '.index.html',
                'print_all_basename' => $pfile . '.print_all.html',
                'phase_num' => $phase,
                'train_job' => $job,
                'train_jobs' => $all_train_jobs,
                'dbc' => $dbc,
                'root' => $root,
            ]
        ));
    }
    foreach ($by_job as $job => $nums) {
        $job_numbers = session_waybill_order_subset($store, $nums);
        $jfile = 'job_' . $job;
        $keep[$jfile . '.index.html'] = true;
        $keep[$jfile . '.print_all.html'] = true;
        $title = $job . ' waybills — session ' . (int) $session_nbr;
        file_put_contents($dir . '/' . $jfile . '.index.html', session_waybill_render_index_page(
            $session_nbr,
            $title,
            $job_numbers,
            $store,
            ['back_href' => '../index.php', 'back_label' => 'Session ' . (int) $session_nbr, 'phase_num' => 0, 'print_all_file' => $jfile . '.print_all.html', 'dbc' => $dbc, 'root' => $root]
        ));
        file_put_contents($dir . '/' . $jfile . '.print_all.html', session_waybill_render_print_all_page(
            $session_nbr,
            $title,
            $job_numbers,
            $store,
            [
                'back_href' => '../index.php',
                'back_label' => 'Session ' . (int) $session_nbr,
                'index_file' => $jfile . '.index.html',
                'print_all_basename' => $jfile . '.print_all.html',
                'train_job' => $job,
                'train_jobs' => $all_train_jobs,
                'phase_num' => 0,
                'dbc' => $dbc,
                'root' => $root,
            ]
        ));
    }

    // Remove stale files from earlier schemes/runs.
    foreach (glob($dir . '/*.html') ?: [] as $existing) {
        if (!isset($keep[basename($existing)])) {
            @unlink($existing);
        }
    }
}

function session_refresh_session_waybills($dbc, $session_nbr, $root = null)
{
    require_once __DIR__ . '/waybill_print_helpers.php';
    $root = $root ?? session_web_root();
    $store = session_waybill_store_load($session_nbr, $root);
    session_waybill_rebuild_pages($dbc, $session_nbr, $store, $root);
    return [
        'path' => session_waybill_dir_for($session_nbr, null, $root) . '/index.html',
        'print_all' => session_waybill_dir_for($session_nbr, null, $root) . '/print_all.html',
        'count' => count($store['order']),
        'waybills' => $store['order'],
    ];
}

/**
 * Capture the waybills for the switch lists generated in a workflow phase.
 * Waybills are derived from the cars on each train's switch list (not the
 * session-number prefix), rendered once and frozen into the session store so
 * each phase keeps its own snapshot. Session/job/phase pages are rebuilt from
 * the store.
 */
function session_generate_waybills_for_phase($dbc, $session_nbr, $phase_num, $root = null)
{
    require_once __DIR__ . '/waybill_print_helpers.php';
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $root = $root ?? session_web_root();
    $phase_num = (int) $phase_num;

    $manifest = session_load_manifest($session_nbr, $root);
    $phase_dir = session_phase_output_dir($session_nbr, $phase_num, $root);

    // Which trains ran in this workflow phase.
    $jobs = [];
    foreach ($manifest['phases'] ?? [] as $phase) {
        if ((int) ($phase['phase'] ?? 0) === $phase_num) {
            $jobs = array_values(array_filter(array_map('trim', $phase['jobs'] ?? [])));
            break;
        }
    }
    if (!$jobs) {
        foreach (glob($phase_dir . '/*_master.json') ?: [] as $cache) {
            if (preg_match('#/([^/]+)_session_\d+_master\.json$#', $cache, $m)) {
                $jobs[] = $m[1];
            }
        }
        $jobs = array_values(array_unique($jobs));
    }

    $store = session_waybill_store_load($session_nbr, $root);
    $settings = waybill_print_settings($dbc);
    $phase_total_numbers = 0;

    foreach ($jobs as $job) {
        $sections = master_sw_load_sections_cache($phase_dir, $job, $session_nbr);
        if (!is_array($sections) || count($sections) === 0) {
            continue;
        }
        $numbers = session_waybill_numbers_for_sections($dbc, $sections);
        // Snapshot bodies once (freeze on first capture).
        foreach ($numbers as $num) {
            if (!isset($store['bodies'][$num])) {
                $body = waybill_print_render_body($dbc, $num, $settings);
                if (trim((string) $body) === '') {
                    continue;
                }
                $store['bodies'][$num] = $body;
            }
            if (!in_array($num, $store['order'], true)) {
                $store['order'][] = $num;
            }
        }
        // Only keep numbers that actually have a snapshot body.
        $numbers = array_values(array_filter($numbers, function ($n) use ($store) {
            return isset($store['bodies'][$n]);
        }));
        $store['groups'][$job . '|' . $phase_num] = $numbers;
        $phase_total_numbers += count($numbers);
    }

    session_waybill_store_save($session_nbr, $store, $root);
    session_waybill_rebuild_pages($dbc, $session_nbr, $store, $root);

    return [
        'path' => session_waybill_dir_for($session_nbr, null, $root) . '/index.html',
        'print_all' => session_waybill_dir_for($session_nbr, null, $root) . '/print_all.html',
        'count' => $phase_total_numbers,
        'session_count' => count($store['order']),
        'session_print_all' => session_waybill_dir_for($session_nbr, null, $root) . '/print_all.html',
        'waybills' => $store['order'],
    ];
}

/**
 * Normalized "generate waybills" entry point shared by the recipe runner and
 * the standalone dispatch handler so both behave identically.
 *
 * Captures waybills for every phase that produced switch lists (idempotent:
 * waybill bodies freeze on first capture, so re-running never re-renders or
 * corrupts an earlier phase's snapshot), then rebuilds all session/job/phase
 * pages from the accumulated store. This means that if orders are filled and
 * additional switch lists are generated later in the session, every phase's
 * waybills are rolled into one complete, self-consistent session bundle.
 */
function session_capture_and_refresh_waybills($dbc, $session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $manifest = session_load_manifest($session_nbr, $root);

    foreach ($manifest['phases'] ?? [] as $phase) {
        $pn = (int) ($phase['phase'] ?? 0);
        if ($pn < 1) {
            continue;
        }
        // Only phases with a switch-list cache capture anything; the rest are
        // no-ops. Bodies already frozen by generate_switchlists stay untouched.
        session_generate_waybills_for_phase($dbc, $session_nbr, $pn, $root);
    }

    // Rebuild pages from the now-complete store (also covers sessions whose
    // waybills were captured but whose pages need refreshing).
    $wb = session_refresh_session_waybills($dbc, $session_nbr, $root);
    $wb['session_count'] = $wb['count'] ?? 0;
    return $wb;
}

function session_run_recipe($dbc, array $recipe, array $options = [])
{
    require_once __DIR__ . '/operational_steps_catalog.php';
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $recipe = operational_steps_normalize_recipe($recipe);
    $config = session_merge_runtime_config($options['config'] ?? []);
    $format = $options['format'] ?? 'all';
    $root = $options['session_root'] ?? session_web_root();
    $from_step = max(1, (int) ($options['from_step'] ?? 1));
    $to_step = min(count($recipe['steps'] ?? []), (int) ($options['to_step'] ?? count($recipe['steps'] ?? [])));
    $skip_steps = [];
    if (!empty($options['skip_steps'])) {
        $raw_skip = $options['skip_steps'];
        if (is_array($raw_skip)) {
            $skip_steps = operational_steps_parse_step_ranges(implode(',', $raw_skip));
        } else {
            $skip_steps = operational_steps_parse_step_ranges((string) $raw_skip);
        }
    }
    $skip_set = [];
    foreach ($skip_steps as $sn) {
        $skip_set[(int) $sn] = true;
    }
    $session_nbr = function_exists('warm_start_get_session')
        ? warm_start_get_session($dbc)
        : session_get_db_session($dbc);
    $recipe_start_session = (int) $session_nbr;
    $manifest = session_load_manifest($session_nbr, $root);
    // Output is keyed to the operating session that owns the switch lists. A
    // step such as generate_orders/begin_session can advance the DB session
    // mid-run, so the switch lists (and their waybills) belong to the session
    // that is current when they are produced -- not the session at recipe
    // start. The output session number is therefore (re)synced to the live DB
    // right before any output step (see $sync_output_session), and a fresh run
    // (reset_output) purges that session's stale files exactly once.
    $reset_output = array_key_exists('reset_output', $options)
        ? (bool) $options['reset_output']
        : ($from_step <= 1);
    $reset_applied_for = null;
    $phase_num = count($manifest['phases'] ?? []);
    // Every session this run touches (a single run can advance through several
    // sessions via begin_session). All of them are compacted at the end so no
    // session is left with accumulated phases just because it wasn't the last.
    $touched_sessions = [(int) $session_nbr => true];

    $sync_output_session = function () use (
        $dbc, $root, $reset_output, &$session_nbr, &$manifest, &$phase_num, &$reset_applied_for, &$touched_sessions
    ) {
        $live = function_exists('warm_start_get_session')
            ? (int) warm_start_get_session($dbc)
            : (int) session_get_db_session($dbc);
        if ($live > 0 && $live !== (int) $session_nbr) {
            $session_nbr = $live;
            $manifest = session_load_manifest($session_nbr, $root);
            $phase_num = count($manifest['phases'] ?? []);
        }
        $touched_sessions[(int) $session_nbr] = true;
        if ($reset_output && $reset_applied_for !== (int) $session_nbr) {
            session_reset_output($session_nbr, $root);
            $manifest['phases'] = [];
            $manifest['jobs'] = [];
            unset($manifest['waybills']);
            $phase_num = 0;
            $reset_applied_for = (int) $session_nbr;
        }
    };
    $ctx = session_evaluate_context($dbc, $config);
    $log = [];
    $stopped = false;
    $loop_error = null;
    $step_span = max(1, $to_step - $from_step + 1);
    $max_iterations = max(500, $step_span * 100);
    $iterations = 0;
    $pc = $from_step - 1;

    while ($pc >= 0 && $pc < $to_step) {
        $iterations++;
        if ($iterations > $max_iterations) {
            $stopped = true;
            $loop_error = 'Possible infinite goto loop (exceeded ' . $max_iterations . ' step executions).';
            $log[] = ['step' => $pc + 1, 'action' => 'loop_guard', 'error' => $loop_error];
            break;
        }

        $step = $recipe['steps'][$pc] ?? null;
        if (!is_array($step)) {
            $pc++;
            continue;
        }
        $n = $pc + 1;
        $fid = $step['function'] ?? '';

        if (!empty($skip_set[$n])) {
            $log[] = ['step' => $n, 'action' => 'skipped_range'];
            $pc++;
            continue;
        }

        if (array_key_exists('enabled', $step) && !$step['enabled']) {
            $log[] = ['step' => $n, 'action' => 'skipped_disabled'];
            $pc++;
            continue;
        }

        if ($fid === 'stop') {
            $stopped = true;
            $log[] = ['step' => $n, 'action' => 'stop'];
            break;
        }
        if ($fid === 'skip_steps') {
            $extra = operational_steps_parse_step_ranges($step['params']['steps'] ?? '');
            foreach ($extra as $sn) {
                $skip_set[(int) $sn] = true;
            }
            $log[] = [
                'step' => $n,
                'action' => 'skip_steps',
                'steps' => operational_steps_format_step_ranges($extra),
            ];
            $pc++;
            continue;
        }
        if ($fid === 'goto') {
            $target = operational_steps_goto_resolve_step($recipe, $step['params'] ?? []);
            $total = count($recipe['steps']);
            if (operational_steps_goto_target_allowed($n, $target, $total)) {
                $pc = $target - 1;
                $log[] = ['step' => $n, 'action' => 'goto', 'target' => $target];
            } else {
                $reason = ($target > 0 && $target <= $n)
                    ? 'backward goto not allowed (use Repeat to loop a section)'
                    : 'invalid target';
                $log[] = ['step' => $n, 'action' => 'goto', 'target' => $target, 'error' => $reason];
                $pc++;
            }
            continue;
        }
        if ($fid === 'if_then') {
            $p = $step['params'] ?? [];
            $ok = session_evaluate_condition(
                $ctx,
                $p['variable'] ?? 'session_nbr',
                $p['operator'] ?? '=',
                $p['value'] ?? '0',
                $p
            );
            $log[] = ['step' => $n, 'action' => 'if_then', 'result' => $ok];
            if ($ok && operational_steps_if_then_has_goto($step)) {
                $target = operational_steps_goto_resolve_step($recipe, $p);
                $total = count($recipe['steps']);
                if (operational_steps_goto_target_allowed($n, $target, $total)) {
                    $pc = $target - 1;
                    $log[] = ['step' => $n, 'action' => 'goto', 'target' => $target, 'from' => 'if_then'];
                    continue;
                }
                $reason = ($target > 0 && $target <= $n)
                    ? 'backward goto not allowed (use Repeat to loop a section)'
                    : 'invalid target';
                $log[] = ['step' => $n, 'action' => 'goto', 'target' => $target, 'error' => $reason, 'from' => 'if_then'];
            }
            $pc++;
            continue;
        }
        if ($fid === 'section_label') {
            $log[] = ['step' => $n, 'action' => 'section_label', 'label' => $step['params']['label'] ?? ''];
            $pc++;
            continue;
        }

        if ($fid === 'generate_switchlists') {
            $sync_output_session();
            $phase_num++;
            $jobs = session_resolve_jobs_param($step['params']['jobs'] ?? 'all', $dbc);
            $phase_dir = session_phase_output_dir($session_nbr, $phase_num, $root);
            $fmt = master_sw_normalize_switchlist_format($step['params']['format'] ?? $format);
            $title = trim((string) ($step['params']['title'] ?? ''));
            // Per-leg "info" note (e.g. Inbound/Outbound). Must be passed to the
            // generator so it appears in each switch list header, and stored in the
            // manifest so the print-all assemblers can reproduce it.
            $info = trim((string) ($step['params']['info'] ?? ''));
            $written = master_sw_generate_for_jobs($dbc, $jobs, $phase_dir, $config, [
                'format' => $fmt,
                'recipe' => $recipe,
                'through_step' => $n - 1,
                'session_override' => $session_nbr,
                'title' => $title,
                'info' => $info,
            ]);
            // Don't register empty switch-list phases. Compact keys on
            // title|info, so an empty late "Starting" snapshot would wipe an
            // earlier same-slot list that still had cars (e.g. morning snap
            // after corrections, then empty overnight pickup).
            $cars_written = 0;
            foreach ((array) $written as $wj) {
                $cars_written += (int) ($wj['cars'] ?? 0);
            }
            if ($cars_written < 1) {
                if (is_dir($phase_dir)) {
                    session_rrmdir($phase_dir);
                }
                $phase_num--;
                $log[] = [
                    'step' => $n,
                    'phase' => null,
                    'written' => $written,
                    'waybills' => 0,
                    'skipped_empty' => true,
                ];
                $pc++;
                continue;
            }
            session_register_phase($manifest, $phase_num, [
                'step' => $n,
                'jobs' => $jobs,
                'format' => $fmt,
                'styles' => master_sw_styles_for_format($fmt),
                'label' => operational_steps_compile_recipe(['steps' => [$step]])[0]['instruction'] ?? 'Generate Switch Lists',
                'title' => $title,
                'info' => $info,
                'output' => $phase_dir,
            ]);
            // Snapshot each phase's waybills immediately while the DB still
            // reflects this phase's state. The switch list cache freezes each
            // car's waybill number, so the waybill bundle always matches the
            // switch lists regardless of where generate_waybills runs.
            session_save_manifest($session_nbr, $manifest, $root);
            $phase_wb = session_generate_waybills_for_phase($dbc, $session_nbr, $phase_num, $root);
            $log[] = ['step' => $n, 'phase' => $phase_num, 'written' => $written, 'waybills' => $phase_wb['count'] ?? 0];
            $pc++;
            continue;
        }
        if ($fid === 'generate_waybills') {
            $sync_output_session();
            if ($phase_num < 1) {
                $phase_num = 1;
            }
            // Capture every switch-list phase (idempotent) then rebuild pages,
            // so filling orders and generating extra switch lists mid-session
            // still yields one complete, self-consistent waybill bundle.
            $wb = session_capture_and_refresh_waybills($dbc, $session_nbr, $root);
            foreach ($manifest['phases'] as &$phase_entry) {
                if ((int) ($phase_entry['phase'] ?? 0) === (int) $phase_num) {
                    $phase_entry['waybills'] = [
                        'count' => session_waybill_phase_count($session_nbr, $phase_num, $root),
                        'index' => 'waybills/index.html',
                        'print_all' => 'waybills/print_all.html',
                    ];
                    break;
                }
            }
            unset($phase_entry);
            $manifest['waybills'] = [
                'count' => $wb['session_count'] ?? ($wb['count'] ?? 0),
                'index' => 'waybills/index.html',
                'print_all' => 'waybills/print_all.html',
                'updated' => date('c'),
            ];
            $log[] = ['step' => $n, 'phase' => $phase_num, 'waybills' => $wb];
            $pc++;
            continue;
        }

        $dispatch_opts = array_merge($config, [
            'session_root' => $root,
            'phase' => $phase_num,
            'recipe' => $recipe,
            'through_step' => $n - 1,
        ]);
        $result = operational_steps_dispatch_step($dbc, $step, $dispatch_opts);
        $log[] = array_merge(['step' => $n], $result);
        $ctx = session_evaluate_context($dbc, $config);
        $pc++;
    }

    // Reconcile the session waybill count from the final store. generate_waybills
    // often runs before the switch lists that populate the store (the store keeps
    // filling as each phase's switch lists are generated), so the count written at
    // that step is stale — typically 0. Recompute from the accumulated store so the
    // "Waybills" stat reflects the unique waybill numbers that moved through the
    // whole session (distinct waybill numbers, not the per-style render variants).
    $wb_store = session_waybill_store_load($session_nbr, $root);
    $wb_unique = count($wb_store['order'] ?? []);
    if ($wb_unique > 0 || isset($manifest['waybills'])) {
        $manifest['waybills'] = array_merge(
            is_array($manifest['waybills'] ?? null) ? $manifest['waybills'] : [],
            [
                'count' => $wb_unique,
                'index' => 'waybills/index.html',
                'print_all' => 'waybills/print_all.html',
                'updated' => date('c'),
            ]
        );
        if (is_array($manifest['phases'] ?? null)) {
            foreach ($manifest['phases'] as &$phase_entry) {
                $pn = (int) ($phase_entry['phase'] ?? 0);
                if ($pn < 1) {
                    continue;
                }
                $pc_count = session_waybill_phase_count($session_nbr, $pn, $root);
                if ($pc_count > 0) {
                    $phase_entry['waybills'] = [
                        'count' => $pc_count,
                        'index' => 'waybills/index.html',
                        'print_all' => 'waybills/print_all.html',
                    ];
                }
            }
            unset($phase_entry);
        }
    }

    // Collapse any accumulated duplicate phases (from repeated regeneration)
    // down to the latest per logical slot, and purge the orphaned phase
    // directories + stale print-all bundles. Done for every session this run
    // touched so none is left inflated, keeping each manifest and every derived
    // count consistent with a single clean generation.
    session_compact_session_output($manifest, $session_nbr, $root);
    session_save_manifest($session_nbr, $manifest, $root);
    foreach (array_keys($touched_sessions) as $ts) {
        if ((int) $ts === (int) $session_nbr || (int) $ts < 1) {
            continue;
        }
        $tm = session_load_manifest($ts, $root);
        if (!empty($tm['phases'])) {
            session_compact_session_output($tm, $ts, $root);
            session_save_manifest($ts, $tm, $root);
        }
    }
    // Persist run stats after the manifest write so phase/waybill data is on
    // disk first; persist reloads the manifest and adds run_stats. Doing this
    // after session_save_manifest avoids the final save wiping run_stats.
    session_persist_recipe_run_stats($dbc, $log, [
        'start_step' => $from_step,
        'stop_step' => $to_step,
    ], $recipe_start_session, $root);

    $final_session = function_exists('warm_start_get_session')
        ? (int) warm_start_get_session($dbc)
        : (int) session_get_db_session($dbc);
    return [
        'session' => (string) ($final_session > 0 ? $final_session : $session_nbr),
        'phases' => $phase_num,
        'stopped' => $stopped,
        'error' => $loop_error,
        'log' => $log,
        'manifest' => $manifest,
    ];
}

function session_discover_sessions($root = null)
{
    $root = $root ?? session_web_root();
    $sessions = [];
    if (!is_dir($root)) {
        return $sessions;
    }
    foreach (scandir($root) ?: [] as $entry) {
        if (preg_match('/^session_(\d+)$/', $entry, $m)) {
            $n = (int) $m[1];
            if ($n >= 1) {
                $sessions[] = $n;
            }
        }
    }
    sort($sessions);
    return $sessions;
}

function session_adjacent_session(array $sessions, $current, $direction)
{
    $current = (int) $current;
    $sessions = array_values(array_map('intval', $sessions));
    $idx = array_search($current, $sessions, true);
    if ($idx === false) {
        return null;
    }
    if ($direction === 'prev' && $idx > 0) {
        return $sessions[$idx - 1];
    }
    if ($direction === 'next' && $idx < count($sessions) - 1) {
        return $sessions[$idx + 1];
    }

    return null;
}

/**
 * First and last session numbers in the browser session list.
 *
 * @param list<int> $sessions
 * @return array{0: ?int, 1: ?int}
 */
function session_edge_sessions(array $sessions)
{
    if ($sessions === []) {
        return [null, null];
    }
    $ints = array_map('intval', $sessions);

    return [min($ints), max($ints)];
}

/**
 * RW (first) or FF (last) skip control for session picker navigation.
 *
 * @param 'rw'|'ff'|'first'|'last' $kind
 * @param list<int>              $sessions
 */
function session_picker_skip_link($kind, $selected, array $sessions, $href_base, $extra_query = '')
{
    list($first, $last) = session_edge_sessions($sessions);
    if ($kind === 'rw' || $kind === 'first') {
        $target = $first;
        $icon = 'skip-start-fill';
        $title = $target !== null ? 'First session (' . $target . ')' : 'First session';
    } else {
        $target = $last;
        $icon = 'skip-end-fill';
        $title = $target !== null ? 'Last session (' . $target . ')' : 'Last session';
    }
    $icon_html = '<i class="bi bi-' . $icon . '"></i>';

    if ($target === null || (int) $selected === (int) $target) {
        return '<span class="btn btn-outline-dark btn-sm session-skip-btn disabled" aria-disabled="true" title="'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">' . $icon_html . '</span>';
    }

    $href = $href_base . (int) $target;
    $extra_query = trim((string) $extra_query);
    if ($extra_query !== '') {
        $href .= (strpos($extra_query, '?') === 0 ? '' : (strpos($href, '?') !== false ? '&' : '?'))
            . ltrim($extra_query, '?&');
    }

    return '<a class="btn btn-outline-dark btn-sm session-skip-btn" href="'
        . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" title="'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">' . $icon_html . '</a>';
}

function session_phase_pad($phase_num)
{
    return str_pad((int) $phase_num, 2, '0', STR_PAD_LEFT);
}

/**
 * Per-leg "info" note (e.g. Inbound/Outbound) for a manifest phase entry. Prefers
 * the explicit 'info' field; for legacy manifests written before 'info' was
 * stored, recovers it from the compiled label suffix ("… — <title> · <info>").
 */
function session_phase_info(array $phase)
{
    $info = trim((string) ($phase['info'] ?? ''));
    if ($info !== '') {
        return $info;
    }
    $label = (string) ($phase['label'] ?? '');
    $sep = ' · ';
    $pos = mb_strrpos($label, $sep);
    if ($pos !== false) {
        return trim(mb_substr($label, $pos + mb_strlen($sep)));
    }

    return '';
}

function session_waybills_bundle_ready($session_nbr, $phase_num = null, $root = null)
{
    // Waybills now live in a single session-level store; "ready" means at least
    // one waybill has been captured for the session.
    $store = session_waybill_store_load($session_nbr, $root);
    return !empty($store['order']);
}

/** Waybill counts for a train (job) in a session, from the snapshot store. */
function session_waybill_job_count($session_nbr, $job, $root = null)
{
    $store = session_waybill_store_load($session_nbr, $root);
    $nums = [];
    foreach ($store['groups'] ?? [] as $key => $group) {
        [$gjob] = array_pad(explode('|', $key, 2), 2, '');
        if ((string) $gjob === (string) $job) {
            foreach ($group as $n) {
                $nums[$n] = true;
            }
        }
    }
    return count($nums);
}

/** Distinct waybill count captured for a given workflow phase. */
function session_waybill_phase_count($session_nbr, $phase_num, $root = null)
{
    $store = session_waybill_store_load($session_nbr, $root);
    $nums = [];
    foreach ($store['groups'] ?? [] as $key => $group) {
        [, $gphase] = array_pad(explode('|', $key, 2), 2, '');
        if ((int) $gphase === (int) $phase_num) {
            foreach ($group as $n) {
                $nums[$n] = true;
            }
        }
    }
    return count($nums);
}

function session_switchlist_styles()
{
    return [
        'mobile' => 'Mobile',
        'half' => 'Half Sheet',
        'full' => 'Full Sheet',
        'dmp' => 'Dot Matrix',
        'wo' => 'Work Order',
        'x2010' => 'X2010',
    ];
}

function session_normalize_switchlist_style($style, $default = 'mobile')
{
    if (!function_exists('operational_steps_normalize_switchlist_format')) {
        require_once __DIR__ . '/operational_steps_catalog.php';
    }
    $style = operational_steps_normalize_switchlist_format($style, $default);
    if ($style === 'all') {
        return $default;
    }

    return $style;
}

function session_switchlist_work_phase_rel($session_nbr, $phase_num, $job, $work_phase, $style = 'mobile')
{
    $style = session_normalize_switchlist_style($style);
    $job_slug = rawurlencode((string) $job);

    return 'session_' . (int) $session_nbr
        . '/phase_' . session_phase_pad($phase_num)
        . '/' . $job_slug
        . '/phase_' . session_phase_pad($work_phase)
        . '_' . $style . '.html';
}

function session_switchlist_work_phase_href($session_nbr, $phase_num, $job, $work_phase, $style = 'mobile')
{
    return session_output_url(session_switchlist_work_phase_rel($session_nbr, $phase_num, $job, $work_phase, $style));
}

function session_switchlist_job_href($session_nbr, $phase_num, $job, $style = 'mobile', $work_phase = 1, $root = null)
{
    $style = session_normalize_switchlist_style($style);
    $root = $root ?? session_web_root();
    if (session_switchlist_style_file_exists($session_nbr, $phase_num, $job, $style, $root, $work_phase)) {
        return session_switchlist_work_phase_href($session_nbr, $phase_num, $job, $work_phase, $style);
    }

    return session_output_url('session_' . (int) $session_nbr
        . '/phase_' . session_phase_pad($phase_num)
        . '/' . rawurlencode((string) $job)
        . '/index.html?style=' . rawurlencode($style));
}

function session_switchlist_style_file_exists($session_nbr, $phase_num, $job, $style, $root = null, $work_phase = 1)
{
    $root = $root ?? session_web_root();
    $path = session_output_fs_path(
        session_switchlist_work_phase_rel($session_nbr, $phase_num, $job, $work_phase, $style),
        $root
    );

    return is_file($path) && filesize($path) > 200;
}

function session_session_style_available($session_nbr, $style, array $manifest, $root = null)
{
    $root = $root ?? session_web_root();
    $style = session_normalize_switchlist_style($style);
    foreach ($manifest['phases'] ?? [] as $phase) {
        $phase_num = (int) ($phase['phase'] ?? 0);
        if ($phase_num < 1) {
            continue;
        }
        foreach ($phase['jobs'] ?? [] as $job) {
            $job = trim((string) $job);
            if ($job === '') {
                continue;
            }
            if (session_switchlist_style_file_exists($session_nbr, $phase_num, $job, $style, $root)) {
                return true;
            }
        }
    }

    return false;
}

function session_rerender_session_style($dbc, $session_nbr, $style, $root = null, $force = false)
{
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $root = $root ?? session_web_root();
    $style = session_normalize_switchlist_style($style);
    $manifest = session_load_manifest($session_nbr, $root);
    $config = session_merge_runtime_config([]);
    $results = [];

    foreach ($manifest['phases'] ?? [] as $phase) {
        $phase_num = (int) ($phase['phase'] ?? 0);
        if ($phase_num < 1) {
            continue;
        }
        $jobs = array_values(array_filter(array_map('trim', $phase['jobs'] ?? [])));
        if (!$jobs) {
            continue;
        }
        $phase_dir = session_phase_output_dir($session_nbr, $phase_num, $root);
        $all_exist = true;
        foreach ($jobs as $job) {
            if (!session_switchlist_style_file_exists($session_nbr, $phase_num, $job, $style, $root)) {
                $all_exist = false;
                break;
            }
        }
        // $force bypasses the "already generated" short-circuit so callers can
        // rebuild switch lists in place (e.g. to pick up a recovered Inbound/
        // Outbound note on sessions generated before it was persisted).
        if ($all_exist && !$force) {
            $results[] = ['phase' => $phase_num, 'skipped' => true, 'reason' => 'style already generated'];
            continue;
        }
        $written = master_sw_generate_for_jobs($dbc, $jobs, $phase_dir, $config, [
            'format' => $style,
            'render_only' => true,
            'session_override' => (string) $session_nbr,
            'title' => trim((string) ($phase['title'] ?? '')),
            'info' => session_phase_info($phase),
        ]);
        foreach ($manifest['phases'] as &$phase_entry) {
            if ((int) ($phase_entry['phase'] ?? 0) === $phase_num) {
                $existing = $phase_entry['styles'] ?? [];
                if (!is_array($existing)) {
                    $existing = [];
                }
                $phase_entry['styles'] = array_values(array_unique(array_merge(
                    $existing,
                    master_sw_styles_for_format($style)
                )));
                break;
            }
        }
        unset($phase_entry);
        $results[] = ['phase' => $phase_num, 'written' => $written];
    }

    $manifest['preferred_switchlist_style'] = $style;
    session_save_manifest($session_nbr, $manifest, $root);

    return $results;
}

function session_switchlist_phase_print_href($session_nbr, $phase_num)
{
    return session_output_url('session_' . (int) $session_nbr
        . '/phase_' . session_phase_pad($phase_num)
        . '/print_all.html');
}

function session_waybill_href_for_number($waybill_number, $session_nbr, $phase_num = null, $root = null)
{
    require_once __DIR__ . '/waybill_print_helpers.php';
    $root = $root ?? session_web_root();
    $file = waybill_print_safe_filename($waybill_number) . '.html';
    // All snapshot pages live in the session-level waybills directory.
    $session_path = session_waybill_dir_for($session_nbr, null, $root) . '/' . $file;
    if (is_file($session_path)) {
        return session_output_url('session_' . (int) $session_nbr . '/waybills/' . $file);
    }

    return null;
}

function session_waybill_for_marks($dbc, $marks)
{
    $marks = trim((string) $marks);
    if ($marks === '') {
        return '';
    }
    $esc = mysqli_real_escape_string($dbc, $marks);
    $rs = mysqli_query(
        $dbc,
        'SELECT car_orders.waybill_number
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         WHERE cars.reporting_marks = "' . $esc . '"
           AND car_orders.waybill_number IS NOT NULL
           AND car_orders.waybill_number != ""
         ORDER BY car_orders.waybill_number DESC
         LIMIT 1'
    );
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return '';
    }

    return trim((string) mysqli_fetch_array($rs)['waybill_number']);
}

function session_train_phase_waybills($dbc, $session_nbr, $job, $phase_num, $root = null)
{
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $root = $root ?? session_web_root();
    $phase_dir = session_phase_output_dir($session_nbr, $phase_num, $root);
    $sections = master_sw_load_sections_cache($phase_dir, $job, $session_nbr);
    if (!is_array($sections)) {
        return [];
    }

    $numbers = [];
    foreach ($sections as $section) {
        foreach ($section['cars'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $wb = session_waybill_for_marks($dbc, $row['reporting_marks'] ?? '');
            if ($wb !== '' && !in_array($wb, $numbers, true)) {
                $numbers[] = $wb;
            }
        }
    }
    sort($numbers, SORT_NATURAL | SORT_FLAG_CASE);

    return $numbers;
}

/**
 * Enumerate a train's switch-list "legs" for a session. Each work-leg (a
 * section captured during the dry-run) is one printable switch list. Returns an
 * ordered list across every workflow phase the train runs, with the waybills for
 * the cars on that leg.
 *
 * @return list<array{workflow_phase:int, work_leg:int, work_leg_total:int, label:string, base_href:string, waybills:list<array{number:string, href:?string}>}>
 */
function session_train_switchlist_legs($dbc, $session_nbr, $job, $root = null)
{
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $root = $root ?? session_web_root();
    $manifest = session_load_manifest($session_nbr, $root);
    $job_meta = $manifest['jobs'][$job] ?? ['phases' => []];
    // Only the latest generation token's phases for this train, so repeated
    // recipe runs that appended to the same session don't stack stale copies.
    $token_phases = session_job_latest_token_phase_nums($manifest, $job);
    $legs = [];

    foreach ($job_meta['phases'] ?? [] as $p) {
        $p = (int) $p;
        if ($p < 1) {
            continue;
        }
        if ($token_phases !== [] && !isset($token_phases[$p])) {
            continue;
        }
        $phase_dir = session_phase_output_dir($session_nbr, $p, $root);
        $sections = master_sw_load_sections_cache($phase_dir, $job, $session_nbr);
        if (!is_array($sections) || count($sections) === 0) {
            continue;
        }
        $work_leg_total = count($sections);
        $phase_info = '';
        foreach ($manifest['phases'] ?? [] as $ph_row) {
            if ((int) ($ph_row['phase'] ?? 0) === $p) {
                $phase_info = session_phase_info($ph_row);
                break;
            }
        }
        foreach ($sections as $idx => $section) {
            $work_leg = $idx + 1;
            $numbers = session_waybill_numbers_for_sections($dbc, [$section]);
            sort($numbers, SORT_NATURAL | SORT_FLAG_CASE);
            $waybills = [];
            foreach ($numbers as $wb) {
                $waybills[] = [
                    'number' => $wb,
                    'href' => session_waybill_href_for_number($wb, $session_nbr, $p, $root),
                ];
            }
            $legs[] = [
                'workflow_phase' => $p,
                'work_leg' => $work_leg,
                'work_leg_total' => $work_leg_total,
                'info' => $phase_info,
                'label' => (string) ($section['label'] ?? ('Phase ' . $work_leg)),
                'base_href' => session_output_url('session_' . (int) $session_nbr
                    . '/phase_' . session_phase_pad($p)
                    . '/' . rawurlencode((string) $job)
                    . '/phase_' . session_phase_pad($work_leg)),
                'waybills' => $waybills,
            ];
        }
    }

    return $legs;
}

/**
 * Map of job key => operator-facing train display name for a session, taken
 * from the workflow's "Generate Switch Lists" Override Train value stored in the
 * manifest ('title'). Jobs without an Override Train keep their raw key. When a
 * job's phases carry differing Override Train values they are joined with " / ".
 *
 * Consolidation keys on the Override Train value exactly, so every phase/leg that
 * shares it collapses into one train — with no assumptions about naming (any
 * direction words live in the separate per-leg "info" field, not here).
 */
function session_job_display_map($session_nbr, $manifest = null, $root = null)
{
    $root = $root ?? session_web_root();
    if ($manifest === null) {
        $manifest = session_load_manifest($session_nbr, $root);
    }
    $bases = [];
    foreach ($manifest['phases'] ?? [] as $phase) {
        $base = trim((string) ($phase['title'] ?? ''));
        if ($base === '') {
            continue;
        }
        foreach ($phase['jobs'] ?? [] as $j) {
            $j = trim((string) $j);
            if ($j !== '') {
                $bases[$j][$base] = true;
            }
        }
    }
    $map = [];
    foreach (array_keys($manifest['jobs'] ?? []) as $job) {
        $job = (string) $job;
        $keys = isset($bases[$job]) ? array_keys($bases[$job]) : [];
        $map[$job] = count($keys) > 0 ? implode(' / ', $keys) : $job;
    }
    // Include any job that only appears in phases (defensive) with a base title.
    foreach ($bases as $job => $set) {
        if (!isset($map[$job])) {
            $keys = array_keys($set);
            $map[$job] = count($keys) > 0 ? implode(' / ', $keys) : (string) $job;
        }
    }
    return $map;
}

/** Operator-facing train display name for a single job (see session_job_display_map). */
function session_job_display_name($session_nbr, $job, $manifest = null, $root = null)
{
    $map = session_job_display_map($session_nbr, $manifest, $root);
    return $map[$job] ?? (string) $job;
}

/**
 * Groups of job keys that share the same operator-facing train name, in manifest
 * order: [ display_name => [job_key, ...], ... ]. Jobs whose overwritten titles
 * collapse to the same name (e.g. STG-DEMMLER + D749 both "D749") are grouped so
 * the UI can present a single consolidated train.
 */
function session_job_group_map($session_nbr, $manifest = null, $root = null)
{
    $root = $root ?? session_web_root();
    if ($manifest === null) {
        $manifest = session_load_manifest($session_nbr, $root);
    }
    $display = session_job_display_map($session_nbr, $manifest, $root);
    $groups = [];
    foreach (array_keys($manifest['jobs'] ?? []) as $job) {
        $job = (string) $job;
        $name = $display[$job] ?? $job;
        $groups[$name][] = $job;
    }
    return $groups;
}

/**
 * Member job keys that share the given job's display name (always includes the
 * job itself), in manifest order.
 */
function session_train_group_members($session_nbr, $job, $manifest = null, $root = null)
{
    $root = $root ?? session_web_root();
    if ($manifest === null) {
        $manifest = session_load_manifest($session_nbr, $root);
    }
    $display = session_job_display_map($session_nbr, $manifest, $root);
    $name = $display[(string) $job] ?? (string) $job;
    $members = [];
    foreach (array_keys($manifest['jobs'] ?? []) as $j) {
        $j = (string) $j;
        if (($display[$j] ?? $j) === $name) {
            $members[] = $j;
        }
    }
    if (!in_array((string) $job, $members, true)) {
        $members[] = (string) $job;
    }
    return $members;
}

/**
 * Primary job key for a train's consolidated switch-list print-all file
 * (train_<primary>.print_all.html). Member jobs in a consolidated group all
 * resolve to the same primary so cross-links from per-member waybill pages land
 * on the correct train bundle.
 */
function session_train_print_all_primary_job($session_nbr, $job, $root = null)
{
    $members = session_train_group_members($session_nbr, $job, null, $root);

    return $members[0] ?? (string) $job;
}

/**
 * The manifest phases that make up the session's *latest generation token* —
 * i.e. only the most recent switch list produced for each logical slot.
 *
 * A recipe run for a session can be repeated (e.g. a simulator replaying seeds,
 * or re-running "Generate Switch Lists" after filling orders). Each run appends
 * its phases to the manifest, so over time a single session accumulates many
 * historical copies of the same train's switch list. For display we only want
 * the freshest copy of each slot.
 *
 * A slot is keyed by its train(s) + recipe step + title/info (direction), and
 * because phases are stored in chronological append order, the last entry seen
 * for a key wins. Clean single-run sessions are unaffected (each key appears
 * once). Returns the surviving phase entries in manifest array order (the
 * authorial / operational sequence), not sorted by phase_NN folder numbers —
 * those can diverge when a leg is reconstructed into a later directory.
 */
function session_latest_token_phases(array $manifest)
{
    $latest = [];
    $order = [];
    $i = 0;
    foreach ($manifest['phases'] ?? [] as $phase) {
        if (!is_array($phase)) {
            continue;
        }
        if ((int) ($phase['phase'] ?? 0) < 1) {
            continue;
        }
        // Key on the logical slot (trains + title/info), NOT the recipe step
        // number, which can shift between runs and otherwise splits the same
        // phase into two "tokens". Later entries overwrite earlier ones.
        $key = session_phase_slot_key($phase);
        $latest[$key] = $phase;
        $order[$key] = $i;
        $i++;
    }
    $result = array_values($latest);
    usort($result, static function ($a, $b) use ($order) {
        return ($order[session_phase_slot_key($a)] ?? 0)
            <=> ($order[session_phase_slot_key($b)] ?? 0);
    });

    return $result;
}

/**
 * Map of phase_num => 0-based display rank from session_latest_token_phases.
 * Used where code still keys by phase folder number but must present legs in
 * operational (manifest) order rather than numeric phase order.
 *
 * @return array<int,int>
 */
function session_phase_display_rank(array $manifest)
{
    $rank = [];
    $i = 0;
    foreach (session_latest_token_phases($manifest) as $phase) {
        $pn = (int) ($phase['phase'] ?? 0);
        if ($pn > 0 && !isset($rank[$pn])) {
            $rank[$pn] = $i++;
        }
    }

    return $rank;
}

/**
 * Set of phase numbers ([phase_num => true]) belonging to a job in the session's
 * latest generation token (see session_latest_token_phases). Used to hide stale
 * appended copies from per-train leg/phase enumerations.
 */
function session_job_latest_token_phase_nums(array $manifest, $job)
{
    $job = (string) $job;
    $nums = [];
    foreach (session_latest_token_phases($manifest) as $phase) {
        $jobs = array_map('strval', (array) ($phase['jobs'] ?? []));
        if (in_array($job, $jobs, true)) {
            $nums[(int) ($phase['phase'] ?? 0)] = true;
        }
    }

    return $nums;
}

/**
 * Distinct waybill count for the session's latest generation token. The waybill
 * store groups snapshots by "JOB|PHASE"; summing only the groups whose phase is
 * in the latest token (see session_latest_token_phases) yields the freshest
 * cycle's waybills instead of every historical copy accumulated by repeated runs.
 */
function session_latest_token_waybill_count($session_nbr, array $manifest = null, $root = null)
{
    $root = $root ?? session_web_root();
    if ($manifest === null) {
        $manifest = session_load_manifest($session_nbr, $root);
    }
    $token = [];
    foreach (session_latest_token_phases($manifest) as $phase) {
        $token[(int) ($phase['phase'] ?? 0)] = true;
    }
    if ($token === []) {
        return 0;
    }
    $store = session_waybill_store_load($session_nbr, $root);
    $nums = [];
    foreach ($store['groups'] ?? [] as $key => $group) {
        [, $gphase] = array_pad(explode('|', (string) $key, 2), 2, '');
        if (isset($token[(int) $gphase])) {
            foreach ((array) $group as $num) {
                $nums[$num] = true;
            }
        }
    }

    return count($nums);
}

/**
 * Browse link to the "same" train in another session. Trains are identified by
 * their operator-facing display name (the Generate Switch Lists "Override Train"
 * value), which is stable across sessions even when the underlying job key
 * differs. When the target session has no train with that display name (e.g. it
 * didn't run that train), fall back to that session's overview so cross-session
 * navigation never lands on an empty "train not found" view.
 */
function session_train_browse_href($target_session, $display_name, $style = 'mobile', $root = null)
{
    $target_session = (int) $target_session;
    $root = $root ?? session_web_root();
    $style = session_normalize_switchlist_style($style);
    $display_name = trim((string) $display_name);
    if ($display_name !== '') {
        $groups = session_job_group_map($target_session, null, $root);
        if (isset($groups[$display_name]) && count($groups[$display_name]) > 0) {
            $primary = $groups[$display_name][0];
            return 'job.php?session=' . $target_session
                . '&job=' . rawurlencode((string) $primary)
                . '&style=' . rawurlencode($style);
        }
    }

    return session_session_index_href($target_session) . '&style=' . rawurlencode($style);
}

/**
 * Ensure a session's switch lists exist in the requested style, rendering them on
 * demand when missing so inline viewers (e.g. job.php iframes) never request a
 * style file that hasn't been generated yet. Returns true when the style is
 * available after the call. Idempotent/cached: a no-op once the style exists.
 */
function session_ensure_style_rendered($dbc, $session_nbr, $style, array $manifest = null, $root = null)
{
    $root = $root ?? session_web_root();
    $style = session_normalize_switchlist_style($style);
    if ($manifest === null) {
        $manifest = session_load_manifest($session_nbr, $root);
    }
    if (session_session_style_available($session_nbr, $style, $manifest, $root)) {
        return true;
    }
    session_rerender_session_style($dbc, $session_nbr, $style, $root);
    $fresh = session_load_manifest($session_nbr, $root);

    return session_session_style_available($session_nbr, $style, $fresh, $root);
}

/** Workflow phases a train runs that have a generated job print_all.html. */
function session_train_switchlist_phase_links($session_nbr, $job, array $phase_nums, $root = null)
{
    $root = $root ?? session_web_root();
    $links = [];
    foreach ($phase_nums as $p) {
        $p = (int) $p;
        if ($p < 1) {
            continue;
        }
        $job_rel = 'session_' . (int) $session_nbr . '/phase_' . session_phase_pad($p) . '/' . rawurlencode((string) $job);
        $index_href = session_output_url($job_rel . '/index.html');
        $print_href = session_output_url($job_rel . '/print_all.html');
        $links[] = [
            'phase' => $p,
            'index_href' => $index_href,
            'has_index' => is_file(session_output_fs_path($job_rel . '/index.html', $root)),
            'print_href' => $print_href,
            'has_print' => is_file(session_output_fs_path($job_rel . '/print_all.html', $root)),
        ];
    }

    return $links;
}

/**
 * Per-train output counts for a session: number of generated switch lists
 * (work-legs across workflow phases) and the number of distinct waybills for
 * the cars that train handles.
 *
 * @return array{switchlists:int, waybills:int}
 */
function session_train_output_counts($dbc, $session_nbr, $job, $root = null)
{
    $legs = session_train_switchlist_legs($dbc, $session_nbr, $job, $root);
    $waybills = [];
    foreach ($legs as $leg) {
        foreach ($leg['waybills'] ?? [] as $wb) {
            $num = $wb['number'] ?? '';
            if ($num !== '') {
                $waybills[$num] = true;
            }
        }
    }

    return [
        'switchlists' => count($legs),
        'waybills' => count($waybills),
    ];
}

/**
 * Build a session-wide "print all switch lists" page that concatenates every
 * built workflow phase and train for the session into one printable document at
 * session_N/print_all.html. Returns the relative path (for session_output_url)
 * or null when the session has no generated switch lists.
 */
function session_build_switchlist_print_all($dbc, $session_nbr, $root = null)
{
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    $manifest = session_load_manifest($session_nbr, $root);

    $phases_html = '';
    $phase_count = 0;
    $trains = [];
    foreach (session_latest_token_phases($manifest) as $phase) {
        $pnum = (int) ($phase['phase'] ?? 0);
        if ($pnum < 1) {
            continue;
        }
        $phase_dir = session_phase_output_dir($session_nbr, $pnum, $root);
        foreach ($phase['jobs'] ?? [] as $job) {
            $job = trim((string) $job);
            if ($job === '') {
                continue;
            }
            $sections = master_sw_load_sections_cache($phase_dir, $job, $session_nbr);
            if (!is_array($sections) || count($sections) === 0) {
                continue;
            }
            $meta = master_sw_job_meta($dbc, $job);
            if ($meta === null) {
                continue;
            }
            $phase_title = trim((string) ($phase['title'] ?? ''));
            if ($phase_title === '') {
                $phase_title = master_sw_switchlist_title_from_cache($phase_dir, $job, $session_nbr);
            }
            $phase_info = session_phase_info($phase);
            $phase_opts = ['title' => $phase_title, 'info' => $phase_info];
            $display_train = master_sw_display_train_name($meta['table_name'], $phase_opts);
            $total = count($sections);
            for ($i = 0; $i < $total; $i++) {
                $phases_html .= master_sw_render_print_all_phase_body(
                    $dbc,
                    $sections[$i],
                    $i + 1,
                    $total,
                    $display_train,
                    $phase_opts
                );
                $phase_count++;
            }
            $trains[$job] = true;
        }
    }

    if ($phases_html === '') {
        return null;
    }

    $rel = 'session_' . $session_nbr . '/print_all.html';

    // Style dropdown: the combined view is style-agnostic (one consolidated
    // table). Selecting a style renders a per-style combined print-all
    // (print_all_<style>.html) that stitches each train/phase's real switch list
    // in that layout, generated on demand and cached (see
    // session_build_switchlist_print_all_style + the build_print_all_style API).
    [$style_cluster, $style_script] = session_print_all_style_controls($session_nbr, '', '', $root);
    // Minimal nav: the Train/Style dropdowns handle scope; "Waybills" follows the
    // current selection (all trains -> all waybills). "waybills/print_all.html" is
    // RELATIVE to this file's directory (session_N/) so so.php rewrites it.
    $nav_html = session_nav_bar_html([
        ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
        ['href' => '/sts/session_overview.php?session=' . $session_nbr, 'label' => 'Session ' . $session_nbr, 'icon' => 'calendar-event'],
        ['href' => 'waybills/print_all.html', 'label' => 'Waybills', 'icon' => 'files'],
    ], '');
    $nav_html = str_replace('</div></div></nav>', $style_cluster . '</div></div></nav>', $nav_html);
    $session_nav = session_switchlist_print_all_session_nav_html($session_nbr, '', $dbc, $root);

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Session ' . (int) $session_nbr . ' — print all switch lists</title>'
        . master_sw_render_head_assets()
        . '</head><body>'
        . $nav_html
        . $session_nav
        . '<div class="page">'
        . '<div class="noprint" style="margin-bottom:12px;">'
        . '<button type="button" onclick="stsSafePrint()">PRINT ALL SWITCH LISTS</button>'
        . '<p style="margin:8px 0 0; color:#555; font-size:14px;">Combined view. Pick a style above to print every switch list in that layout — each starts on a new page. Use an external browser (Chrome/Safari) if Cursor\'s preview crashes on print.</p>'
        . '</div>'
        . $phases_html
        . '</div>'
        . $style_script
        . '</body></html>';

    $fs = session_output_fs_path($rel, $root);
    $dir = dirname($fs);
    session_ensure_writable_dir($dir);
    if (file_put_contents($fs, $html) === false) {
        return null;
    }

    return $rel;
}

/**
 * Build (and cache) a per-train print-all switch list: every phase/leg the train
 * runs, in phase order, concatenated into one printable document — the switch
 * list analogue of the per-train waybill bundle (job_<job>.print_all.html). The
 * train is the consolidated group of member jobs sharing the given job's display
 * name, so multi-key trains (e.g. STG-DEMMLER + D749) print as one train.
 * Returns the relative output path, or null when the train has no switch lists.
 */
function session_build_switchlist_train_print_all($dbc, $session_nbr, $job, $root = null)
{
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    $job = trim((string) $job);
    if ($job === '') {
        return null;
    }
    $manifest = session_load_manifest($session_nbr, $root);
    $members = session_train_group_members($session_nbr, $job, $manifest, $root);
    $member_set = array_fill_keys($members, true);
    $display = session_job_display_map($session_nbr, $manifest, $root);
    $train_label = $display[$job] ?? $job;

    $phases_html = '';
    $phase_count = 0;
    foreach (session_latest_token_phases($manifest) as $phase) {
        $pnum = (int) ($phase['phase'] ?? 0);
        if ($pnum < 1) {
            continue;
        }
        $phase_dir = session_phase_output_dir($session_nbr, $pnum, $root);
        foreach ($phase['jobs'] ?? [] as $pj) {
            $pj = trim((string) $pj);
            if ($pj === '' || !isset($member_set[$pj])) {
                continue;
            }
            $sections = master_sw_load_sections_cache($phase_dir, $pj, $session_nbr);
            if (!is_array($sections) || count($sections) === 0) {
                continue;
            }
            $meta = master_sw_job_meta($dbc, $pj);
            if ($meta === null) {
                continue;
            }
            $phase_title = trim((string) ($phase['title'] ?? ''));
            if ($phase_title === '') {
                $phase_title = master_sw_switchlist_title_from_cache($phase_dir, $pj, $session_nbr);
            }
            $phase_info = session_phase_info($phase);
            $phase_opts = ['title' => $phase_title, 'info' => $phase_info];
            $display_train = master_sw_display_train_name($meta['table_name'], $phase_opts);
            $total = count($sections);
            for ($i = 0; $i < $total; $i++) {
                $phases_html .= master_sw_render_print_all_phase_body(
                    $dbc,
                    $sections[$i],
                    $i + 1,
                    $total,
                    $display_train,
                    $phase_opts
                );
                $phase_count++;
            }
        }
    }

    if ($phases_html === '') {
        return null;
    }

    $rel = 'session_' . $session_nbr . '/train_' . $job . '.print_all.html';
    // App-level pages must use absolute /sts/ hrefs: this document is served
    // through so.php, whose link rewriter would otherwise treat a relative
    // "session_overview.php" as a file under session_N/ and mangle it.
    $browse_href = '/sts/session_overview.php?session=' . $session_nbr;
    // "Waybills" is RELATIVE to this file's directory (session_N/) so so.php
    // rewrites it. A consolidated train (multiple member jobs) has no single
    // per-train waybill bundle, so it falls back to the session-wide waybills.
    $train_wb_href = count($members) > 1
        ? 'waybills/print_all.html'
        : 'waybills/job_' . rawurlencode($job) . '.print_all.html';
    [$style_cluster, $style_script] = session_print_all_style_controls($session_nbr, '', $job, $root);
    // Minimal nav: Train/Style dropdowns handle scope; "Waybills" follows this
    // train (session-wide waybills for a consolidated multi-member train).
    $nav_html = session_nav_bar_html([
        ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
        ['href' => $browse_href, 'label' => 'Session ' . $session_nbr, 'icon' => 'calendar-event'],
        ['href' => $train_wb_href, 'label' => 'Waybills', 'icon' => 'file-text'],
    ], '');
    $nav_html = str_replace('</div></div></nav>', $style_cluster . '</div></div></nav>', $nav_html);
    $session_nav = session_switchlist_train_print_all_session_nav_html($session_nbr, $job, $dbc, $root);

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Session ' . $session_nbr . ' — ' . htmlspecialchars($train_label) . ' print all switch lists</title>'
        . master_sw_render_head_assets()
        . '</head><body>'
        . $nav_html
        . $session_nav
        . '<div class="page">'
        . '<div class="noprint" style="margin-bottom:12px;">'
        . '<button type="button" onclick="stsSafePrint()">PRINT ALL SWITCH LISTS</button>'
        . '<p style="margin:8px 0 0; color:#555; font-size:14px;">All phases for '
        . htmlspecialchars($train_label) . '. Pick a style above to print in that layout — each switch list starts on a new printed page.</p>'
        . '</div>'
        . $phases_html
        . '</div>'
        . $style_script
        . '</body></html>';

    $fs = session_output_fs_path($rel, $root);
    session_ensure_writable_dir(dirname($fs));
    if (file_put_contents($fs, $html) === false) {
        return null;
    }

    return $rel;
}

/**
 * Nav-bar Train + Style dropdowns + script shared by every switch-list print-all
 * page: the combined session view (print_all.html), the per-style session views
 * (print_all_<style>.html), the per-train combined view (train_<job>.print_all
 * .html) and the per-train per-style views (train_<job>.print_all_<style>.html).
 *
 * The Train dropdown lets you narrow to one consolidated train or back to "All
 * trains"; the Style dropdown switches layout. Combined (style="") views are the
 * style-agnostic consolidated table and are reached by direct so.php navigation;
 * picking an actual style POSTs to build_print_all_style (optionally scoped to a
 * train via `job`), which renders/caches the stitched per-style document and
 * returns its URL.
 *
 * @param string $selected_style '' for the combined view, else a style key.
 * @param string $selected_job   '' for all trains, else a member job key (the
 *                               dropdown resolves it to its consolidated primary).
 * @return array{0:string,1:string} [cluster_html, script_html]
 */
function session_print_all_style_controls($session_nbr, $selected_style, $selected_job = '', $root = null)
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    $selected_style = $selected_style === '' ? '' : session_normalize_switchlist_style($selected_style);
    $selected_job = trim((string) $selected_job);

    // Train dropdown: "All trains" + one entry per consolidated train. The option
    // value is the group's primary job key (matches the train_<primary>.print_all
    // file naming); the current train is selected when its member set contains the
    // requested job.
    $train_options = '<option value="">All trains</option>';
    foreach (session_job_group_map($session_nbr, null, $root) as $name => $members) {
        $primary = isset($members[0]) ? (string) $members[0] : '';
        if ($primary === '') {
            continue;
        }
        $is_sel = ($selected_job !== '' && in_array($selected_job, $members, true));
        $train_options .= '<option value="' . htmlspecialchars($primary, ENT_QUOTES) . '"'
            . ($is_sel ? ' selected' : '') . '>' . htmlspecialchars($name) . '</option>';
    }

    // Style dropdown: "Combined" (style-agnostic) + each switch-list style.
    $style_options = '<option value="">Combined</option>';
    foreach (session_switchlist_styles() as $key => $label) {
        $sel = ($selected_style !== '' && $key === $selected_style) ? ' selected' : '';
        $style_options .= '<option value="' . htmlspecialchars($key, ENT_QUOTES) . '"' . $sel . '>'
            . htmlspecialchars($label) . '</option>';
    }

    $cluster = '<div class="d-flex align-items-center gap-2 ms-auto">'
        . '<label for="sw-train-select" class="text-white-50 small mb-0">Train</label>'
        . '<select id="sw-train-select" class="form-select form-select-sm" style="width:auto;">'
        . $train_options . '</select>'
        . '<label for="sw-style-select" class="text-white-50 small mb-0">Style</label>'
        . '<select id="sw-style-select" class="form-select form-select-sm" style="width:auto;">'
        . $style_options . '</select>'
        . '<span id="sw-style-status" class="text-white-50 small"></span></div>';
    $script = '<script>(function(){'
        . 'var tr=document.getElementById("sw-train-select");'
        . 'var el=document.getElementById("sw-style-select");'
        . 'if(!tr||!el)return;'
        . 'var st=document.getElementById("sw-style-status");'
        . 'var sid=' . $session_nbr . ';'
        . 'function go(){var job=tr.value;var style=el.value;'
        . 'if(style===""){'
        . 'var f=job===""?("session_"+sid+"/print_all.html"):("session_"+sid+"/train_"+encodeURIComponent(job)+".print_all.html");'
        . 'window.location.href="/sts/so.php?f="+encodeURIComponent(f).replace(/%2F/g,"/");return;}'
        . 'tr.disabled=true;el.disabled=true;if(st)st.textContent="Rendering\u2026";'
        . 'var body={session:sid,style:style};if(job!=="")body.job=job;'
        . 'fetch("/sts/operational_steps_api.php?action=build_print_all_style",{method:"POST",'
        . 'headers:{"Content-Type":"application/json"},body:JSON.stringify(body)})'
        . '.then(function(r){return r.json();})'
        . '.then(function(d){if(!d.ok||!d.url)throw new Error(d.error||"Render failed");window.location.href=d.url;})'
        . '.catch(function(e){tr.disabled=false;el.disabled=false;if(st)st.textContent=String(e.message||e);});}'
        . 'tr.addEventListener("change",go);el.addEventListener("change",go);'
        . '})();</script>';

    return [$cluster, $script];
}

/**
 * Extract the inner HTML of the `.page` content wrapper from a generated
 * switch-list file, dropping any `.noprint` chrome (PRINT button, style nav).
 * Used to stitch per-phase per-style switch lists into a combined print-all
 * document without re-implementing each style's layout.
 */
function session_extract_switchlist_page_html($html)
{
    if (!is_string($html) || trim($html) === '') {
        return '';
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    // Prefix an XML encoding hint so loadHTML treats the bytes as UTF-8.
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $xpath = new DOMXPath($doc);
    $pages = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " page ")]');
    if ($pages === false || $pages->length === 0) {
        return '';
    }
    $page = $pages->item(0);

    // Remove .noprint chrome inside the page (PRINT button, etc.).
    $noprints = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " noprint ")]', $page);
    if ($noprints !== false) {
        $remove = [];
        foreach ($noprints as $node) {
            $remove[] = $node;
        }
        foreach ($remove as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    $inner = '';
    foreach ($page->childNodes as $child) {
        $inner .= $doc->saveHTML($child);
    }

    return $inner;
}

/**
 * Build (and cache) a combined print-all document for a single switch-list style:
 * session_N/print_all_<style>.html. Each train/phase's switch list is stitched in
 * the requested layout, one per printed page. The per-phase switch-list files are
 * (re)generated in that style first (idempotent), then their `.page` bodies are
 * concatenated. Returns the relative output path, or null when nothing rendered.
 */
function session_build_switchlist_print_all_style($dbc, $session_nbr, $style, $root = null)
{
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    $style = session_normalize_switchlist_style($style);

    // Ensure the per-phase switch lists exist in this style (idempotent — skips
    // phases already rendered in the style).
    session_rerender_session_style($dbc, $session_nbr, $style, $root);
    $manifest = session_load_manifest($session_nbr, $root);

    $phases_html = '';
    $phase_count = 0;
    $trains = [];
    foreach (session_latest_token_phases($manifest) as $phase) {
        $pnum = (int) ($phase['phase'] ?? 0);
        if ($pnum < 1) {
            continue;
        }
        $phase_dir = session_phase_output_dir($session_nbr, $pnum, $root);
        foreach ($phase['jobs'] ?? [] as $job) {
            $job = trim((string) $job);
            if ($job === '') {
                continue;
            }
            $sections = master_sw_load_sections_cache($phase_dir, $job, $session_nbr);
            if (!is_array($sections) || count($sections) === 0) {
                continue;
            }
            $total = count($sections);
            for ($i = 0; $i < $total; $i++) {
                $work_leg = $i + 1;
                $leg_rel = session_switchlist_work_phase_rel($session_nbr, $pnum, $job, $work_leg, $style);
                $leg_fs = session_output_fs_path($leg_rel, $root);
                if (!is_file($leg_fs)) {
                    continue;
                }
                $body = session_extract_switchlist_page_html((string) file_get_contents($leg_fs));
                if (trim($body) === '') {
                    continue;
                }
                // The extracted per-style body already includes the switch-list
                // header (train title, phase band, table). Wrap for page breaks only.
                $phases_html .= '<section class="print-all-phase">' . $body . '</section>';
                $phase_count++;
            }
            $trains[$job] = true;
        }
    }

    if ($phases_html === '') {
        return null;
    }

    $rel = 'session_' . $session_nbr . '/print_all_' . $style . '.html';
    [$style_cluster, $style_script] = session_print_all_style_controls($session_nbr, $style, '', $root);
    $nav_html = session_nav_bar_html([
        ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
        ['href' => '/sts/session_overview.php?session=' . $session_nbr, 'label' => 'Session ' . $session_nbr, 'icon' => 'calendar-event'],
        ['href' => 'waybills/print_all.html', 'label' => 'Waybills', 'icon' => 'files'],
    ], '');
    $nav_html = str_replace('</div></div></nav>', $style_cluster . '</div></div></nav>', $nav_html);
    $session_nav = session_switchlist_print_all_session_nav_html($session_nbr, $style, $dbc, $root);

    $style_label = master_sw_style_label($style);
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Session ' . $session_nbr . ' — print all (' . htmlspecialchars($style_label) . ')</title>'
        . master_sw_render_head_assets()
        . '</head><body>'
        . $nav_html
        . $session_nav
        . '<div class="page">'
        . '<div class="noprint" style="margin-bottom:12px;">'
        . '<button type="button" onclick="stsSafePrint()">PRINT ALL SWITCH LISTS</button>'
        . '<p style="margin:8px 0 0; color:#555; font-size:14px;">Style: ' . htmlspecialchars($style_label)
        . '. Each switch list starts on a new printed page.</p>'
        . '</div>'
        . $phases_html
        . '</div>'
        . $style_script
        . '</body></html>';

    $fs = session_output_fs_path($rel, $root);
    session_ensure_writable_dir(dirname($fs));
    if (file_put_contents($fs, $html) === false) {
        return null;
    }

    return $rel;
}

/**
 * Build (and cache) a per-train print-all switch list in a single style:
 * session_N/train_<job>.print_all_<style>.html. The per-train analogue of
 * session_build_switchlist_print_all_style — every phase/leg the consolidated
 * train runs, stitched in the requested layout, one per printed page.
 * Returns the relative output path, or null when the train has no switch lists.
 */
function session_build_switchlist_train_print_all_style($dbc, $session_nbr, $job, $style, $root = null)
{
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    $job = trim((string) $job);
    $style = session_normalize_switchlist_style($style);
    if ($job === '') {
        return null;
    }

    // Ensure per-phase switch lists exist in this style (idempotent).
    session_rerender_session_style($dbc, $session_nbr, $style, $root);
    $manifest = session_load_manifest($session_nbr, $root);
    $members = session_train_group_members($session_nbr, $job, $manifest, $root);
    $member_set = array_fill_keys($members, true);
    $display = session_job_display_map($session_nbr, $manifest, $root);
    $train_label = $display[$job] ?? $job;

    $phases_html = '';
    $phase_count = 0;
    foreach (session_latest_token_phases($manifest) as $phase) {
        $pnum = (int) ($phase['phase'] ?? 0);
        if ($pnum < 1) {
            continue;
        }
        foreach ($phase['jobs'] ?? [] as $pj) {
            $pj = trim((string) $pj);
            if ($pj === '' || !isset($member_set[$pj])) {
                continue;
            }
            $phase_dir = session_phase_output_dir($session_nbr, $pnum, $root);
            $sections = master_sw_load_sections_cache($phase_dir, $pj, $session_nbr);
            if (!is_array($sections) || count($sections) === 0) {
                continue;
            }
            $total = count($sections);
            for ($i = 0; $i < $total; $i++) {
                $work_leg = $i + 1;
                $leg_rel = session_switchlist_work_phase_rel($session_nbr, $pnum, $pj, $work_leg, $style);
                $leg_fs = session_output_fs_path($leg_rel, $root);
                if (!is_file($leg_fs)) {
                    continue;
                }
                $body = session_extract_switchlist_page_html((string) file_get_contents($leg_fs));
                if (trim($body) === '') {
                    continue;
                }
                $phases_html .= '<section class="print-all-phase">' . $body . '</section>';
                $phase_count++;
            }
        }
    }

    if ($phases_html === '') {
        return null;
    }

    $rel = 'session_' . $session_nbr . '/train_' . $job . '.print_all_' . $style . '.html';
    $browse_href = '/sts/session_overview.php?session=' . $session_nbr;
    $train_wb_href = count($members) > 1
        ? 'waybills/print_all.html'
        : 'waybills/job_' . rawurlencode($job) . '.print_all.html';
    [$style_cluster, $style_script] = session_print_all_style_controls($session_nbr, $style, $job, $root);
    // Minimal nav: Train/Style dropdowns handle scope; "Waybills" follows this
    // train (session-wide waybills for a consolidated multi-member train).
    $nav_html = session_nav_bar_html([
        ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
        ['href' => $browse_href, 'label' => 'Session ' . $session_nbr, 'icon' => 'calendar-event'],
        ['href' => $train_wb_href, 'label' => 'Waybills', 'icon' => 'file-text'],
    ], '');
    $nav_html = str_replace('</div></div></nav>', $style_cluster . '</div></div></nav>', $nav_html);
    $session_nav = session_switchlist_train_print_all_session_nav_html($session_nbr, $job, $dbc, $root);

    $style_label = master_sw_style_label($style);
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Session ' . $session_nbr . ' — ' . htmlspecialchars($train_label) . ' print all (' . htmlspecialchars($style_label) . ')</title>'
        . master_sw_render_head_assets()
        . '</head><body>'
        . $nav_html
        . $session_nav
        . '<div class="page">'
        . '<div class="noprint" style="margin-bottom:12px;">'
        . '<button type="button" onclick="stsSafePrint()">PRINT ALL SWITCH LISTS</button>'
        . '<p style="margin:8px 0 0; color:#555; font-size:14px;">All phases for '
        . htmlspecialchars($train_label) . ' · ' . htmlspecialchars($style_label)
        . '. Each switch list starts on a new printed page.</p>'
        . '</div>'
        . $phases_html
        . '</div>'
        . $style_script
        . '</body></html>';

    $fs = session_output_fs_path($rel, $root);
    session_ensure_writable_dir(dirname($fs));
    if (file_put_contents($fs, $html) === false) {
        return null;
    }

    return $rel;
}

/** True when at least one train in the phase has a generated switch-list page. */
function session_phase_has_switchlists(array $phase, $session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $phase_num = (int) ($phase['phase'] ?? 0);
    if ($phase_num < 1) {
        return false;
    }
    foreach ($phase['jobs'] ?? [] as $job) {
        $job = trim((string) $job);
        if ($job === '') {
            continue;
        }
        $index = session_output_fs_path('session_' . (int) $session_nbr
            . '/phase_' . session_phase_pad($phase_num)
            . '/' . rawurlencode((string) $job)
            . '/index.html', $root);
        if (is_file($index) && filesize($index) > 400) {
            return true;
        }
    }

    return false;
}

function session_manifest_has_switchlists(array $manifest, $session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    foreach ($manifest['phases'] ?? [] as $phase) {
        if (session_phase_has_switchlists($phase, $session_nbr, $root)) {
            return true;
        }
    }

    $legacy = session_dir_for($session_nbr, $root) . '/index.html';
    return is_file($legacy) && filesize($legacy) > 400;
}

/**
 * Featured station names pinned to the top of a session station report (display
 * order). Layouts may override via warm_start_station_report_featured().
 *
 * @return list<string>
 */
function session_station_report_featured_stations()
{
    if (function_exists('warm_start_station_report_featured')) {
        return warm_start_station_report_featured();
    }

    return [];
}

/** True when a cached station_report.html should be rebuilt. */
function session_station_report_stale($session_nbr, $report_fs, $root = null)
{
    if (!is_file($report_fs)) {
        return true;
    }
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    $mtime = (int) filemtime($report_fs);
    $manifest = session_dir_for($session_nbr, $root) . '/manifest.json';
    if (is_file($manifest) && filemtime($manifest) > $mtime) {
        return true;
    }
    foreach (glob(session_dir_for($session_nbr, $root) . '/phase_*/*_master.json') ?: [] as $master) {
        if (filemtime($master) > $mtime) {
            return true;
        }
    }

    return false;
}

/**
 * Destination location id for a switch-list car row (pickup target track).
 *
 * @param array<string,mixed> $car
 * @param array<string,int>   $code_to_loc
 */
function session_station_report_dest_loc(array $car, array $code_to_loc)
{
    $status = (string) ($car['status'] ?? '');
    if ($status === 'Ordered') {
        $code = (string) ($car['loading_location'] ?? '');
    } elseif (in_array($status, ['Loading', 'Loaded', 'Unloading'], true)) {
        $code = (string) ($car['unloading_location'] ?? '');
    } else {
        $code = '';
    }

    return $code !== '' ? ($code_to_loc[$code] ?? 0) : 0;
}

/**
 * Resting location id at the start of session N (0 when unknown/off-layout).
 *
 * @param array<int,array<int,int>>     $obs
 * @param array<int,int>                $s1only_dest
 * @param array<string,mixed>           $car
 */
function session_station_report_start_loc($cid, $session, array $obs, array $s1only_dest, array $car)
{
    if (isset($obs[$cid][$session])) {
        return $obs[$cid][$session];
    }
    if (isset($obs[$cid])) {
        $later = array_filter(array_keys($obs[$cid]), static fn($n) => $n > $session);
        if ($later) {
            return $obs[$cid][min($later)];
        }
        if (!empty($s1only_dest[$cid])) {
            return $s1only_dest[$cid];
        }
    }

    return (int) ($car['current'] ?? 0) > 0 ? (int) $car['current'] : 0;
}

/**
 * Load/status at the start of session N.
 *
 * @param array<int,array<int,string>>  $obs_status
 * @param array<string,mixed>           $car
 */
function session_station_report_start_status($cid, $session, array $obs_status, array $car)
{
    if (isset($obs_status[$cid][$session])) {
        return $obs_status[$cid][$session];
    }
    if (isset($obs_status[$cid])) {
        $later = array_filter(array_keys($obs_status[$cid]), static fn($n) => $n > $session);
        if ($later) {
            return $obs_status[$cid][min($later)];
        }
    }

    return (string) ($car['live_status'] ?? '');
}

/**
 * Assemble per-car rows for the start-of-session station report.
 *
 * @return array{by_station: array<string,list<array>>, station_order: list<string>, total: int}|null
 */
function session_station_report_data($dbc, $session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    if ($session_nbr < 1 || !is_dir(session_dir_for($session_nbr, $root))) {
        return null;
    }

    $locations = [];
    $code_to_loc = [];
    $rs = mysqli_query($dbc, 'SELECT id, code, station FROM locations');
    while ($row = mysqli_fetch_assoc($rs)) {
        $id = (int) $row['id'];
        $locations[$id] = ['code' => (string) $row['code'], 'station_id' => (int) $row['station']];
        $code_to_loc[(string) $row['code']] = $id;
    }

    $stations = [];
    $rs = mysqli_query($dbc, 'SELECT id, station, sort_seq FROM routing');
    while ($row = mysqli_fetch_assoc($rs)) {
        $stations[(int) $row['id']] = ['name' => (string) $row['station'], 'sort' => (int) $row['sort_seq']];
    }

    $cars = [];
    $by_marks = [];
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS id, cars.reporting_marks AS marks, cars.current_location_id AS cur,
                cars.status AS live_status, cc.code AS car_code,
                homeloc.code AS home_code, homest.station AS home_station
         FROM cars
         LEFT JOIN car_codes cc ON cc.id = cars.car_code_id
         LEFT JOIN locations homeloc ON homeloc.id = cars.home_location
         LEFT JOIN routing homest ON homest.id = homeloc.station'
    );
    while ($row = mysqli_fetch_assoc($rs)) {
        $cid = (int) $row['id'];
        $cars[$cid] = [
            'marks' => (string) $row['marks'],
            'car_code' => (string) ($row['car_code'] ?? ''),
            'current' => (int) $row['cur'],
            'live_status' => (string) ($row['live_status'] ?? ''),
            'home' => $row['home_station'] !== null && $row['home_station'] !== ''
                ? (string) $row['home_station'] : (string) ($row['home_code'] ?? ''),
        ];
        $by_marks[(string) $row['marks']] = $cid;
    }

    $obs = [];
    $obs_status = [];
    $session_work = [];
    $pre_dest = [];

    $sess_dirs = [];
    foreach (glob($root . '/session_*', GLOB_ONLYDIR) ?: [] as $d) {
        if (preg_match('#/session_(\d+)$#', $d, $m)) {
            $sess_dirs[(int) $m[1]] = $d;
        }
    }
    ksort($sess_dirs);

    foreach ($sess_dirs as $sn => $dir) {
        $files = glob($dir . '/phase_*/*_master.json') ?: [];
        sort($files);
        foreach ($files as $f) {
            $d = json_decode((string) file_get_contents($f), true);
            if (!is_array($d) || empty($d['sections'])) {
                continue;
            }
            $job = (string) ($d['job'] ?? '');
            foreach ($d['sections'] as $sec) {
                foreach (($sec['cars'] ?? []) as $car) {
                    $marks = (string) ($car['reporting_marks'] ?? ($car[0] ?? ''));
                    if ($marks === '' || !isset($by_marks[$marks])) {
                        continue;
                    }
                    $cid = $by_marks[$marks];
                    $loc = (int) ($car['current_location_id'] ?? ($car[14] ?? 0));
                    $status = (string) ($car['status'] ?? ($car[2] ?? ''));
                    if (!isset($obs[$cid][$sn]) && $loc > 0) {
                        $obs[$cid][$sn] = $loc;
                    }
                    if (!isset($obs_status[$cid][$sn]) && $status !== '') {
                        $obs_status[$cid][$sn] = $status;
                    }
                    if ($sn === $session_nbr && !isset($session_work[$cid])) {
                        $session_work[$cid] = [
                            'job' => $job,
                            'dest_loc' => session_station_report_dest_loc($car, $code_to_loc),
                        ];
                    }
                    if ($sn < $session_nbr) {
                        $dest = session_station_report_dest_loc($car, $code_to_loc);
                        if ($dest > 0) {
                            $pre_dest[$cid] = $dest;
                        }
                    }
                }
            }
        }
    }

    $featured = session_station_report_featured_stations();
    $by_station = [];
    foreach ($cars as $cid => $car) {
        $loc_id = session_station_report_start_loc($cid, $session_nbr, $obs, $pre_dest, $car);
        $loc = $locations[$loc_id] ?? null;
        $loc_code = $loc['code'] ?? ($loc_id > 0 ? 'loc#' . $loc_id : '');
        $st = $loc ? ($stations[$loc['station_id']] ?? null) : null;
        $station_name = $loc_code === '' ? 'On train / off-layout' : ($st['name'] ?? 'Unknown station');
        $sort = $st['sort'] ?? 99999;
        $status = session_station_report_start_status($cid, $session_nbr, $obs_status, $car);

        $stays = !isset($session_work[$cid]);
        if ($stays) {
            $action = '—';
        } else {
            $w = $session_work[$cid];
            $dest = $w['dest_loc'] ? ($locations[$w['dest_loc']]['code'] ?? '') : '';
            $action = 'Pick up · ' . ($w['job'] ?: '?') . ($dest !== '' ? ' → ' . $dest : '');
        }

        $by_station[$station_name][] = [
            'sort' => $sort,
            'loc' => $loc_code,
            'marks' => $car['marks'],
            'car_code' => $car['car_code'],
            'status' => $status,
            'home' => $car['home'],
            'action' => $action,
            'stays' => $stays,
        ];
    }

    foreach ($by_station as &$rows) {
        usort($rows, static fn($a, $b) => [$a['loc'], $a['marks']] <=> [$b['loc'], $b['marks']]);
    }
    unset($rows);

    $station_order = [];
    foreach ($featured as $f) {
        if (isset($by_station[$f])) {
            $station_order[] = $f;
        }
    }
    $rest = [];
    foreach ($by_station as $name => $rows) {
        if (!in_array($name, $station_order, true)) {
            $rest[$name] = $rows[0]['sort'] ?? 99999;
        }
    }
    asort($rest);
    foreach (array_keys($rest) as $name) {
        $station_order[] = $name;
    }

    return [
        'by_station' => $by_station,
        'station_order' => $station_order,
        'featured' => $featured,
        'total' => count($cars),
    ];
}

/** HTML for a load-status badge (mirrors display_station_report.php styling). */
function session_station_report_status_html($status)
{
    $status = trim((string) $status);
    if ($status === '') {
        return '';
    }
    $cls = 'status-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $status));

    return '<span class="' . htmlspecialchars($cls, ENT_QUOTES) . '">'
        . htmlspecialchars($status, ENT_QUOTES) . '</span>';
}

/**
 * Render the station report HTML for a session.
 *
 * @param array{by_station: array, station_order: list<string>, featured: list<string>, total: int} $data
 */
function session_station_report_render_html($session_nbr, array $data)
{
    $session_nbr = (int) $session_nbr;
    $by_station = $data['by_station'];
    $station_order = $data['station_order'];
    $featured = $data['featured'];
    $total = (int) $data['total'];
    $session_nav = (string) ($data['session_nav'] ?? '');
    $generated_at = date('M j, Y g:i A');

    // Distinct load statuses (for the status filter dropdown), in a friendly order.
    $status_rank = ['Loaded' => 0, 'Empty' => 1, 'Ordered' => 2, 'Loading' => 3, 'Unloading' => 4];
    $statuses = [];
    foreach ($by_station as $rows) {
        foreach ($rows as $r) {
            $s = trim((string) $r['status']);
            if ($s !== '') {
                $statuses[$s] = true;
            }
        }
    }
    $statuses = array_keys($statuses);
    usort($statuses, static function ($a, $b) use ($status_rank) {
        return [$status_rank[$a] ?? 99, $a] <=> [$status_rank[$b] ?? 99, $b];
    });

    ob_start();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Station Car Report — Start of Session <?= $session_nbr ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background:#f8f9fa; font-size:13px; }
  main { max-width: 1100px; margin: 0 auto; padding: 1rem 1rem 2.5rem; }
  h1 { font-size: 1.15rem; font-weight: 600; margin-bottom:.5rem; }
  .subtitle { color:#6c757d; font-size:.8rem; margin-bottom:.5rem; }
  .station-card { background:#fff; border-radius:.5rem; box-shadow:0 2px 6px rgba(0,0,0,.08); margin-bottom:1rem; overflow:hidden; }
  .station-head { display:flex; justify-content:space-between; align-items:center;
    padding:.5rem .85rem; font-weight:600; font-size:.9rem; color:#fff;
    background:linear-gradient(135deg,#667eea 0%,#764ba2 100%); }
  .station-head .count { font-weight:500; font-size:.8rem; opacity:.9; }
  .featured .station-head { background:linear-gradient(135deg,#0d6efd 0%,#0a58ca 100%); }
  table { margin:0; font-size:.8rem; }
  th { font-size:.68rem; text-transform:uppercase; letter-spacing:.03em; color:#495057; }
  td, th { padding:.3rem .6rem !important; vertical-align:middle; }
  .track { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:.78rem; color:#0a58ca; }
  .marks { font-weight:600; }
  .stay { color:#198754; font-weight:500; }
  .act { color:#b02a37; }
  .legend { font-size:.78rem; color:#6c757d; }
  .status-empty { display:inline-block; background:#ffeaa7; color:#333; padding:1px 6px; border-radius:3px; font-weight:600; font-size:.72rem; }
  .status-loaded { display:inline-block; background:#a8e6cf; color:#333; padding:1px 6px; border-radius:3px; font-weight:600; font-size:.72rem; }
  .status-loading { display:inline-block; background:#74b9ff; color:#fff; padding:1px 6px; border-radius:3px; font-weight:600; font-size:.72rem; }
  .status-unloading { display:inline-block; background:#fab1a0; color:#fff; padding:1px 6px; border-radius:3px; font-weight:600; font-size:.72rem; }
  .status-ordered { display:inline-block; background:#dfe6e9; color:#333; padding:1px 6px; border-radius:3px; font-weight:600; font-size:.72rem; }
  .station-report-navbar { background:linear-gradient(135deg,#0d6efd 0%,#0a58ca 100%); }
  .station-report-navbar .btn-outline-light { border-color:rgba(255,255,255,.65); }
  .session-nav-row { display:flex; align-items:center; flex-wrap:wrap; gap:.4rem; margin-bottom:1.1rem; }
  .station-report-nav-label { font-size:.75rem; text-transform:uppercase; letter-spacing:.03em; color:#6c757d; font-weight:600; margin-right:.15rem; }
  .station-report-jump { max-width:230px; }
  .station-report-session-current { font-weight:600; color:#495057; }
  .srp-filters { display:flex; flex-wrap:wrap; gap:.6rem; align-items:flex-end; background:#fff;
    border-radius:.5rem; box-shadow:0 2px 6px rgba(0,0,0,.08); padding:.85rem 1rem; margin-bottom:1.25rem; }
  .srp-filters .field { display:flex; flex-direction:column; gap:.2rem; }
  .srp-filters label { font-size:.7rem; text-transform:uppercase; letter-spacing:.03em; color:#6c757d; font-weight:600; }
  .srp-filters input, .srp-filters select { border:1.5px solid #dee2e6; border-radius:.375rem; padding:.35rem .6rem; font-size:.9rem; min-height:38px; }
  .srp-filters input[type=search] { min-width:220px; }
  .srp-filters .grow { flex:1 1 220px; }
  .srp-filters .btn-clear { align-self:flex-end; }
  .srp-empty { color:#6c757d; font-style:italic; padding:.5rem .25rem; }
  .srp-summary { font-size:.85rem; color:#6c757d; margin:-.5rem 0 1rem; }
  tr.srp-hidden, .station-card.srp-hidden { display:none; }
  @media print {
    body{background:#fff;}
    .station-card{box-shadow:none;border:1px solid #ccc;}
    .noprint{display:none;}
    .srp-filters{display:none;}
    h1{margin:0;}
    .station-head, .featured .station-head,
    .status-empty, .status-loaded, .status-loading, .status-unloading, .status-ordered {
      -webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact;
    }
  }
</style>
</head>
<body>
<nav class="navbar navbar-dark station-report-navbar noprint">
  <div class="container-fluid">
    <div class="d-flex flex-wrap align-items-center gap-2 w-100">
      <a class="btn btn-outline-light btn-sm" href="/sts/index.html"><i class="bi bi-house"></i> STS Main Menu</a>
      <a class="btn btn-outline-light btn-sm" href="index.php"><i class="bi bi-calendar-event"></i> Session <?= $session_nbr ?></a>
      <a class="btn btn-outline-light btn-sm" href="wheel_report.html" title="Wheel report"><i class="bi bi-arrow-left-right"></i> Wheel Report</a>
      <button type="button" class="btn btn-outline-light btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
      <a class="btn btn-outline-light btn-sm ms-auto" href="/sts/session-sitemap.html"><i class="bi bi-diagram-3"></i> Site Map</a>
    </div>
  </div>
</nav>
<main>
  <h1>Station Car Report — Start of Session <?= $session_nbr ?></h1>
  <p class="subtitle noprint">Where every car was staged before Session <?= $session_nbr ?> work began, reconstructed from the switch-list archives. <?= $total ?> cars total. Generated <?= htmlspecialchars($generated_at, ENT_QUOTES) ?>.</p>
  <p class="legend noprint"><span class="stay">—</span> = stays put (not on a Session <?= $session_nbr ?> switch list) &nbsp;·&nbsp; <span class="act">Pick up · JOB → DEST</span> = handled on a Session <?= $session_nbr ?> switch list.</p>
  <?= $session_nav ?>
  <div class="srp-filters noprint">
    <div class="field grow">
      <label for="srp-search">Search</label>
      <input type="search" id="srp-search" placeholder="Reporting marks, car code, track…">
    </div>
    <div class="field">
      <label for="srp-station">Station</label>
      <select id="srp-station">
        <option value="">All stations</option>
<?php foreach ($station_order as $name): ?>
        <option value="<?= htmlspecialchars($name, ENT_QUOTES) ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="srp-status">Status</label>
      <select id="srp-status">
        <option value="">Any status</option>
<?php foreach ($statuses as $s): ?>
        <option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>"><?= htmlspecialchars($s, ENT_QUOTES) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="srp-handling">Handling</label>
      <select id="srp-handling">
        <option value="">All cars</option>
        <option value="stay">Staying only</option>
        <option value="work">On a switch list</option>
      </select>
    </div>
    <button type="button" id="srp-clear" class="btn btn-sm btn-outline-secondary btn-clear">Clear</button>
  </div>
  <p class="srp-summary noprint" id="srp-summary"></p>
<?php foreach ($station_order as $name):
    $rows = $by_station[$name];
    $is_featured = in_array($name, $featured, true);
    $stays = count(array_filter($rows, static fn($r) => $r['stays']));
?>
  <div class="station-card<?= $is_featured ? ' featured' : '' ?>" data-station="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
    <div class="station-head">
      <span><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($name, ENT_QUOTES) ?></span>
      <span class="count" data-total="<?= count($rows) ?>" data-stays="<?= $stays ?>"><?= count($rows) ?> car<?= count($rows) === 1 ? '' : 's' ?> · <?= $stays ?> staying</span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover">
        <thead><tr>
          <th>Track</th><th>Reporting Marks</th><th>Car Code</th><th>Status</th><th>Home</th><th>Session <?= $session_nbr ?> action</th>
        </tr></thead>
        <tbody>
<?php foreach ($rows as $r):
    $search = strtolower(trim($r['marks'] . ' ' . $r['car_code'] . ' ' . $r['loc'] . ' ' . $r['action']));
?>
          <tr data-search="<?= htmlspecialchars($search, ENT_QUOTES) ?>" data-status="<?= htmlspecialchars($r['status'], ENT_QUOTES) ?>" data-handling="<?= $r['stays'] ? 'stay' : 'work' ?>">
            <td class="track"><?= htmlspecialchars($r['loc'], ENT_QUOTES) ?></td>
            <td class="marks"><?= htmlspecialchars($r['marks'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['car_code'], ENT_QUOTES) ?></td>
            <td><?= session_station_report_status_html($r['status']) ?></td>
            <td><?= htmlspecialchars($r['home'], ENT_QUOTES) ?></td>
            <td class="<?= $r['stays'] ? 'stay' : 'act' ?>"><?= htmlspecialchars($r['action'], ENT_QUOTES) ?></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>
</main>
<script>
(function () {
  var search = document.getElementById('srp-search');
  var station = document.getElementById('srp-station');
  var status = document.getElementById('srp-status');
  var handling = document.getElementById('srp-handling');
  var clear = document.getElementById('srp-clear');
  var summary = document.getElementById('srp-summary');
  var cards = Array.prototype.slice.call(document.querySelectorAll('.station-card'));

  function apply() {
    var q = (search.value || '').trim().toLowerCase();
    var st = station.value || '';
    var stat = status.value || '';
    var hand = handling.value || '';
    var shownCars = 0, shownStations = 0;

    cards.forEach(function (card) {
      var cardStation = card.getAttribute('data-station');
      var rows = Array.prototype.slice.call(card.querySelectorAll('tbody tr'));
      var visible = 0;
      rows.forEach(function (row) {
        var ok = true;
        if (q && row.getAttribute('data-search').indexOf(q) === -1) { ok = false; }
        if (ok && stat && row.getAttribute('data-status') !== stat) { ok = false; }
        if (ok && hand && row.getAttribute('data-handling') !== hand) { ok = false; }
        row.classList.toggle('srp-hidden', !ok);
        if (ok) { visible++; }
      });
      var stationHidden = (st && cardStation !== st) || visible === 0;
      card.classList.toggle('srp-hidden', stationHidden);
      var countEl = card.querySelector('.count');
      if (countEl) {
        var total = countEl.getAttribute('data-total');
        countEl.textContent = (visible === Number(total))
          ? (total + ' car' + (total === '1' ? '' : 's') + ' · ' + countEl.getAttribute('data-stays') + ' staying')
          : ('showing ' + visible + ' of ' + total);
      }
      if (!stationHidden) { shownStations++; shownCars += visible; }
    });

    var filtered = q || st || stat || hand;
    summary.textContent = filtered
      ? ('Showing ' + shownCars + ' car' + (shownCars === 1 ? '' : 's') + ' across ' + shownStations + ' station' + (shownStations === 1 ? '' : 's') + '.')
      : '';
  }

  [search, station, status, handling].forEach(function (el) {
    el.addEventListener('input', apply);
    el.addEventListener('change', apply);
  });
  clear.addEventListener('click', function () {
    search.value = ''; station.value = ''; status.value = ''; handling.value = '';
    apply();
  });
  apply();
})();
</script>
</body>
</html>
<?php
    return (string) ob_get_clean();
}

/** Relative href from session_N/ to another session's station report. */
function session_station_report_rel_href($session_nbr)
{
    return '../session_' . (int) $session_nbr . '/station_report.html';
}

/**
 * Full session picker for a station report page: skip-to-first, prev, a jump
 * dropdown, next, skip-to-last — mirroring the session overview picker. Nav hrefs
 * use the relative "../session_N/station_report.html" form so so.php rewrites them
 * to flow through the output server; the dropdown navigates via inline JS (not an
 * href/src attribute, so it is left untouched by so.php's link rewriting).
 */
function session_station_report_session_nav_html($session_nbr, $dbc = null, $root = null)
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    if ($session_nbr < 1) {
        return '';
    }
    if ($dbc === null) {
        require_once __DIR__ . '/open_db.php';
        $dbc = open_db();
    }
    $current_db = (int) session_get_db_session($dbc);
    $sessions = session_list_browser_sessions($current_db, $root);
    $prev = session_adjacent_session($sessions, $session_nbr, 'prev');
    $next = session_adjacent_session($sessions, $session_nbr, 'next');
    list($first, $last) = session_edge_sessions($sessions);

    $edge_btn = static function ($target, $icon, $title) {
        if ($target === null) {
            return '<span class="btn btn-outline-primary btn-sm disabled" aria-disabled="true"><i class="bi bi-'
                . $icon . '"></i></span>';
        }

        return '<a class="btn btn-outline-primary btn-sm" href="'
            . htmlspecialchars(session_station_report_rel_href($target)) . '" title="'
            . htmlspecialchars($title, ENT_QUOTES) . '"><i class="bi bi-' . $icon . '"></i></a>';
    };

    $skip_first = ($first !== null && (int) $first !== $session_nbr) ? $first : null;
    $skip_last = ($last !== null && (int) $last !== $session_nbr) ? $last : null;

    $html = '<div class="session-nav-row station-report-session-nav noprint">';
    $html .= '<span class="station-report-nav-label">Session</span>';
    $html .= $edge_btn($skip_first, 'skip-start-fill', $skip_first !== null ? 'First session (' . (int) $skip_first . ')' : 'First session');
    $html .= $edge_btn($prev, 'chevron-left', $prev !== null ? 'Session ' . (int) $prev : 'Previous');
    $html .= '<select class="form-select form-select-sm station-report-jump" aria-label="Jump to session" '
        . 'onchange="if(this.value){window.location.href=\'so.php?f=session_\'+this.value+\'/station_report.html\';}">';
    foreach ($sessions as $n) {
        $n = (int) $n;
        $html .= '<option value="' . $n . '"' . ($n === $session_nbr ? ' selected' : '') . '>'
            . 'Session ' . $n . ($n === $current_db ? ' (current)' : '') . '</option>';
    }
    $html .= '</select>';
    $html .= $edge_btn($next, 'chevron-right', $next !== null ? 'Session ' . (int) $next : 'Next');
    $html .= $edge_btn($skip_last, 'skip-end-fill', $skip_last !== null ? 'Last session (' . (int) $skip_last . ')' : 'Last session');
    $html .= '</div>';

    return $html;
}

/**
 * Build (and cache) the start-of-session station car report. Reconstructs car
 * positions and load status from switch-list archives (read-only). Returns the
 * relative output path, or null when the session directory does not exist.
 */
function session_build_station_report($dbc, $session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    $data = session_station_report_data($dbc, $session_nbr, $root);
    if ($data === null) {
        return null;
    }

    $data['session_nav'] = session_station_report_session_nav_html($session_nbr, $dbc, $root);

    $rel = 'session_' . $session_nbr . '/station_report.html';
    $fs = session_output_fs_path($rel, $root);
    session_ensure_writable_dir(dirname($fs));
    $html = session_station_report_render_html($session_nbr, $data);
    if (file_put_contents($fs, $html) === false) {
        return null;
    }

    return $rel;
}

/* -------------------------------------------------------------------------
 * Wheel report — end-of-session train consists.
 *
 * Mirrors the STS "Reports → Wheel Report" page (cars assigned to each job/
 * train, including cars physically in train and staging outbound awaiting pickup)
 * as a static per-session snapshot.
 *
 * Current DB session: live query (handled_by_job_id), validated against
 * wheel_report.php. Historical sessions: archive reconstruction — cars with
 * current_location_id = 0 on a job switch list are in train; STG-DEMMLER
 * phases titled "D749" / info "Inbound" are staging outbound merged onto D749
 * at Demmler Yard (inbound train for the next session).
 *
 * TODO (deferred, see command catalog): expose a catalog step that builds/
 * pins these station + wheel report snapshots for a session so a run can bake
 * them into the session bundle. session_build_wheel_report()/
 * session_build_station_report() are already single-call, catalog-friendly.
 * ---------------------------------------------------------------------- */

/** L/E column for wheel report rows. */
function session_wheel_report_le_from_status($status)
{
    $status = (string) $status;
    if ($status === 'Loaded') {
        return 'L';
    }
    if ($status === 'Empty' || $status === 'Ordered') {
        return 'E';
    }

    return '';
}

/**
 * Build one wheel-report row from a switch-list archive car record.
 *
 * @param array<string,mixed> $car
 */
function session_wheel_report_row_from_master_car(array $car, $pickup_station = null, $pickup_loc = null)
{
    $marks = (string) ($car['reporting_marks'] ?? ($car[0] ?? ''));
    $status = (string) ($car['status'] ?? ($car[2] ?? ''));
    $commodity = (string) ($car['consignment'] ?? ($car[4] ?? ''));
    if ($status === 'Ordered' && $commodity === '') {
        $commodity = 'Ordered';
    }
    if ($status === 'Ordered') {
        $dest_station = (string) ($car['loading_station'] ?? '');
        $dest_loc = (string) ($car['loading_location'] ?? '');
    } else {
        $dest_station = (string) ($car['unloading_station'] ?? '');
        $dest_loc = (string) ($car['unloading_location'] ?? '');
    }
    if ($pickup_station === null) {
        $pickup_station = (string) ($car['current_station'] ?? ($car[8] ?? ''));
    }
    if ($pickup_loc === null) {
        $pickup_loc = (string) ($car['current_location'] ?? ($car[9] ?? ''));
    }

    return [
        'step' => (int) ($car['step_number'] ?? ($car[16] ?? 0)),
        'marks' => $marks,
        'car_code' => (string) ($car['car_code'] ?? ($car[1] ?? '')),
        'status' => $status,
        'le' => session_wheel_report_le_from_status($status),
        'commodity' => $commodity,
        'pickup_station' => (string) $pickup_station,
        'pickup_loc' => (string) $pickup_loc,
        'dest_station' => $dest_station,
        'dest_loc' => $dest_loc,
    ];
}

/** Assign sequence numbers and sort each job block. */
function session_wheel_report_finish_by_job(array &$by_job)
{
    foreach ($by_job as &$rows) {
        usort($rows, static fn($a, $b) => [$a['step'], $a['pickup_loc'], $a['marks']] <=> [$b['step'], $b['pickup_loc'], $b['marks']]);
        $seq = 1;
        foreach ($rows as &$r) {
            $r['seq'] = $seq++;
        }
        unset($r);
    }
    unset($rows);
}

/**
 * Demmler Yard online staging pickup label (for STG-DEMMLER → D749 inbound rows).
 *
 * @return array{0:string,1:string}
 */
function session_wheel_report_demmler_pickup($dbc)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $station = 'Demmler Yard';
    $code = 'DEMMLER-YARD';
    $rs = mysqli_query(
        $dbc,
        'SELECT r.station, l.code
         FROM locations l
         LEFT JOIN routing r ON r.id = l.station
         WHERE l.code = "DEMMLER-YARD"
         LIMIT 1'
    );
    if ($rs && ($row = mysqli_fetch_assoc($rs))) {
        if (!empty($row['station'])) {
            $station = (string) $row['station'];
        }
        if (!empty($row['code'])) {
            $code = (string) $row['code'];
        }
    }
    $cache = [$station, $code];

    return $cache;
}

/**
 * Destination cell for an Ordered car (mirrors wheel_report.php shipment logic).
 *
 * @return array{0:string,1:string}
 */
function session_wheel_report_ordered_destination($dbc, $shipment_id, $wb_number)
{
    $shipment_id = (int) $shipment_id;
    if ($shipment_id < 1) {
        return ['', ''];
    }
    $empty_move = substr((string) $wb_number, 4, 1) === 'E';
    if ($empty_move) {
        $sql = 'SELECT r.station AS dest_station, l.code AS dest_location
                FROM routing r, locations l, shipments s
                WHERE r.id = l.station AND l.id = s.unloading_location AND s.id = ' . $shipment_id;
    } else {
        $sql = 'SELECT r.station AS dest_station, l.code AS dest_location
                FROM routing r, locations l, shipments s
                WHERE r.id = l.station AND l.id = s.loading_location AND s.id = ' . $shipment_id;
    }
    $rs = mysqli_query($dbc, $sql);
    if (!$rs || !($row = mysqli_fetch_assoc($rs))) {
        return ['', ''];
    }

    return [(string) ($row['dest_station'] ?? ''), (string) ($row['dest_location'] ?? '')];
}

/**
 * Live wheel-report rows for one job (current DB snapshot — matches wheel_report.php).
 *
 * @return list<array>
 */
function session_wheel_report_live_rows_for_job($dbc, $job_name)
{
    $job_name = mysqli_real_escape_string($dbc, (string) $job_name);
    $sql = 'SELECT cars.id AS car_id,
                   cars.reporting_marks AS reporting_marks,
                   car_codes.code AS car_code,
                   cars.status AS status,
                   loc01.code AS pickup_location,
                   IFNULL(sta01.station, "In Train") AS pickup_station,
                   loc02.code AS unloading_location,
                   sta02.station AS unloading_station,
                   commodities.code AS commodity,
                   car_orders.shipment AS shipment_id,
                   car_orders.waybill_number AS wb_number,
                   `' . $job_name . '`.step_number AS job_step
            FROM jobs
            LEFT JOIN cars ON jobs.id = cars.handled_by_job_id
            LEFT JOIN car_codes ON cars.car_code_id = car_codes.id
            LEFT JOIN car_orders ON car_orders.car = cars.id
            LEFT JOIN shipments ON shipments.id = car_orders.shipment
            LEFT JOIN locations loc01 ON cars.current_location_id = loc01.id
            LEFT JOIN routing sta01 ON loc01.station = sta01.id
            LEFT JOIN locations loc02 ON shipments.unloading_location = loc02.id
            LEFT JOIN routing sta02 ON loc02.station = sta02.id
            LEFT JOIN commodities ON commodities.id = shipments.consignment
            LEFT JOIN `' . $job_name . '` ON `' . $job_name . '`.station = sta01.id
            WHERE jobs.name = "' . $job_name . '"
            GROUP BY car_id, reporting_marks, car_code, status, pickup_location,
                     pickup_station, unloading_location, unloading_station,
                     commodity, shipment_id, wb_number, job_step
            ORDER BY job_step, pickup_location, reporting_marks';

    $rows = [];
    $seen = [];
    $rs = mysqli_query($dbc, $sql);
    if (!$rs) {
        return $rows;
    }
    while ($row = mysqli_fetch_assoc($rs)) {
        if ((int) ($row['car_id'] ?? 0) < 1) {
            continue;
        }
        $marks = (string) ($row['reporting_marks'] ?? '');
        if ($marks === '' || isset($seen[$marks])) {
            continue;
        }
        $seen[$marks] = true;
        $status = (string) ($row['status'] ?? '');
        if ($status === 'Ordered') {
            $commodity = 'Ordered';
            list($dest_station, $dest_loc) = session_wheel_report_ordered_destination(
                $dbc,
                (int) ($row['shipment_id'] ?? 0),
                (string) ($row['wb_number'] ?? '')
            );
        } else {
            $commodity = (string) ($row['commodity'] ?? '');
            $dest_station = (string) ($row['unloading_station'] ?? '');
            $dest_loc = (string) ($row['unloading_location'] ?? '');
        }
        $rows[] = [
            'step' => (int) ($row['job_step'] ?? 0),
            'marks' => (string) ($row['reporting_marks'] ?? ''),
            'car_code' => (string) ($row['car_code'] ?? ''),
            'status' => $status,
            'le' => session_wheel_report_le_from_status($status),
            'commodity' => $commodity,
            'pickup_station' => (string) ($row['pickup_station'] ?? ''),
            'pickup_loc' => (string) ($row['pickup_location'] ?? ''),
            'dest_station' => $dest_station,
            'dest_loc' => $dest_loc,
        ];
    }

    return $rows;
}

/**
 * Live DB wheel report (used for the current operating session).
 *
 * @return array{by_job: array<string,list<array>>, job_order: list<string>, total: int, live: bool}
 */
function session_wheel_report_data_live($dbc)
{
    $by_job = [];
    $rs = mysqli_query($dbc, 'SELECT name FROM jobs ORDER BY name');
    while ($rs && ($row = mysqli_fetch_assoc($rs))) {
        $job = (string) ($row['name'] ?? '');
        if ($job === '') {
            continue;
        }
        $by_job[$job] = session_wheel_report_live_rows_for_job($dbc, $job);
    }
    session_wheel_report_finish_by_job($by_job);
    $job_order = array_keys($by_job);
    sort($job_order, SORT_NATURAL | SORT_FLAG_CASE);
    $total = 0;
    foreach ($by_job as $rows) {
        $total += count($rows);
    }

    return [
        'by_job' => $by_job,
        'job_order' => $job_order,
        'total' => $total,
        'live' => true,
    ];
}

/**
 * STG-DEMMLER "D749 · Inbound" archive rows → D749 cars at Demmler after staging.
 *
 * @return list<array>
 */
function session_wheel_report_stg_d749_inbound_rows($dbc, $session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    list($dem_station, $dem_loc) = session_wheel_report_demmler_pickup($dbc);
    $rows = [];
    $seen = [];
    foreach (glob(session_dir_for($session_nbr, $root) . '/phase_*/*STG-DEMMLER*_master.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if (!is_array($d) || ($d['job'] ?? '') !== 'STG-DEMMLER') {
            continue;
        }
        if ((string) ($d['title'] ?? '') !== 'D749' || (string) ($d['info'] ?? '') !== 'Inbound') {
            continue;
        }
        foreach (($d['sections'] ?? []) as $sec) {
            foreach (($sec['cars'] ?? []) as $car) {
                $marks = (string) ($car['reporting_marks'] ?? ($car[0] ?? ''));
                if ($marks === '' || isset($seen[$marks])) {
                    continue;
                }
                $seen[$marks] = true;
                $rows[] = session_wheel_report_row_from_master_car($car, $dem_station, $dem_loc);
            }
        }
    }

    return $rows;
}

/**
 * Reconstruct end-of-session wheel report from switch-list archives.
 *
 * @return array{by_job: array<string,list<array>>, job_order: list<string>, total: int, live: bool}
 */
function session_wheel_report_data_archived($dbc, $session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    $dir = session_dir_for($session_nbr, $root);
    $by_job = [];
    $seen = [];

    foreach (glob($dir . '/phase_*/*_master.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if (!is_array($d) || empty($d['sections'])) {
            continue;
        }
        $job = trim((string) ($d['job'] ?? ''));
        if ($job === '' || $job === 'STG-DEMMLER' || $job === 'STG-SCULLY') {
            continue;
        }
        foreach ($d['sections'] as $sec) {
            foreach (($sec['cars'] ?? []) as $car) {
                $marks = (string) ($car['reporting_marks'] ?? ($car[0] ?? ''));
                $loc_id = (int) ($car['current_location_id'] ?? ($car[14] ?? 0));
                if ($marks === '' || $loc_id !== 0) {
                    continue;
                }
                if (isset($seen[$job][$marks])) {
                    continue;
                }
                $seen[$job][$marks] = true;
                $row = session_wheel_report_row_from_master_car($car, 'In Train', '');
                $by_job[$job][] = $row;
            }
        }
    }

    foreach (session_wheel_report_stg_d749_inbound_rows($dbc, $session_nbr, $root) as $row) {
        $marks = (string) $row['marks'];
        if ($marks === '' || isset($seen['D749'][$marks])) {
            continue;
        }
        $seen['D749'][$marks] = true;
        $by_job['D749'][] = $row;
    }

    session_wheel_report_finish_by_job($by_job);
    $job_order = array_keys($by_job);
    sort($job_order, SORT_NATURAL | SORT_FLAG_CASE);
    $total = 0;
    foreach ($by_job as $rows) {
        $total += count($rows);
    }

    return [
        'by_job' => $by_job,
        'job_order' => $job_order,
        'total' => $total,
        'live' => false,
    ];
}

/** True when a cached wheel_report.html should be rebuilt (same triggers as station report). */
function session_wheel_report_stale($session_nbr, $report_fs, $root = null)
{
    return session_station_report_stale($session_nbr, $report_fs, $root);
}

/**
 * Assemble per-train car rows for the end-of-session wheel report.
 *
 * @return array{by_job: array<string,list<array>>, job_order: list<string>, total: int, live?: bool}|null
 */
function session_wheel_report_data($dbc, $session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    if ($session_nbr < 1 || !is_dir(session_dir_for($session_nbr, $root))) {
        return null;
    }
    $current_db = (int) session_get_db_session($dbc);
    if ($session_nbr === $current_db) {
        return session_wheel_report_data_live($dbc);
    }

    return session_wheel_report_data_archived($dbc, $session_nbr, $root);
}

/** Relative href from session_N/ to another session's wheel report. */
function session_wheel_report_rel_href($session_nbr)
{
    return '../session_' . (int) $session_nbr . '/wheel_report.html';
}

/** Full session picker for the wheel report (mirrors the station report picker). */
function session_wheel_report_session_nav_html($session_nbr, $dbc = null, $root = null)
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    if ($session_nbr < 1) {
        return '';
    }
    if ($dbc === null) {
        require_once __DIR__ . '/open_db.php';
        $dbc = open_db();
    }
    $current_db = (int) session_get_db_session($dbc);
    $sessions = session_list_browser_sessions($current_db, $root);
    $prev = session_adjacent_session($sessions, $session_nbr, 'prev');
    $next = session_adjacent_session($sessions, $session_nbr, 'next');
    list($first, $last) = session_edge_sessions($sessions);

    $edge_btn = static function ($target, $icon, $title) {
        if ($target === null) {
            return '<span class="btn btn-outline-primary btn-sm disabled" aria-disabled="true"><i class="bi bi-'
                . $icon . '"></i></span>';
        }

        return '<a class="btn btn-outline-primary btn-sm" href="'
            . htmlspecialchars(session_wheel_report_rel_href($target)) . '" title="'
            . htmlspecialchars($title, ENT_QUOTES) . '"><i class="bi bi-' . $icon . '"></i></a>';
    };

    $skip_first = ($first !== null && (int) $first !== $session_nbr) ? $first : null;
    $skip_last = ($last !== null && (int) $last !== $session_nbr) ? $last : null;

    $html = '<div class="session-nav-row wheel-report-session-nav noprint">';
    $html .= '<span class="station-report-nav-label">Session</span>';
    $html .= $edge_btn($skip_first, 'skip-start-fill', $skip_first !== null ? 'First session (' . (int) $skip_first . ')' : 'First session');
    $html .= $edge_btn($prev, 'chevron-left', $prev !== null ? 'Session ' . (int) $prev : 'Previous');
    $html .= '<select class="form-select form-select-sm station-report-jump" aria-label="Jump to session" '
        . 'onchange="if(this.value){window.location.href=\'so.php?f=session_\'+this.value+\'/wheel_report.html\';}">';
    foreach ($sessions as $n) {
        $n = (int) $n;
        $html .= '<option value="' . $n . '"' . ($n === $session_nbr ? ' selected' : '') . '>'
            . 'Session ' . $n . ($n === $current_db ? ' (current)' : '') . '</option>';
    }
    $html .= '</select>';
    $html .= $edge_btn($next, 'chevron-right', $next !== null ? 'Session ' . (int) $next : 'Next');
    $html .= $edge_btn($skip_last, 'skip-end-fill', $skip_last !== null ? 'Last session (' . (int) $skip_last . ')' : 'Last session');
    $html .= '</div>';

    return $html;
}

/**
 * Render the wheel report HTML for a session.
 *
 * @param array{by_job: array, job_order: list<string>, total: int} $data
 */
function session_wheel_report_render_html($session_nbr, array $data)
{
    $session_nbr = (int) $session_nbr;
    $by_job = $data['by_job'];
    $job_order = $data['job_order'];
    $total = (int) $data['total'];
    $session_nav = (string) ($data['session_nav'] ?? '');
    $generated_at = date('M j, Y g:i A');
    $is_live = !empty($data['live']);
    $job_order = array_values(array_filter($job_order, static fn($j) => !empty($by_job[$j])));
    $train_count = count($job_order);
    $total = 0;
    foreach ($job_order as $j) {
        $total += count($by_job[$j]);
    }

    ob_start();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Wheel Report — Start of Session <?= $session_nbr ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background:#f8f9fa; font-size:13px; }
  main { max-width: 1100px; margin: 0 auto; padding: 1rem 1rem 2.5rem; }
  h1 { font-size: 1.15rem; font-weight: 600; margin-bottom:.5rem; }
  .subtitle { color:#6c757d; font-size:.8rem; margin-bottom:.5rem; }
  .train-card { background:#fff; border-radius:.5rem; box-shadow:0 2px 6px rgba(0,0,0,.08); margin-bottom:1rem; overflow:hidden; }
  .train-head { display:flex; justify-content:space-between; align-items:center;
    padding:.5rem .85rem; font-weight:600; font-size:.9rem; color:#fff;
    background:linear-gradient(135deg,#0d6efd 0%,#0a58ca 100%); }
  .train-head .desc { font-weight:500; font-size:.78rem; opacity:.92; }
  .train-head .count { font-weight:500; font-size:.8rem; opacity:.9; }
  table { margin:0; font-size:.8rem; }
  th { font-size:.68rem; text-transform:uppercase; letter-spacing:.03em; color:#495057; }
  td, th { padding:.3rem .6rem !important; vertical-align:middle; }
  .track { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:.78rem; color:#0a58ca; }
  .marks { font-weight:600; }
  .station-name { font-weight:600; }
  .le-l { display:inline-block; background:#a8e6cf; color:#123; padding:1px 7px; border-radius:3px; font-weight:700; font-size:.72rem; }
  .le-e { display:inline-block; background:#ffeaa7; color:#332; padding:1px 7px; border-radius:3px; font-weight:700; font-size:.72rem; }
  .station-report-navbar { background:linear-gradient(135deg,#0d6efd 0%,#0a58ca 100%); }
  .station-report-navbar .btn-outline-light { border-color:rgba(255,255,255,.65); }
  .wr-train-select { max-width:180px; }
  .session-nav-row { display:flex; align-items:center; flex-wrap:wrap; gap:.4rem; margin-bottom:1.1rem; }
  .station-report-nav-label { font-size:.75rem; text-transform:uppercase; letter-spacing:.03em; color:#6c757d; font-weight:600; margin-right:.15rem; }
  .station-report-jump { max-width:230px; }
  .wrp-filters { display:flex; flex-wrap:wrap; gap:.6rem; align-items:flex-end; background:#fff;
    border-radius:.5rem; box-shadow:0 2px 6px rgba(0,0,0,.08); padding:.85rem 1rem; margin-bottom:1.25rem; }
  .wrp-filters .field { display:flex; flex-direction:column; gap:.2rem; }
  .wrp-filters label { font-size:.7rem; text-transform:uppercase; letter-spacing:.03em; color:#6c757d; font-weight:600; }
  .wrp-filters input, .wrp-filters select { border:1.5px solid #dee2e6; border-radius:.375rem; padding:.35rem .6rem; font-size:.9rem; min-height:38px; }
  .wrp-filters input[type=search] { min-width:220px; }
  .wrp-filters .grow { flex:1 1 220px; }
  .wrp-empty { color:#6c757d; font-style:italic; padding:.5rem .25rem; }
  .wrp-summary { font-size:.85rem; color:#6c757d; margin:-.5rem 0 1rem; }
  .no-cars { background:#fff; border-radius:.5rem; box-shadow:0 2px 6px rgba(0,0,0,.08); padding:1.25rem; color:#6c757d; }
  tr.wrp-hidden, .train-card.wrp-hidden { display:none; }
  @media print {
    body{background:#fff;}
    .train-card{box-shadow:none;border:1px solid #ccc;}
    .noprint{display:none;}
    .wrp-filters{display:none;}
    h1{margin:0;}
    .train-head, .le-l, .le-e {
      -webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact;
    }
  }
</style>
</head>
<body>
<nav class="navbar navbar-dark station-report-navbar noprint">
  <div class="container-fluid">
    <div class="d-flex flex-wrap align-items-center gap-2 w-100">
      <a class="btn btn-outline-light btn-sm" href="/sts/index.html"><i class="bi bi-house"></i> STS Main Menu</a>
      <a class="btn btn-outline-light btn-sm" href="index.php"><i class="bi bi-calendar-event"></i> Session <?= $session_nbr ?></a>
      <a class="btn btn-outline-light btn-sm" href="station_report.html" title="Station car report"><i class="bi bi-arrow-left-right"></i> Station Report</a>
      <button type="button" class="btn btn-outline-light btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
      <select class="form-select form-select-sm wr-train-select ms-auto" id="wr-train" aria-label="Train">
        <option value="">All trains</option>
<?php foreach ($job_order as $job): ?>
        <option value="<?= htmlspecialchars($job, ENT_QUOTES) ?>"><?= htmlspecialchars($job, ENT_QUOTES) ?></option>
<?php endforeach; ?>
      </select>
      <a class="btn btn-outline-light btn-sm" href="/sts/session-sitemap.html"><i class="bi bi-diagram-3"></i> Site Map</a>
    </div>
  </div>
</nav>
<main>
  <h1>Wheel Report — Start of Session <?= $session_nbr ?></h1>
  <p class="subtitle noprint">Cars assigned to each train/job at the start of Session <?= $session_nbr ?><?= $is_live ? ' (live database snapshot)' : ', reconstructed from switch-list archives' ?>. <?= $total ?> car<?= $total === 1 ? '' : 's' ?> across <?= $train_count ?> train<?= $train_count === 1 ? '' : 's' ?>. Generated <?= htmlspecialchars($generated_at, ENT_QUOTES) ?>.</p>
  <?= $session_nav ?>
<?php if ($train_count === 0): ?>
  <div class="no-cars">No cars were assigned to any train at the start of Session <?= $session_nbr ?>.</div>
<?php else: ?>
  <div class="wrp-filters noprint">
    <div class="field grow">
      <label for="wrp-search">Search</label>
      <input type="search" id="wrp-search" placeholder="Reporting marks, car code, commodity, track…">
    </div>
    <div class="field">
      <label for="wrp-le">Load</label>
      <select id="wrp-le">
        <option value="">Loaded &amp; empty</option>
        <option value="L">Loaded only</option>
        <option value="E">Empty only</option>
      </select>
    </div>
    <button type="button" id="wrp-clear" class="btn btn-sm btn-outline-secondary">Clear</button>
  </div>
  <p class="wrp-summary noprint" id="wrp-summary"></p>
<?php foreach ($job_order as $job):
    $rows = $by_job[$job];
    $loads = count(array_filter($rows, static fn($r) => $r['le'] === 'L'));
    $empties = count(array_filter($rows, static fn($r) => $r['le'] === 'E'));
?>
  <div class="train-card" data-job="<?= htmlspecialchars($job, ENT_QUOTES) ?>">
    <div class="train-head">
      <span><i class="bi bi-train-freight"></i> <?= htmlspecialchars($job, ENT_QUOTES) ?></span>
      <span class="count" data-total="<?= count($rows) ?>"><?= $loads ?> L · <?= $empties ?> E · <?= count($rows) ?> car<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover">
        <thead><tr>
          <th>Seq</th><th>Reporting Marks</th><th>Type</th><th>L/E</th><th>Commodity</th><th>Pick Up At</th><th>Destination</th>
        </tr></thead>
        <tbody>
<?php foreach ($rows as $r):
    $search = strtolower(trim($r['marks'] . ' ' . $r['car_code'] . ' ' . $r['commodity'] . ' ' . $r['pickup_loc'] . ' ' . $r['dest_loc']));
    $le_html = $r['le'] === 'L' ? '<span class="le-l">L</span>' : ($r['le'] === 'E' ? '<span class="le-e">E</span>' : '');
?>
          <tr data-search="<?= htmlspecialchars($search, ENT_QUOTES) ?>" data-le="<?= htmlspecialchars($r['le'], ENT_QUOTES) ?>">
            <td class="text-center"><?= (int) $r['seq'] ?></td>
            <td class="marks"><?= htmlspecialchars($r['marks'], ENT_QUOTES) ?></td>
            <td class="text-center"><?= htmlspecialchars($r['car_code'], ENT_QUOTES) ?></td>
            <td class="text-center"><?= $le_html ?></td>
            <td><?= htmlspecialchars($r['commodity'], ENT_QUOTES) ?></td>
            <td><span class="station-name"><?= htmlspecialchars($r['pickup_station'], ENT_QUOTES) ?></span><?php if ($r['pickup_loc'] !== ''): ?><br><span class="track"><?= htmlspecialchars($r['pickup_loc'], ENT_QUOTES) ?></span><?php endif; ?></td>
            <td><span class="station-name"><?= htmlspecialchars($r['dest_station'], ENT_QUOTES) ?></span><?php if ($r['dest_loc'] !== ''): ?><br><span class="track"><?= htmlspecialchars($r['dest_loc'], ENT_QUOTES) ?></span><?php endif; ?></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>
<?php endif; ?>
</main>
<script>
(function () {
  var search = document.getElementById('wrp-search');
  var le = document.getElementById('wrp-le');
  var train = document.getElementById('wr-train');
  var clear = document.getElementById('wrp-clear');
  var summary = document.getElementById('wrp-summary');
  if (!train) { return; }
  var cards = Array.prototype.slice.call(document.querySelectorAll('.train-card'));

  function apply() {
    var q = (search && search.value || '').trim().toLowerCase();
    var l = (le && le.value) || '';
    var t = train.value || '';
    var shownCars = 0, shownTrains = 0;

    cards.forEach(function (card) {
      var cardJob = card.getAttribute('data-job');
      var rows = Array.prototype.slice.call(card.querySelectorAll('tbody tr'));
      var visible = 0;
      rows.forEach(function (row) {
        var ok = true;
        if (q && row.getAttribute('data-search').indexOf(q) === -1) { ok = false; }
        if (ok && l && row.getAttribute('data-le') !== l) { ok = false; }
        row.classList.toggle('wrp-hidden', !ok);
        if (ok) { visible++; }
      });
      var cardHidden = (t && cardJob !== t) || visible === 0;
      card.classList.toggle('wrp-hidden', cardHidden);
      var countEl = card.querySelector('.count');
      if (countEl) {
        var total = countEl.getAttribute('data-total');
        if (visible !== Number(total)) { countEl.setAttribute('data-filtered', '1'); }
      }
      if (!cardHidden) { shownTrains++; shownCars += visible; }
    });

    var filtered = q || l || t;
    summary.textContent = filtered
      ? ('Showing ' + shownCars + ' car' + (shownCars === 1 ? '' : 's') + ' across ' + shownTrains + ' train' + (shownTrains === 1 ? '' : 's') + '.')
      : '';
  }

  [search, le, train].forEach(function (el) {
    if (!el) { return; }
    el.addEventListener('input', apply);
    el.addEventListener('change', apply);
  });
  if (clear) {
    clear.addEventListener('click', function () {
      if (search) { search.value = ''; }
      if (le) { le.value = ''; }
      train.value = '';
      apply();
    });
  }
  apply();
})();
</script>
</body>
</html>
<?php
    return (string) ob_get_clean();
}

/**
 * Build (and cache) the start-of-session wheel report. Reconstructs train
 * consists from the switch-list archives (read-only). Returns the relative
 * output path, or null when the session directory does not exist.
 */
function session_build_wheel_report($dbc, $session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    $data = session_wheel_report_data($dbc, $session_nbr, $root);
    if ($data === null) {
        return null;
    }

    $data['session_nav'] = session_wheel_report_session_nav_html($session_nbr, $dbc, $root);

    $rel = 'session_' . $session_nbr . '/wheel_report.html';
    $fs = session_output_fs_path($rel, $root);
    session_ensure_writable_dir(dirname($fs));
    $html = session_wheel_report_render_html($session_nbr, $data);
    if (file_put_contents($fs, $html) === false) {
        return null;
    }

    return $rel;
}
