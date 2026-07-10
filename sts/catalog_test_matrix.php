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
 * Explicit test steps grouped by section. Variants cover multiple param shapes per command.
 *
 * @return list<array{label: string, steps: list<array{function: string, params: array, description: string}>}>
 */
function catalog_test_matrix_sections()
{
    return [
        [
            'label' => '[Catalog test — setup]',
            'steps' => [
                [
                    'function' => 'restore_database',
                    'params' => ['backup' => 'hart_seed'],
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
                    'params' => ['station' => 'Demmler', 'job' => 'D749'],
                    'description' => 'Test: Build Switch Lists (Demmler D749)',
                ],
                [
                    'function' => 'build_switchlists_sts',
                    'params' => ['station' => 'Scully', 'job' => 'NVL'],
                    'description' => 'Test: Build Switch Lists (Scully NVL)',
                ],
                [
                    'function' => 'auto_assign_locals',
                    'params' => ['jobs' => 'D749,NVL,CK1'],
                    'description' => 'Test: Auto-Assign Cars (explicit jobs)',
                ],
                [
                    'function' => 'auto_assign_locals',
                    'params' => [],
                    'description' => 'Test: Auto-Assign Cars (locals default)',
                ],
                [
                    'function' => 'pick_up_cars',
                    'params' => ['job' => 'D749', 'location' => 'Demmler'],
                    'description' => 'Test: Pick Up Cars (D749 Demmler)',
                ],
                [
                    'function' => 'pick_up_cars',
                    'params' => [],
                    'description' => 'Test: Pick Up Cars (all locals)',
                ],
                [
                    'function' => 'set_out_cars',
                    'params' => ['job' => 'D749', 'location' => 'South-Yard'],
                    'description' => 'Test: Set Out Cars (D749 South-Yard)',
                ],
                [
                    'function' => 'set_out_cars',
                    'params' => [],
                    'description' => 'Test: Set Out Cars (all locals)',
                ],
                [
                    'function' => 'run_job_criterion',
                    'params' => ['job' => 'NVL', 'steps' => '10,15,20'],
                    'description' => 'Test: Run Job Criterion Steps',
                ],
                [
                    'function' => 'track_scale',
                    'params' => ['job' => 'CK1'],
                    'description' => 'Test: Track Scale (CK1)',
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
                            'current_location' => 'Scully',
                            'status' => 'Loading',
                        ],
                    ],
                    'description' => 'Test: Load / Unload Cars',
                ],
            ],
        ],
        [
            'label' => '[Session]',
            'steps' => [
                [
                    'function' => 'increment_session',
                    'params' => [],
                    'description' => 'Test: Increment Session Number',
                ],
            ],
        ],
        [
            'label' => '[Switch Lists]',
            'steps' => [
                [
                    'function' => 'generate_switchlists',
                    'params' => ['jobs' => 'all'],
                    'description' => 'Test: Generate Switch Lists',
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
                    'params' => ['backup' => 'catalog_test_backup'],
                    'description' => 'Test: Create Backup',
                ],
                [
                    'function' => 'validate_database',
                    'params' => [],
                    'description' => 'Test: Validate Database',
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
                    'params' => ['variable' => 'session_nbr', 'operator' => '>', 'value' => '0'],
                    'description' => 'Test: If … then',
                ],
                [
                    'function' => 'goto',
                    'params' => ['step' => '36'],
                    'description' => 'Test: Goto step (forward to Stop)',
                ],
            ],
        ],
    ];
}

/** Commands covered by catalog_test_matrix_sections() (one entry per function id). */
function catalog_test_matrix_covered_command_ids()
{
    $ids = [];
    foreach (catalog_test_matrix_sections() as $section) {
        foreach ($section['steps'] as $step) {
            $ids[$step['function']] = true;
        }
    }
    return array_keys($ids);
}

/** Round-trip import is unreliable for these command ids (complex param encoding). */
function catalog_test_matrix_round_trip_skip()
{
    return [
        'fill_orders',
        'reposition_empties',
        'load_unload',
        'import_data',
        'goto',
        'text_instruction',
        'track_scale',
    ];
}

/** Recipe runner handles these; operational_steps_dispatch_step() has no case. */
function catalog_test_matrix_runner_dispatch_ids()
{
    return ['goto', 'if_then', 'stop'];
}
