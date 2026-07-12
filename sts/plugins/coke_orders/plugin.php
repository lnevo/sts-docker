<?php
/**
 * Coke Orders addon — outbound coke order replenishment for HART-style layouts.
 */
return [
    'id' => 'coke_orders',
    'label' => 'Coke Orders',
    'version' => '1.0.0',
    'bootstrap' => __DIR__ . '/bootstrap.php',
    'helpers' => __DIR__ . '/coke_orders_helpers.php',

    'catalog_steps_callback' => 'coke_orders_catalog_definitions',

    'catalog_adder' => [
        'before' => ['replenish_coke_orders'],
    ],

    'import_text_guessers' => [
        'coke_orders_plugin_guess_replenish',
    ],

    'normalize_params' => [
        'replenish_coke_orders' => 'coke_orders_normalize_params',
    ],

    'dispatch_handlers' => [
        'replenish_coke_orders' => 'coke_orders_dispatch_replenish',
    ],

    'dispatch_log_hooks' => [
        'replenish_coke_orders' => 'coke_orders_dispatch_log_messages',
    ],

    'runtime_without_warm_start' => ['replenish_coke_orders'],

    'catalog_test_sections_callback' => 'coke_orders_catalog_test_sections',

    'catalog_test_round_trip_skip' => ['replenish_coke_orders'],
];
