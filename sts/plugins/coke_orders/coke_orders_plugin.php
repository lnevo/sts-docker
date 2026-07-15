<?php

function coke_orders_normalize_params(array &$params)
{
    $target_min = trim((string) ($params['target_min'] ?? '6'));
    $target_max = trim((string) ($params['target_max'] ?? '8'));
    if ($target_min === '' || !ctype_digit($target_min)) {
        $target_min = '6';
    }
    if ($target_max === '' || !ctype_digit($target_max)) {
        $target_max = '8';
    }
    if ((int) $target_max < (int) $target_min) {
        $target_max = $target_min;
    }

    $params['target_min'] = $target_min;
    $params['target_max'] = $target_max;
}

function coke_orders_dispatch_replenish($dbc, array $step, array $def, array $params, array $config, array $result)
{
    coke_orders_normalize_params($params);

    return array_merge(
        $result,
        coke_orders_replenish(
            $dbc,
            (int) $params['target_min'],
            (int) $params['target_max']
        )
    );
}

function coke_orders_dispatch_log_messages(array $entry, array &$messages)
{
    if (!array_key_exists('after', $entry)) {
        return;
    }
    $messages[] = sprintf(
        'Outbound coke orders: %d → %d (target %d–%d).',
        (int) ($entry['before'] ?? 0),
        (int) $entry['after'],
        (int) ($entry['target_min'] ?? 6),
        (int) ($entry['target_max'] ?? 8)
    );
    if (!empty($entry['shipments']) && is_array($entry['shipments'])) {
        $messages[] = 'Lanes: ' . implode(', ', $entry['shipments']) . '.';
    }
}

function coke_orders_plugin_guess_replenish($text)
{
    if (stripos((string) $text, 'Replenish Coke') !== false) {
        return 'replenish_coke_orders';
    }

    return null;
}

function coke_orders_catalog_definitions()
{
    return [
        [
            'id' => 'replenish_coke_orders',
            'category' => 'operations',
            'adder' => false,
            'adder_group' => 'before',
            'label' => 'Replenish Coke Orders',
            'gui_template' => 'Replenish Coke Orders (target {target_min}–{target_max})',
            'description' => 'Count unfilled outbound coke orders (USS/CLEV singles and bulk). When below target minimum, generate single-car COKE-USS / COKE-CLEV orders alternating lanes until the minimum is met (capped at target maximum).',
            'runnable' => true,
            'dispatch' => 'replenish_coke_orders',
            'params' => [
                [
                    'key' => 'target_min',
                    'label' => 'Target minimum',
                    'type' => 'number',
                    'default' => '6',
                    'required' => true,
                    'min' => 1,
                    'step' => 1,
                    'visible_label' => true,
                ],
                [
                    'key' => 'target_max',
                    'label' => 'Target maximum',
                    'type' => 'number',
                    'default' => '8',
                    'required' => true,
                    'min' => 1,
                    'step' => 1,
                    'visible_label' => true,
                ],
            ],
        ],
    ];
}

function coke_orders_catalog_test_sections($dbc, array $context)
{
    return [
        [
            'label' => '[Before Operations — Coke Orders plugin]',
            'steps' => [
                [
                    'function' => 'replenish_coke_orders',
                    'params' => ['target_min' => '6', 'target_max' => '8'],
                    'description' => 'Test: Replenish Coke Orders',
                ],
            ],
        ],
    ];
}
