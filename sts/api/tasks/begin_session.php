<?php
/**
 * Task kind begin_session: warm_start_begin_operating_session(), modelled on the
 * sts-helpers CLI begin_operating_session.php.
 * Spec: load_unload, increment, reposition, assign, fill, generate (bools),
 * reposition_fraction, fill_fraction, config (array), options (array of extra
 * scenario options), backup.
 */
function api_task_kind_begin_session(array $spec, callable $log)
{
    $sts = dirname(__DIR__, 2);
    require_once $sts . '/open_db.php';
    require_once $sts . '/warm_start_helpers.php';
    chdir($sts);

    $options = !empty($spec['options']) && is_array($spec['options']) ? $spec['options'] : [];
    foreach (['load_unload', 'increment', 'reposition', 'assign', 'fill'] as $flag) {
        $options[$flag] = array_key_exists($flag, $spec) ? (bool) $spec[$flag] : true;
    }
    $options['generate'] = !empty($spec['generate']);
    foreach (['reposition_fraction', 'fill_fraction'] as $key) {
        if (isset($spec[$key])) {
            $options[$key] = (float) $spec[$key];
        }
    }
    if (!empty($spec['config']) && is_array($spec['config'])) {
        $options['config'] = $spec['config'];
    }
    $dbc = open_db();
    $result = warm_start_begin_operating_session($dbc, $options);
    $out = ['result' => $result, 'report' => warm_start_format_begin_session_report($result), 'backup' => null];
    foreach (explode("\n", (string) $out['report']) as $line) {
        $log($line);
    }
    if (!empty($result['blocked'])) {
        mysqli_close($dbc);
        throw new ApiTaskFailure('Session start blocked', $out);
    }
    if (!empty($spec['backup'])) {
        $out['backup'] = warm_start_backup($dbc, (string) $spec['backup']);
        $log('backup written: ' . $out['backup']);
    }
    mysqli_close($dbc);
    return $out;
}
