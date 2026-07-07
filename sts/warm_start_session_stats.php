<?php
/**
 * Per-session move statistics for NVL, D749, and CK1.
 */

function warm_start_session_stats_blank()
{
    return [
        'NVL' => [
            'pu_scully' => 0,
            'so_south_1' => 0,
            'pu_south_1' => 0,
            'deliver_island' => 0,
            'pu_island' => 0,
            'so_south_2' => 0,
            'pu_south_2' => 0,
            'so_scully' => 0,
            '_seen_island' => false,
        ],
        'D749' => [
            'pu_demmler' => 0,
            'pu_south' => 0,
            'so_demmler' => 0,
        ],
        'CK1' => [
            'pu_south' => 0,
            'so_shenango' => 0,
            'pu_shenango' => 0,
            'weighed' => 0,
            'reloads' => 0,
            'outbound' => 0,
            'so_south' => 0,
        ],
        'coke_deliveries' => 0,
        'coke_reload_weigh' => 0,
    ];
}

function &warm_start_session_stats_current()
{
    static $stats = null;
    if ($stats === null) {
        $stats = warm_start_session_stats_blank();
    }
    return $stats;
}

function warm_start_session_stats_reset()
{
    $stats = &warm_start_session_stats_current();
    $stats = warm_start_session_stats_blank();
}

function &warm_start_session_stats_log()
{
    static $log = [];
    return $log;
}

function warm_start_reset_session_stats_log()
{
    $log = &warm_start_session_stats_log();
    $log = [];
}

function warm_start_record_job_pickup($dbc, $job_name, $location_id)
{
    if (!in_array($job_name, ['NVL', 'D749', 'CK1'], true)) {
        return;
    }

    $station = warm_start_location_station($dbc, $location_id);
    $s = &warm_start_session_stats_current();

    if ($job_name === 'NVL') {
        if ($station === 9) {
            $s['NVL']['pu_scully']++;
        } elseif ($station === 8) {
            if (!$s['NVL']['_seen_island']) {
                $s['NVL']['pu_south_1']++;
            } else {
                $s['NVL']['pu_south_2']++;
            }
        } elseif ($station === 3) {
            $s['NVL']['pu_island']++;
            $s['NVL']['_seen_island'] = true;
        } elseif ($station === 12) {
            $s['NVL']['_seen_island'] = true;
        }
        return;
    }

    if ($job_name === 'D749') {
        if ($station === 10) {
            $s['D749']['pu_demmler']++;
        } elseif ($station === 8) {
            $s['D749']['pu_south']++;
        }
        return;
    }

    if ($job_name === 'CK1') {
        if ($station === 8) {
            $s['CK1']['pu_south']++;
        } elseif ($station === 12) {
            $s['CK1']['pu_shenango']++;
        }
    }
}

function warm_start_record_job_setout($dbc, $job_name, $location_id)
{
    if (!in_array($job_name, ['NVL', 'D749', 'CK1'], true)) {
        return;
    }

    $station = warm_start_location_station($dbc, $location_id);
    $s = &warm_start_session_stats_current();

    if ($job_name === 'NVL') {
        if ($station === 9) {
            $s['NVL']['so_scully']++;
        } elseif ($station === 8) {
            if (!$s['NVL']['_seen_island']) {
                $s['NVL']['so_south_1']++;
            } else {
                $s['NVL']['so_south_2']++;
            }
        } elseif ($station === 3 || $station === 12) {
            $s['NVL']['deliver_island']++;
            $s['NVL']['_seen_island'] = true;
        }
        return;
    }

    if ($job_name === 'D749' && $station === 10) {
        $s['D749']['so_demmler']++;
        return;
    }

    if ($job_name === 'CK1') {
        if ($station === 12) {
            $s['CK1']['so_shenango']++;
        } elseif ($station === 8) {
            $s['CK1']['so_south']++;
        }
    }
}

function warm_start_session_stats_commit($session, $label, array $coke_before = null)
{
    $s = warm_start_session_stats_current();
    $coke_after = warm_start_coke_stats_copy();
    $delta = $coke_before === null
        ? ['deliveries' => 0, 'reloads' => 0]
        : warm_start_coke_stats_delta($coke_before, $coke_after);

    $row = [
        'session' => (int) $session,
        'label' => $label,
        'NVL' => $s['NVL'],
        'D749' => $s['D749'],
        'CK1' => $s['CK1'],
        'coke_deliveries' => (int) $delta['deliveries'],
        'coke_reload_weigh' => (int) $delta['reloads'],
    ];

    $log = &warm_start_session_stats_log();
    $log[] = $row;
    warm_start_session_stats_reset();

    return $row;
}

