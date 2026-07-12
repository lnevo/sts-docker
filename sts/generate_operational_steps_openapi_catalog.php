#!/usr/bin/env php
<?php
/**
 * Emit OpenAPI fragments for catalog command params (from operational_steps_catalog.php).
 *
 * Usage:
 *   php generate_operational_steps_openapi_catalog.php [output.yaml]
 *
 * Default output: operational_steps_catalog.openapi.generated.yaml beside this script.
 * Regenerate after changing catalog params; wired into bin/run_catalog_tests.sh.
 *
 * Options:
 *   --core-only   Emit core catalog only (omit plugin-contributed steps).
 */

declare(strict_types=1);

$sts_dir = __DIR__;
$core_only = in_array('--core-only', $argv, true);
if ($core_only) {
    define('STS_CATALOG_CORE_ONLY', true);
    $argv = array_values(array_filter($argv, static function ($arg) {
        return $arg !== '--core-only';
    }));
}
require_once $sts_dir . '/operational_steps_catalog.php';

$out = $argv[1] ?? ($sts_dir . '/operational_steps_catalog.openapi.generated.yaml');

$catalog_payload = [
    'ok' => true,
    'categories' => operational_steps_catalog_categories(),
    'adder_categories' => operational_steps_catalog_adder_categories(),
    'dynamic_options' => [
        'note' => 'Populated at runtime from DB (jobs, locations, backups, car codes, shipments, etc.)',
    ],
    'functions' => operational_steps_catalog_definitions(),
    'adder_functions' => operational_steps_catalog_adder_definitions(),
];

$commands_by_id = [];
foreach (operational_steps_catalog_definitions() as $def) {
    $id = (string) ($def['id'] ?? '');
    if ($id === '') {
        continue;
    }
    $commands_by_id[$id] = catalog_openapi_command_schema($def);
}

$lines = [];
$lines[] = '# GENERATED FILE — do not edit by hand.';
$lines[] = '# Source: operational_steps_catalog.php via generate_operational_steps_openapi_catalog.php';
$lines[] = '';
$lines[] = 'components:';
$lines[] = '  examples:';
$lines[] = '    CatalogFullResponse:';
$lines[] = '      summary: Full catalog (all commands and params)';
$lines[] = '      value:';
foreach (catalog_openapi_indent_yaml($catalog_payload, 8) as $line) {
    $lines[] = $line;
}
$lines[] = '  schemas:';
$lines[] = '    CatalogCommandsById:';
$lines[] = '      type: object';
$lines[] = '      description: >';
$lines[] = '        Workflow commands keyed by function id. Each entry lists label, category,';
$lines[] = '        runnable/dispatch metadata, and the params array accepted in recipe steps.';
$lines[] = '        Generated from operational_steps_catalog.php — regenerate after catalog edits.';
$lines[] = '      additionalProperties: false';
$lines[] = '      properties:';
foreach ($commands_by_id as $id => $schema_lines) {
    $lines[] = '        ' . catalog_openapi_yaml_key($id) . ':';
    foreach ($schema_lines as $schema_line) {
        $lines[] = '          ' . $schema_line;
    }
}

$yaml = implode("\n", $lines) . "\n";
if (@file_put_contents($out, $yaml) === false) {
    fwrite(STDERR, "Write failed: {$out}\n");
    exit(1);
}

fwrite(STDOUT, "Wrote {$out} (" . count($commands_by_id) . " commands)\n");

/**
 * @param array<string, mixed> $def
 * @return list<string>
 */
