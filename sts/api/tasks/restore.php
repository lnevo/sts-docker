<?php
/**
 * Task kind restore: sts_restore_sql_file() on backups/<backup>, optional photos,
 * then the generic plugin session-reset hooks (reason 'restore').
 * Spec: backup (file name in the backups dir), photos (bool), full (bool, default
 * true: drop every table first, like a DROP/CREATE DATABASE, so tables that are not
 * in the backup do not survive the restore).
 */
function api_task_kind_restore(array $spec, callable $log)
{
    $sts = dirname(__DIR__, 2);
    require_once $sts . '/open_db.php';
    require_once $sts . '/restore_sql_helpers.php';
    require_once $sts . '/operational_steps_catalog.php';
    require_once $sts . '/plugin_registry.php';

    $name = (string) ($spec['backup'] ?? '');
    $dir = operational_steps_backups_dir();
    $path = $dir . '/' . $name;
    if ($name === '' || $name !== basename($name) || $name[0] === '.' || !is_file($path)) {
        throw new ApiTaskFailure('Backup not found');
    }
    chdir($sts);
    $dbc = open_db();
    mysqli_report(MYSQLI_REPORT_OFF);
    // Keep other requests' open_db() migrations (and v1 requests) away while tables are dropped and recreated.
    $marker_id = (string) ($GLOBALS['run_task_id'] ?? '');
    if ($marker_id !== '') {
        api_restore_marker_set($marker_id);
    }
    try {
        return api_task_restore_body($spec, $log, $dbc, $sts, $dir, $name, $path);
    } finally {
        if ($marker_id !== '') {
            api_restore_marker_clear($marker_id);
        }
    }
}

function api_task_restore_body(array $spec, callable $log, $dbc, $sts, $dir, $name, $path)
{
    if (!array_key_exists('full', $spec) || !empty($spec['full'])) {
        $dropped = 0;
        mysqli_query($dbc, 'SET FOREIGN_KEY_CHECKS = 0');
        $rs = mysqli_query($dbc, 'SHOW FULL TABLES');
        $names = [];
        while ($rs && ($row = mysqli_fetch_row($rs))) {
            $names[] = [$row[0], strtoupper((string) ($row[1] ?? 'BASE TABLE'))];
        }
        foreach ($names as [$t, $kind]) {
            $sql = ($kind === 'VIEW' ? 'DROP VIEW IF EXISTS `' : 'DROP TABLE IF EXISTS `') . str_replace('`', '``', $t) . '`';
            if (mysqli_query($dbc, $sql)) {
                $dropped++;
            }
        }
        mysqli_query($dbc, 'SET FOREIGN_KEY_CHECKS = 1');
        $log('cleared database: ' . $dropped . ' tables/views dropped');
    }
    $log('restoring ' . $name);
    [$ok, $msg] = sts_restore_sql_file($dbc, $path, $name);
    if (!$ok) {
        throw new ApiTaskFailure($msg);
    }
    $log($msg);
    $out = ['backup' => $name, 'photos_restored' => 0];

    if (!empty($spec['photos'])) {
        $images = $sts . '/ImageStore/DB_Images';
        foreach (['barcodes', 'qrcodes', 'uploads', 'RollingStock'] as $sub) {
            foreach (glob($images . '/' . $sub . '/*.*') ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
        $photos = $dir . '/' . $name . '_photos';
        if (is_dir($photos)) {
            if (!is_dir($images . '/RollingStock')) {
                @mkdir($images . '/RollingStock', 0775, true);
            }
            foreach (glob($photos . '/*.*') ?: [] as $f) {
                if (is_file($f) && copy($f, $images . '/RollingStock/' . basename($f))) {
                    $out['photos_restored']++;
                }
            }
            $log($out['photos_restored'] . ' rolling stock photos restored');
        } else {
            $log('no ' . $name . '_photos directory; photo folders cleared only');
        }
    }

    $rs = mysqli_query($dbc, 'SELECT setting_value FROM settings WHERE setting_name = "session_nbr"');
    $row = $rs ? mysqli_fetch_array($rs) : null;
    $session = $row ? (int) $row['setting_value'] : 0;
    plugins_run_session_reset_hooks($dbc, ['session_nbr' => $session, 'restored_session' => $session, 'reason' => 'restore']);
    $log('plugin session-reset hooks run');
    mysqli_close($dbc);
    return $out;
}
