<?php
/**
 * Task kind warm_start: warm_start_run(), modelled on the sts-helpers CLI simulate_warm_start.php.
 * Spec: sessions, max_sessions, seed, continue, tracked, config (array of extra overrides), backup.
 * "tracked" asks the registered warm-start scenario for its 'tracked_overrides'.
 */
function api_task_kind_warm_start(array $spec, callable $log)
{
    $sts = dirname(__DIR__, 2);
    require_once $sts . '/open_db.php';
    require_once $sts . '/warm_start_helpers.php';
    chdir($sts);

    $overrides = [];
    if (!empty($spec['config']) && is_array($spec['config'])) {
        $overrides = $spec['config'];
    }
    if (isset($spec['sessions'])) {
        $overrides['min_sessions'] = (int) $spec['sessions'];
    }
    if (isset($spec['max_sessions'])) {
        $overrides['max_sessions'] = (int) $spec['max_sessions'];
    }
    $continue = !empty($spec['continue']);
    if ($continue) {
        $overrides['continue_from_current'] = true;
        $overrides['reseed'] = false;
    } elseif (isset($spec['seed'])) {
        $overrides['seed'] = (int) $spec['seed'];
    }
    if (!empty($spec['tracked'])) {
        $tracked = plugins_scenario_call('tracked_overrides', [], null);
        if (!is_array($tracked)) {
            throw new ApiTaskFailure('No installed plugin provides tracked warm-start settings');
        }
        $overrides = array_merge($overrides, $tracked);
    }
    $config = warm_start_merge_config($overrides);
    $dbc = open_db();
    $summary = warm_start_run($dbc, $config);
    $log('warm start finished at session ' . ($summary['session'] ?? '?'));

    $out = ['summary' => $summary, 'backup' => null];
    if (!empty($spec['backup'])) {
        $out['backup'] = warm_start_backup($dbc, (string) $spec['backup']);
        $log('backup written: ' . $out['backup']);
    }
    mysqli_close($dbc);
    if (!empty($summary['weigh_failed'])) {
        throw new ApiTaskFailure('Simulation stopped: weigh failed', $out);
    }
    return $out;
}
