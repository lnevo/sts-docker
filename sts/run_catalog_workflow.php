#!/usr/bin/env php
<?php
/**
 * Run a catalog test workflow CSV through session_run_recipe (simulation dispatch).
 *
 * Usage: php run_catalog_workflow.php [workflow.csv]
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

chdir(__DIR__);
require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/session_helpers.php';
require_once __DIR__ . '/operational_steps_catalog.php';

$defaultCsv = __DIR__ . '/backups/session_editor/WORKFLOW_TEST_ALL_TYPES.csv';
$csvPath = $argv[1] ?? $defaultCsv;

if (!is_file($csvPath)) {
    fwrite(STDERR, "Workflow CSV not found: {$csvPath}\n");
    fwrite(STDERR, "Usage: php run_catalog_workflow.php [workflow.csv]\n");
    exit(1);
}

$recipe = operational_steps_normalize_recipe(
    operational_steps_default_recipe_from_csv_file($csvPath)
);
$recipe['source_csv'] = basename($csvPath);

$dbc = open_db();
$result = session_run_recipe($dbc, $recipe, ['format' => 'phased']);

$errors = [];
$skipped = 0;
$dispatched = 0;
$control = 0;

if (!empty($result['error'])) {
    $errors[] = (string) $result['error'];
}

foreach ($result['log'] ?? [] as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    if (!empty($entry['error'])) {
        $errors[] = 'Step ' . ($entry['step'] ?? '?') . ': ' . $entry['error'];
    }
    if (!empty($entry['skipped'])) {
        $skipped++;
        continue;
    }
    $action = $entry['action'] ?? ($entry['function'] ?? '');
    if (in_array($action, ['section_label', 'if_then', 'goto', 'stop'], true)) {
        $control++;
        continue;
    }
    if ($action !== '') {
        $dispatched++;
    }
}

echo "Catalog workflow run\n";
echo "  CSV:      {$csvPath}\n";
echo "  Session:  " . ($result['session'] ?? '?') . "\n";
echo "  Steps:    " . count($recipe['steps'] ?? []) . "\n";
echo "  Phases:   " . ($result['phases'] ?? 0) . "\n";
echo "  Control:  {$control}\n";
echo "  Dispatch: {$dispatched}\n";
echo "  Skipped:  {$skipped}\n";

if ($errors) {
    echo "  Errors:   " . count($errors) . "\n";
    foreach ($errors as $err) {
        echo "    - {$err}\n";
    }
    exit(1);
}

echo "  Status:   OK\n";
exit(0);
