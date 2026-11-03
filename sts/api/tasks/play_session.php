<?php
/**
 * Task kind play_session: warm_start_play_operating_session(), modelled on the
 * sts-helpers CLI play_operating_session.php.
 * Spec: config (array, optional), backup.
 */
function api_task_kind_play_session(array $spec, callable $log)
{
    $sts = dirname(__DIR__, 2);
    require_once $sts . '/open_db.php';
    require_once $sts . '/warm_start_helpers.php';
    chdir($sts);

    $config = !empty($spec['config']) && is_array($spec['config']) ? $spec['config'] : [];
    $dbc = open_db();
    $log('playing operating session ' . warm_start_get_session($dbc));
    $result = warm_start_play_operating_session($dbc, $config);
    $summary = warm_start_summarize($dbc);
    $out = ['result' => $result, 'summary' => $summary, 'backup' => null];
    if (!empty($spec['backup'])) {
        $out['backup'] = warm_start_backup($dbc, (string) $spec['backup']);
        $log('backup written: ' . $out['backup']);
    }
    mysqli_close($dbc);
    return $out;
}
