<?php
/**
 * Test-case definitions for selectable (non-disabled) catalog adder commands.
 * Used by generate_test_workflow_csv.php and validate_catalog_api.php.
 */

function catalog_test_matrix_selectable_commands()
{
    $commands = [];
    foreach (operational_steps_catalog_adder_definitions() as $def) {
        if (!empty($def['disabled'])) {
            continue;
        }
        $commands[] = $def;
    }
    return $commands;
}

function catalog_test_matrix_disabled_adder_commands()
{
    $disabled = [];
    foreach (operational_steps_catalog_adder_definitions() as $def) {
        if (!empty($def['disabled'])) {
            $disabled[] = $def;
        }
    }
    return $disabled;
}

/**
 * Explicit test steps grouped by section. When $dbc is provided, job/station samples come from the DB.
 *
 * @return list<array{label: string, steps: list<array{function: string, params: array, description: string}>}>
 */
function catalog_test_matrix_sections($dbc = null)
{
    $job_a = 'JOB_A';
    $job_b = 'JOB_B';
    $station_a = 'Yard';
    $station_b = 'Offline';
    $jobs_csv = 'JOB_A,JOB_B';
    $commodity = '';
    $default_backup = 'catalog_test_backup';
    if ($dbc !== null) {
        require_once __DIR__ . '/session_helpers.php';
        $jobs = session_list_switchlist_job_names($dbc);
        if (count($jobs) > 0) {
            $job_a = $jobs[0];
        }
        if (count($jobs) > 1) {
            $job_b = $jobs[1];
        }
        $jobs_csv = implode(',', array_slice($jobs, 0, 3));
        $rs = mysqli_query($dbc, 'SELECT routing.station FROM routing ORDER BY routing.station LIMIT 2');
        $stations = [];
        while ($rs && ($row = mysqli_fetch_array($rs))) {
            $stations[] = (string) $row['station'];
        }
        if (!empty($stations[0])) {
            $station_a = $stations[0];
        }
        if (!empty($stations[1])) {
            $station_b = $stations[1];
        }
        $rs = mysqli_query($dbc, 'SELECT code FROM commodities ORDER BY code LIMIT 1');
        if ($rs && ($row = mysqli_fetch_array($rs))) {
            $commodity = (string) ($row['code'] ?? '');
        }
    }
    $backups = operational_steps_list_backup_files();
    if (!empty($backups[0])) {
        $default_backup = $backups[0];
    }

    $context = [
        'job_a' => $job_a,
        'job_b' => $job_b,
        'station_a' => $station_a,
        'station_b' => $station_b,
        'jobs_csv' => $jobs_csv,
        'commodity' => $commodity,
        'default_backup' => $default_backup,
    ];

    $sections = [
        [
            'label' => '[Catalog test — setup]',
            'steps' => [
                [
                    'function' => 'restore_database',
                    'params' => ['backup' => $default_backup],
                    'description' => 'Test: Restore Database',
                ],
            ],
        ],
        [
            'label' => '[Before Operations]',
            'steps' => [
                [
                    'function' => 'generate_orders',
                    'params' => [],
                    'description' => 'Test: Generate Car Orders',
                ],
                [
                    'function' => 'fill_orders',
                    'params' => [
                        'percent' => '100',
                        'car_filters' => [
                            'categories' => 'pool,station,priority,system',
                            'current_station' => 'all',
                        ],
                    ],
                    'description' => 'Test: Fill Car Orders',
                ],
                [
                    'function' => 'reposition_empties',
                    'params' => [
                        'mode' => 'reposition_to_home',
                        'percent' => '65',
                        'filters' => [
                            'current_station' => 'all',
                            'home_station' => 'all',
                        ],
                    ],
                    'description' => 'Test: Reposition Empty Cars',
                ],
            ],
        ],
        [
            'label' => '[During Operations]',
            'steps' => [
                [
                    'function' => 'build_switchlists_sts',
                    'params' => ['station' => $station_a, 'job' => $job_a],
                    'description' => 'Test: Build Switch Lists (' . $station_a . ' ' . $job_a . ')',
                ],
                [
                    'function' => 'build_switchlists_sts',
                    'params' => ['station' => $station_b, 'job' => $job_b],
                    'description' => 'Test: Build Switch Lists (' . $station_b . ' ' . $job_b . ')',
                ],
                [
                    'function' => 'auto_assign_locals',
                    'params' => ['jobs' => $jobs_csv, 'station' => $station_a],
                    'description' => 'Test: Auto-Assign Cars (jobs + station filter)',
                ],
                [
                    'function' => 'auto_assign_locals',
                    'params' => [],
                    'description' => 'Test: Auto-Assign Cars (no jobs selected)',
                ],
                [
                    'function' => 'pick_up_cars',
                    'params' => ['job' => $job_a, 'location' => $station_a],
                    'description' => 'Test: Pick Up Cars (' . $job_a . ' ' . $station_a . ')',
                ],
                [
                    'function' => 'pick_up_cars',
                    'params' => [],
                    'description' => 'Test: Pick Up Cars (all locals)',
                ],
                [
                    'function' => 'set_out_cars',
                    'params' => ['job' => $job_a, 'location' => $station_b],
                    'description' => 'Test: Set Out Cars (' . $job_a . ' ' . $station_b . ')',
                ],
                [
                    'function' => 'set_out_cars',
                    'params' => [],
                    'description' => 'Test: Set Out Cars (all locals)',
                ],
            ],
        ],
        [
            'label' => '[After Operations]',
            'steps' => [
                [
                    'function' => 'load_unload',
                    'params' => [
                        'filters' => [
                            'current_location' => $station_b,
                            'status' => 'Loading',
                        ],
                    ],
                    'description' => 'Test: Load / Unload Cars',
                ],
            ],
        ],
        [
            'label' => '[Switch Lists]',
            'steps' => [
                [
                    'function' => 'generate_switchlists',
                    'params' => [
                        'jobs' => $job_a,
                        'format' => 'mobile',
                        'title' => $job_a,
                        'info' => 'Test',
                    ],
                    'description' => 'Test: Generate Switch Lists (' . $job_a . ' mobile)',
                ],
                [
                    'function' => 'generate_waybills',
                    'params' => [],
                    'description' => 'Test: Generate Waybill List',
                ],
            ],
        ],
        [
            'label' => '[Database]',
            'steps' => [
                [
                    'function' => 'backup_database',
                    // Full-name override (no prefix): keeps remove_backup pairing stable.
                    'params' => ['backup' => 'catalog_test_backup'],
                    'description' => 'Test: Create Backup',
                ],
                [
                    'function' => 'validate_database',
                    'params' => [],
                    'description' => 'Test: Validate Database',
                ],
                [
                    'function' => 'increment_session',
                    'params' => [],
                    'description' => 'Test: Increment Session Number',
                ],
                [
                    'function' => 'import_data',
                    'params' => ['table' => 'shipments', 'add_replace' => 'append'],
                    'description' => 'Test: Import Data',
                ],
                [
                    'function' => 'restart_session',
                    'params' => [],
                    'description' => 'Test: Restart Session',
                ],
                [
                    'function' => 'reset_session',
                    'params' => [],
                    'description' => 'Test: Reset Session',
                ],
                [
                    'function' => 'remove_backup',
                    'params' => ['backup' => 'catalog_test_backup'],
                    'description' => 'Test: Remove Backup',
                ],
            ],
        ],
        [
            'label' => '[Workflow notes]',
            'steps' => [
                [
                    'function' => 'text_instruction',
                    'params' => ['instruction' => 'Sample free-text instruction for catalog test'],
                    'description' => 'Test: Text instruction',
                ],
                [
                    'function' => 'if_then',
                    'params' => [
                        'variable' => 'session_nbr',
                        'operator' => '>',
                        'value' => '0',
                    ],
                    'description' => 'Test: If … then goto',
                ],
            ],
        ],
    ];

    return array_merge($sections, plugins_catalog_test_sections($dbc, $context));
}

/** Commands covered by catalog_test_matrix_sections() (one entry per function id). */
function catalog_test_matrix_covered_command_ids($dbc = null)
{
    $ids = [];
    foreach (catalog_test_matrix_sections($dbc) as $section) {
        foreach ($section['steps'] as $step) {
            $ids[$step['function']] = true;
        }
    }
    return array_keys($ids);
}

/** Round-trip import is unreliable for these command ids (complex param encoding). */
function catalog_test_matrix_round_trip_skip()
{
    return array_values(array_unique(array_merge([
        'fill_orders',
        'reposition_empties',
        'load_unload',
        'import_data',
        'goto',
        'text_instruction',
        'auto_assign_locals',
        'generate_switchlists',
    ], plugins_catalog_test_round_trip_skip())));
}

/** Recipe runner handles these; operational_steps_dispatch_step() has no case. */
function catalog_test_matrix_runner_dispatch_ids()
{
    return ['goto', 'if_then', 'stop'];
}
