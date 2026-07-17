<?php

function track_scale_plugin_resolve_config(array $params = [])
{
    $config = track_scale_load_config();
    $commodity = trim((string) ($params['commodity'] ?? ''));
    if ($commodity !== '') {
        $config['commodity_code'] = strtoupper($commodity);
    }

    // Catalog / recipe override for the automated weigh-batch reload floor.
    // Empty string keeps plugin config default; 0 disables the floor.
    if (array_key_exists('min_reloads', $params) && trim((string) $params['min_reloads']) !== '') {
        if (!isset($config['simulation']) || !is_array($config['simulation'])) {
            $config['simulation'] = [];
        }
        $config['simulation']['min_reloads_per_weigh_batch'] = max(0, (int) $params['min_reloads']);
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
    $result['min_reloads'] = track_scale_min_reloads_per_weigh_batch($ts_config);
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
    $min = trim((string) ($params['min_reloads'] ?? ''));
    if ($min !== '' && ctype_digit($min)) {
        $merged['min_reloads_suffix'] = ' (min reloads ' . $min . ')';
    } else {
        $merged['min_reloads_suffix'] = '';
    }
}

function track_scale_plugin_normalize_params(array &$params)
{
    if (!empty($params['commodity'])) {
        $params['commodity'] = strtoupper(trim((string) $params['commodity']));
    }
    if (array_key_exists('min_reloads', $params)) {
        $raw = trim((string) $params['min_reloads']);
        if ($raw === '') {
            $params['min_reloads'] = '';
        } elseif (preg_match('/^-?\d+$/', $raw)) {
            $params['min_reloads'] = (string) max(0, (int) $raw);
        } else {
            $params['min_reloads'] = '';
        }
    }
}

function track_scale_plugin_guess_weigh_cars($text)
{
    if (stripos((string) $text, 'Weigh Cars') !== false) {
        return 'track_scale';
    }

    return null;
}

function track_scale_plugin_guess_calibrate($text)
{
    if (stripos((string) $text, 'Calibrate Track Scale') !== false) {
        return 'calibrate_track_scale';
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
            'gui_template' => 'Weigh Cars {job}{commodity_suffix}{min_reloads_suffix}',
            'description' => 'Weigh loaded cars on a job train (or at the scale) for the selected commodity. Uses track scale config when commodity is blank. Min reloads floors how many cars in the current weigh batch must route to reload after the tolerance roll (0 disables; blank uses plugin config, usually 1).',
            'runnable' => true,
            'dispatch' => 'track_scale',
            'params' => [
                operational_steps_catalog_job_param(false),
                operational_steps_catalog_commodity_param(false),
                [
                    'key' => 'min_reloads',
                    'label' => 'Min reloads per weigh batch',
                    'type' => 'number',
                    'default' => '1',
                    'required' => false,
                    'min' => 0,
                    'step' => 1,
                    'visible_label' => true,
                ],
            ],
        ],
        [
            'id' => 'calibrate_track_scale',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'during',
            'label' => 'Calibrate Track Scale',
            'gui_template' => 'Calibrate Track Scale (every {every_sessions} session[s])',
            'description' => 'Recalibrate the track scale. Performs a fresh random calibration when required (first use / out of service) or when the configured number of sessions have elapsed since the last calibration. Set to 1 to calibrate every session (steady in-tolerance routing); higher values let scale drift accumulate so more cars route to reload between calibrations. Run this before the Track Scale weigh step.',
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

function track_scale_catalog_test_sections($dbc, array $context)
{
    $job_a = (string) ($context['job_a'] ?? 'JOB_A');
    $commodity = (string) ($context['commodity'] ?? '');

    return [
        [
            'label' => '[During Operations — Track Scale plugin]',
            'steps' => [
                [
                    'function' => 'calibrate_track_scale',
                    'params' => ['every_sessions' => '1'],
                    'description' => 'Test: Calibrate Track Scale (every 1 session)',
                ],
                [
                    'function' => 'track_scale',
                    'params' => array_filter([
                        'job' => $job_a,
                        'commodity' => $commodity,
                        'min_reloads' => '1',
                    ], static function ($v) {
                        return $v !== null && $v !== '';
                    }),
                    'description' => 'Test: Track Scale (' . $job_a . ($commodity !== '' ? ' ' . $commodity : '') . ')',
                ],
            ],
        ],
    ];
}
