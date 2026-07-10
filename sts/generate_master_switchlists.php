#!/usr/bin/env php
<?php
/**
 * CLI: generate master (multi-phase) switch lists for phased local jobs.
 *
 * Usage:
 *   php generate_master_switchlists.php [--format=halfsheet|mobile] [--render-only] [--save-cache-only]
 *       [--session=N] [--jobs=D749,NVL,CK1] [--output=DIR]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the command line only.\n");
    exit(1);
}

chdir(__DIR__);
require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/master_switchlist_helpers.php';

$options = getopt('', ['session::', 'jobs::', 'output::', 'format::', 'render-only', 'save-cache-only', 'from-halfsheet', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php generate_master_switchlists.php [--format=halfsheet|mobile|phased|phased-mobile] [--render-only] [--from-halfsheet] [--save-cache-only]\n");
    exit(0);
}

$job_names = ['D749', 'NVL', 'CK1'];
if (!empty($options['jobs'])) {
    $job_names = array_values(array_filter(array_map('trim', explode(',', $options['jobs']))));
}

$format = $options['format'] ?? 'halfsheet';
if (!in_array($format, ['halfsheet', 'mobile', 'phased', 'phased-mobile'], true)) {
    fwrite(STDERR, "Unknown format: {$format}\n");
    exit(1);
}

$output_dir = $options['output'] ?? (__DIR__ . '/../../switchlists');
$config = warm_start_merge_config([]);

$dbc = open_db();
$session_nbr = master_sw_get_setting($dbc, 'session_nbr');
if (isset($options['session']) && (int) $options['session'] !== (int) $session_nbr) {
    fwrite(STDERR, "Warning: DB is session {$session_nbr}, not {$options['session']}.\n");
}

$session_output_dir = rtrim($output_dir, '/') . '/session_' . $session_nbr;
$written = master_sw_generate_for_jobs($dbc, $job_names, $session_output_dir, $config, [
    'format' => $format,
    'render_only' => isset($options['render-only']),
    'from_halfsheet' => isset($options['from-halfsheet']),
    'save_cache_only' => isset($options['save-cache-only']),
]);

$mode = isset($options['render-only']) ? 'render' : (isset($options['save-cache-only']) ? 'cache' : 'generate');
fwrite(STDOUT, "=== Master switch lists ({$mode}, {$format}) — session {$session_nbr} ===" . PHP_EOL);
if (count($written) === 0) {
    fwrite(STDERR, "No switch lists generated.\n");
    mysqli_close($dbc);
    exit(1);
}

foreach ($written as $item) {
    if (!empty($item['cache_only'])) {
        fwrite(STDOUT, sprintf(
            "%s: %d phase(s), %d car row(s) → cache %s\n",
            $item['job'],
            $item['phases'],
            $item['cars'],
            $item['path']
        ));
        continue;
    }
    fwrite(STDOUT, sprintf(
        "%s: %d phase(s), %d car row(s) → %s\n",
        $item['job'],
        $item['phases'],
        $item['cars'],
        $item['path']
    ));
}

mysqli_close($dbc);
exit(0);
