<?php

function track_scale_plugin_resolve_config(array $params = [])
{
    $config = track_scale_load_config();
    $commodity = trim((string) ($params['commodity'] ?? ''));
    if ($commodity !== '') {
        $config['commodity_code'] = strtoupper($commodity);
    }

    return $config;
}

function track_scale_plugin_dispatch_weigh($dbc, array $step, array $def, array $params, array $config, array $result)
{
    $job = strtoupper(trim($params['job'] ?? ''));
    if ($job === '') {
        $result['skipped'] = true;
        $result['reason'] = 'missing job param';

        return $result;
    }

    $ts_config = track_scale_plugin_resolve_config($params);
    $result['commodity'] = (string) ($ts_config['commodity_code'] ?? '');
    $result['weigh'] = track_scale_run_job_weigh_dispatch($dbc, $job, $config, $ts_config);

    return $result;
}

function track_scale_plugin_dispatch_calibrate($dbc, array $step, array $def, array $params, array $config, array $result)
{
    if (!function_exists('track_scale_maybe_calibrate_scale')) {
        $result['skipped'] = true;
        $result['reason'] = 'calibration helper not available';

        return $result;
    }

    $every = max(1, (int) ($params['every_sessions'] ?? 1));
    $result['calibration'] = track_scale_maybe_calibrate_scale($dbc, [
        'scale_calibrate_every_sessions' => $every,
    ]);
    $result['every_sessions'] = $every;

    return $result;
}

function track_scale_plugin_gui_label_merge(array $params, array &$merged)
{
    $job = trim($params['job'] ?? '');
    $merged['job'] = $job !== '' ? $job : 'train';
    $commodity = trim($params['commodity'] ?? '');
    $merged['commodity_suffix'] = $commodity !== '' ? ' (' . $commodity . ')' : '';
}

function track_scale_plugin_normalize_params(array &$params)
{
    if (!empty($params['commodity'])) {
        $params['commodity'] = strtoupper(trim((string) $params['commodity']));
    }
}

function track_scale_plugin_guess_weigh_cars($text)
{
    if (stripos((string) $text, 'Weigh Cars') !== false) {
        return 'track_scale';
    }

    return null;
}

function track_scale_catalog_definitions()
{
    return [
        [
            'id' => 'track_scale',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'during',
            'label' => 'Track Scale',
            'gui_template' => 'Weigh Cars {job}{commodity_suffix}',
            'description' => 'Weigh loaded cars on a job train (or at the scale) for the selected commodity. Uses track scale config when commodity is blank.',
            'runnable' => true,
            'dispatch' => 'track_scale',
            'params' => [
                operational_steps_catalog_job_param(false),
                operational_steps_catalog_commodity_param(false),
            ],
        ],
        [
            'id' => 'calibrate_track_scale',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'during',
            'label' => 'Calibrate Track Scale',
            'gui_template' => 'Calibrate Track Scale (every {every_sessions} session[s])',
            'description' => 'Recalibrate the coke track scale. Performs a fresh random calibration when required (first use / out of service) or when the configured number of sessions have elapsed since the last calibration. Set to 1 to calibrate every session (steady ~15% reload routing); higher values let scale drift accumulate so more cars route to reload between calibrations. Run this before the Track Scale weigh step.',
            'runnable' => true,
            'dispatch' => 'calibrate_track_scale',
            'params' => [
                [
                    'key' => 'every_sessions',
                    'label' => 'Calibrate every N sessions',
                    'type' => 'number',
                    'default' => '1',
                    'required' => false,
                    'min' => 1,
                    'step' => 1,
                    'visible_label' => true,
                ],
            ],
        ],
        [
            'id' => 'track_scale_gui',
            'category' => 'operations',
            'adder' => false,
            'label' => 'Track Scale (STS GUI)',
            'gui_template' => 'Track Scale',
            'description' => 'Weigh cars at track scale. GUI: track_scale.php.',
            'runnable' => false,
            'gui_path' => '/sts/track_scale.php',
            'params' => [],
        ],
    ];
}
