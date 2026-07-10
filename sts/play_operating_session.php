#!/usr/bin/env php
<?php
/**
 * CLI: play through one operating session at the current session number.
 *
 * Use after begin_session has opened the session (STG-SCULLY, fill, assign).
 * Runs D749/NVL/CK1, STG-DEMMLER bookend, and leaves STG-SCULLY backlog for the next begin_session.
 *
 * Usage:
 *   php play_operating_session.php [--backup=NAME]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the command line only.\n");
    exit(1);
}

chdir(__DIR__);
require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/warm_start_helpers.php';

$options = getopt('', ['backup::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php play_operating_session.php [--backup=NAME]\n");
    exit(0);
}

$dbc = open_db();
$session_before = warm_start_get_session($dbc);
warm_start_log('Playing operating session ' . $session_before);

$result = warm_start_play_operating_session($dbc);
$config = warm_start_merge_config(warm_start_tracked_sim_overrides());
$summary = warm_start_summarize($dbc);
$scully_backlog = warm_start_staging_backlog_for_job($dbc, 'STG-SCULLY', $config);

warm_start_log('');
warm_start_log('=== Play session summary ===');
warm_start_log('Session: ' . $summary['session']);
warm_start_log('Orders: ' . $summary['orders_total'] . ' (' . $summary['orders_unfilled'] . ' unfilled)');
warm_start_log('Awaiting job assignment: ' . $summary['awaiting_assignment']);
warm_start_log('STG-SCULLY eligible at Scully: ' . ($scully_backlog['eligible'] ?? 0));
warm_start_log('STG-SCULLY on job: ' . ($scully_backlog['on_jobs'] ?? 0));
warm_start_log(
    'Ops: filled=' . (int) ($result['filled'] ?? 0)
    . ' assigned=' . (int) ($result['assigned'] ?? 0)
    . ' pickup=' . (int) ($result['picked_up'] ?? 0)
    . ' setout=' . (int) ($result['set_out'] ?? 0)
);

if (isset($options['backup']) && $options['backup'] !== '') {
    $backup_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $options['backup']);
    if ($backup_name === '') {
        fwrite(STDERR, "Invalid backup name.\n");
        exit(1);
    }
    $path = warm_start_backup($dbc, $backup_name);
    warm_start_log('');
    warm_start_log('Backup written: ' . $path);
}

mysqli_close($dbc);
exit(0);