function catalog_openapi_command_schema(array $def): array
{
    $lines = [];
    $lines[] = 'type: object';
    $lines[] = 'required: [id, label, params]';
    $lines[] = 'properties:';
    $lines[] = '  id:';
    $lines[] = '    type: string';
    $lines[] = '    enum: [' . catalog_openapi_yaml_scalar($def['id'] ?? '') . ']';
    $lines[] = '  label:';
    $lines[] = '    type: string';
    $lines[] = '    example: ' . catalog_openapi_yaml_scalar($def['label'] ?? '');
    if (!empty($def['category'])) {
        $lines[] = '  category:';
        $lines[] = '    type: string';
        $lines[] = '    example: ' . catalog_openapi_yaml_scalar($def['category']);
    }
    if (array_key_exists('runnable', $def)) {
        $lines[] = '  runnable:';
        $lines[] = '    type: boolean';
        $lines[] = '    example: ' . (!empty($def['runnable']) ? 'true' : 'false');
    }
    if (!empty($def['dispatch'])) {
        $lines[] = '  dispatch:';
        $lines[] = '    type: string';
        $lines[] = '    example: ' . catalog_openapi_yaml_scalar($def['dispatch']);
    }
    if (!empty($def['gui_template'])) {
        $lines[] = '  gui_template:';
        $lines[] = '    type: string';
        $lines[] = '    example: ' . catalog_openapi_yaml_scalar($def['gui_template']);
    }
    if (!empty($def['description'])) {
        $lines[] = '  description:';
        $lines[] = '    type: string';
        $lines[] = '    example: ' . catalog_openapi_yaml_scalar($def['description']);
    }
    $lines[] = '  params:';
    $lines[] = '    type: array';
    $lines[] = '    items:';
    $lines[] = '      $ref: "operational_steps_api.openapi.yaml#/components/schemas/CatalogParam"';
    if (!empty($def['params'])) {
        $lines[] = '    example:';
        foreach (catalog_openapi_indent_yaml($def['params'], 0) as $ex_line) {
            $lines[] = '      ' . $ex_line;
        }
    } else {
        $lines[] = '    example: []';
    }

    return $lines;
}

/**
 * @param mixed $value
 * @return list<string>
 */
function catalog_openapi_indent_yaml($value, int $indent): array
{
    $pad = str_repeat(' ', $indent);
    $lines = [];

    if (is_array($value)) {
        if ($value === []) {
            return ['[]'];
        }
        $is_list = array_keys($value) === range(0, count($value) - 1);
        if ($is_list) {
            foreach ($value as $item) {
                if (is_array($item)) {
                    $lines[] = $pad . '-';
                    foreach (catalog_openapi_indent_yaml($item, $indent + 2) as $child) {
                        $lines[] = $child;
                    }
                } else {
                    $lines[] = $pad . '- ' . catalog_openapi_yaml_scalar($item);
                }
            }
            return $lines;
        }
        foreach ($value as $key => $item) {
            $key_str = catalog_openapi_yaml_key((string) $key);
            if (is_array($item)) {
                if ($item === []) {
                    $lines[] = $pad . $key_str . ': []';
                    continue;
                }
                $item_is_list = array_keys($item) === range(0, count($item) - 1);
                if ($item_is_list) {
                    $lines[] = $pad . $key_str . ':';
                    foreach ($item as $list_item) {
                        if (is_array($list_item)) {
                            $lines[] = $pad . '  -';
                            foreach (catalog_openapi_indent_yaml($list_item, $indent + 4) as $child) {
                                $lines[] = $child;
                            }
                        } else {
                            $lines[] = $pad . '  - ' . catalog_openapi_yaml_scalar($list_item);
                        }
                    }
                } else {
                    $lines[] = $pad . $key_str . ':';
                    foreach (catalog_openapi_indent_yaml($item, $indent + 2) as $child) {
                        $lines[] = $child;
                    }
                }
            } else {
                $lines[] = $pad . $key_str . ': ' . catalog_openapi_yaml_scalar($item);
            }
        }
        return $lines;
    }

    return [$pad . catalog_openapi_yaml_scalar($value)];
}

/**
 * @param mixed $value
 */
function catalog_openapi_yaml_scalar($value): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    $text = str_replace(["\r\n", "\r", "\n"], ' ', (string) $value);
    if ($text === '' || preg_match('/[:#\[\]{},&*!|>\'"%@`]/', $text) || preg_match('/^\s/', $text) || preg_match('/\s$/', $text)) {
        return json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $text;
}

function catalog_openapi_yaml_key(string $key): string
{
    if ($key === '' || preg_match('/[^A-Za-z0-9_.-]/', $key)) {
        return json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $key;
}
