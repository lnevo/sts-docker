#!/usr/bin/env php
<?php
/**
 * CLI: simulate several STS operating sessions and optionally save a warm-start backup.
 *
 * Usage:
 *   php simulate_warm_start.php [--sessions=N] [--seed=N] [--backup=NAME] [--continue]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the command line only.\n");
    exit(1);
}

chdir(__DIR__);
require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/warm_start_helpers.php';

$options = getopt('', ['sessions::', 'min-sessions::', 'max-sessions::', 'seed::', 'backup::', 'continue', 'ck1-test', 'tracked', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php simulate_warm_start.php [--min-sessions=3] [--max-sessions=12] [--seed=42] [--backup=NAME] [--continue] [--ck1-test] [--tracked]\n");
    exit(0);
}

$overrides = [];
if (isset($options['sessions'])) {
    $overrides['min_sessions'] = (int) $options['sessions'];
}
if (isset($options['min-sessions'])) {
    $overrides['min_sessions'] = (int) $options['min-sessions'];
}
if (isset($options['max-sessions'])) {
    $overrides['max_sessions'] = (int) $options['max-sessions'];
}
if (isset($options['seed'])) {
    $overrides['seed'] = (int) $options['seed'];
}
if (isset($options['continue'])) {
    $overrides['continue_from_current'] = true;
    $overrides['reseed'] = false;
}
if (isset($options['ck1-test'])) {
    $overrides['stop_when_staging_ready'] = false;
    $overrides['run_ck1_test'] = true;
}
if (isset($options['tracked'])) {
    $overrides['stop_when_stg_scully_ready'] = true;
    $overrides['stop_when_locals_secured'] = false;
    $overrides['stop_when_staging_ready'] = false;
    $overrides['run_ck1_each_session'] = true;
    $overrides['secure_locals_each_session'] = true;
    $overrides['staging_after_locals'] = true;
    $overrides['run_phased_locals'] = true;
    if (!isset($overrides['max_sessions'])) {
        $overrides['max_sessions'] = 10;
    }
}
$config = warm_start_merge_config($overrides);

$dbc = open_db();
$summary = warm_start_run($dbc, $config);

warm_start_log('');
warm_start_log('=== Warm start summary ===');
warm_start_log('Session: ' . $summary['session']);
warm_start_log('Orders: ' . $summary['orders_total'] . ' (' . $summary['orders_unfilled'] . ' unfilled)');
warm_start_log('Awaiting job assignment: ' . $summary['awaiting_assignment']);
warm_start_log('Assigned, pending pickup: ' . $summary['pending_pickup']);
warm_start_log('Staging eligible (ready to run): ' . ($summary['staging_backlog']['eligible'] ?? 0));
warm_start_log('Staging on jobs: ' . ($summary['staging_backlog']['on_jobs'] ?? 0));
if (!empty($summary['stopped_at_stg_scully'])) {
    warm_start_log(
        'STG-SCULLY backlog: '
        . ($summary['stg_scully_backlog']['eligible'] ?? 0)
        . ' eligible at Scully, '
        . ($summary['stg_scully_backlog']['on_jobs'] ?? 0)
        . ' on job'
    );
}
warm_start_log('Non-staging cars still on jobs: ' . ($summary['non_staging_on_jobs'] ?? 0));
warm_start_log('Cars on Neville Island: ' . ($summary['island_cars'] ?? 0));
warm_start_log('Cars at South Yard: ' . ($summary['south_yard_cars'] ?? 0));
warm_start_log('Offline cars ready for load/unload: ' . ($summary['offline_ready'] ?? 0));
warm_start_log('Open reposition (E) orders: ' . ($summary['reposition_orders'] ?? 0));
warm_start_log('Coke customer deliveries (total): ' . ($summary['coke_complete_deliveries'] ?? 0));
warm_start_log('Coke scale reloads (total): ' . ($summary['coke_reloads'] ?? 0));
warm_start_log('Coke weighed: ' . ($summary['coke_weighed'] ?? 0));
warm_start_log('Coke outbound (in-tolerance at scale): ' . ($summary['coke_outbound_assignments'] ?? 0));
warm_start_log('CK1 outbound coke on train: ' . ($summary['ck1_outbound_on_train'] ?? 0));
warm_start_log('In train (assigned): ' . $summary['in_train_assigned']);
warm_start_log('Empties off-home (no order): ' . $summary['empties_off_home']);
foreach ($summary['cars_by_status'] as $status => $count) {
    warm_start_log("  {$status}: {$count}");
}

if (!empty($summary['weigh_failed'])) {
    warm_start_log('');
    warm_start_log('Simulation stopped: CK1 weigh failed.');
    if (isset($options['backup']) && $options['backup'] !== '') {
        fwrite(STDERR, "Warning: backup will still be written despite weigh failure.\n");
    }
}

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
exit(!empty($summary['weigh_failed']) ? 1 : 0);
