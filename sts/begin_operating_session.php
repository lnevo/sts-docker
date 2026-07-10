#!/usr/bin/env php
<?php
/**
 * CLI: begin a live operating session from warm-start end state.
 *
 * After manual STG-SCULLY swap: load/unload, increment session, fill unfilled
 * orders, reposition empties, auto-assign jobs.
 *
 * Usage:
 *   php begin_operating_session.php [--run-stg-scully] [--no-increment] [--no-reposition]
 *       [--no-assign] [--no-fill] [--no-load-unload] [--repo-fraction=N] [--fill-fraction=N]
 *       [--generate] [--backup=NAME]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the command line only.\n");
    exit(1);
}

chdir(__DIR__);
require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/warm_start_helpers.php';

$options = getopt('', [
    'run-stg-scully',
    'no-increment',
    'no-reposition',
    'no-assign',
    'no-fill',
    'no-load-unload',
    'repo-fraction::',
    'fill-fraction::',
    'generate',
    'backup::',
    'help',
]);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<HELP
Usage: php begin_operating_session.php [options]

Begin a live operating session from warm-start end state.

  --run-stg-scully       Run STG-SCULLY staging before session prep
  --no-increment         Keep current session number
  --no-load-unload       Skip offline load/unload transitions
  --no-fill              Skip filling unfilled car orders (default: fill all eligible)
  --no-reposition        Skip empty reposition orders
  --no-assign            Skip auto-assign
  --repo-fraction=N      Reposition fraction (default from warm_start config)
  --fill-fraction=N      Fraction of unfilled orders to fill (default 1.0)
  --generate             Generate revenue orders for the new session
  --backup=NAME          Save backup after setup
  --help                 Show this help

Prerequisites:
  - Warm-start end state loaded (e.g. hart_warm_start)
  - STG-SCULLY complete (manually in STS, or use --run-stg-scully)

HELP);
    exit(0);
}

$session_options = [
    'run_stg_scully' => isset($options['run-stg-scully']),
    'load_unload' => !isset($options['no-load-unload']),
    'increment' => !isset($options['no-increment']),
    'reposition' => !isset($options['no-reposition']),
    'assign' => !isset($options['no-assign']),
    'fill' => !isset($options['no-fill']),
    'generate' => isset($options['generate']),
];
if (isset($options['repo-fraction'])) {
    $session_options['reposition_fraction'] = (float) $options['repo-fraction'];
}
if (isset($options['fill-fraction'])) {
    $session_options['fill_fraction'] = (float) $options['fill-fraction'];
}

$dbc = open_db();
$result = warm_start_begin_operating_session($dbc, $session_options);

fwrite(STDOUT, warm_start_format_begin_session_report($result) . PHP_EOL);

if (!empty($result['blocked'])) {
    mysqli_close($dbc);
    exit(1);
}

if (isset($options['backup']) && $options['backup'] !== '') {
    $backup_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $options['backup']);
    if ($backup_name === '') {
        fwrite(STDERR, "Invalid backup name.\n");
        mysqli_close($dbc);
        exit(1);
    }
    $path = warm_start_backup($dbc, $backup_name);
    fwrite(STDOUT, PHP_EOL . 'Backup written: ' . $path . PHP_EOL);
}

mysqli_close($dbc);
exit(0);