function warm_start_format_session_stats_report()
{
    $log = warm_start_session_stats_log();
    if (count($log) === 0) {
        return '';
    }

    $lines = [];
    $lines[] = '=== NVL moves by session ===';
    $lines[] = sprintf(
        '%-7s %3s %3s %3s %3s %3s %3s %3s %3s',
        'Sess',
        'PuS',
        'SoY',
        'PuY',
        'Del',
        'PuI',
        'SoY2',
        'PuY2',
        'SoS'
    );
    $lines[] = str_repeat('-', 52);
    $nvl_totals = array_fill_keys(['pu_scully', 'so_south_1', 'pu_south_1', 'deliver_island', 'pu_island', 'so_south_2', 'pu_south_2', 'so_scully'], 0);

    foreach ($log as $row) {
        $n = $row['NVL'];
        $lines[] = sprintf(
            '%-7d %3d %3d %3d %3d %3d %3d %3d %3d',
            $row['session'],
            $n['pu_scully'],
            $n['so_south_1'],
            $n['pu_south_1'],
            $n['deliver_island'],
            $n['pu_island'],
            $n['so_south_2'],
            $n['pu_south_2'],
            $n['so_scully']
        );
        foreach ($nvl_totals as $key => $_) {
            if ($key[0] === '_') {
                continue;
            }
            $nvl_totals[$key] += (int) ($n[$key] ?? 0);
        }
    }
    $lines[] = sprintf(
        '%-7s %3d %3d %3d %3d %3d %3d %3d %3d',
        'Total',
        $nvl_totals['pu_scully'],
        $nvl_totals['so_south_1'],
        $nvl_totals['pu_south_1'],
        $nvl_totals['deliver_island'],
        $nvl_totals['pu_island'],
        $nvl_totals['so_south_2'],
        $nvl_totals['pu_south_2'],
        $nvl_totals['so_scully']
    );
    $lines[] = '  PuS=pickup Scully  SoY/PuY=South visit 1  Del=Island/Shen setout  PuI=pickup Island  SoY2/PuY2=South visit 2  SoS=setout Scully';

    $lines[] = '';
    $lines[] = '=== D749 moves by session ===';
    $lines[] = sprintf('%-7s %5s %5s %5s', 'Sess', 'PuDEM', 'PuSth', 'SoDEM');
    $lines[] = str_repeat('-', 28);
    $d749_totals = ['pu_demmler' => 0, 'pu_south' => 0, 'so_demmler' => 0];
    foreach ($log as $row) {
        $d = $row['D749'];
        $lines[] = sprintf(
            '%-7d %5d %5d %5d',
            $row['session'],
            $d['pu_demmler'],
            $d['pu_south'],
            $d['so_demmler']
        );
        foreach ($d749_totals as $key => $_) {
            $d749_totals[$key] += (int) ($d[$key] ?? 0);
        }
    }
    $lines[] = sprintf('%-7s %5d %5d %5d', 'Total', $d749_totals['pu_demmler'], $d749_totals['pu_south'], $d749_totals['so_demmler']);

    $lines[] = '';
    $lines[] = '=== CK1 coke cycle by session ===';
    $lines[] = sprintf('%-7s %4s %4s %4s %4s %4s %4s %4s', 'Sess', 'PuS', 'SoSh', 'PuSh', 'Wgh', 'Rld', 'Ob', 'SoS');
    $lines[] = str_repeat('-', 44);
    $ck1_totals = ['pu_south' => 0, 'so_shenango' => 0, 'pu_shenango' => 0, 'weighed' => 0, 'reloads' => 0, 'outbound' => 0, 'so_south' => 0];
    foreach ($log as $row) {
        $c = $row['CK1'];
        $lines[] = sprintf(
            '%-7d %4d %4d %4d %4d %4d %4d %4d',
            $row['session'],
            $c['pu_south'],
            $c['so_shenango'],
            $c['pu_shenango'],
            $c['weighed'],
            $c['reloads'],
            $c['outbound'],
            $c['so_south']
        );
        foreach ($ck1_totals as $key => $_) {
            $ck1_totals[$key] += (int) ($c[$key] ?? 0);
        }
    }
    $lines[] = sprintf(
        '%-7s %4d %4d %4d %4d %4d %4d %4d',
        'Total',
        $ck1_totals['pu_south'],
        $ck1_totals['so_shenango'],
        $ck1_totals['pu_shenango'],
        $ck1_totals['weighed'],
        $ck1_totals['reloads'],
        $ck1_totals['outbound'],
        $ck1_totals['so_south']
    );
    $lines[] = '  PuS/SoS=South pickup/setout  SoSh/PuSh=Shenango reload drop / loaded pickup  Wgh/Rld/Ob=weigh stats';

    $lines[] = '';
    $lines[] = '=== Coke customer deliveries (unload) by session ===';
    $lines[] = sprintf('%-7s %4s %4s', 'Sess', 'Del', 'Rld');
    $lines[] = str_repeat('-', 18);
    $del_total = 0;
    $rld_total = 0;
    foreach ($log as $row) {
        $lines[] = sprintf('%-7d %4d %4d', $row['session'], $row['coke_deliveries'], $row['coke_reload_weigh']);
        $del_total += (int) $row['coke_deliveries'];
        $rld_total += (int) $row['coke_reload_weigh'];
    }
    $lines[] = sprintf('%-7s %4d %4d', 'Total', $del_total, $rld_total);

    return implode(PHP_EOL, $lines);
}

function warm_start_print_session_stats_report()
{
    $report = warm_start_format_session_stats_report();
    if ($report !== '') {
        warm_start_log('');
        warm_start_log($report);
    }
}
