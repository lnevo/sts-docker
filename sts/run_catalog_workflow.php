#!/usr/bin/env php
<?php
/**
 * Run a catalog test workflow JSON through session_run_recipe (simulation dispatch).
 *
 * Usage: php run_catalog_workflow.php [workflow.json]
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

chdir(__DIR__);
require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/session_helpers.php';
require_once __DIR__ . '/operational_steps_catalog.php';

$defaultJson = __DIR__ . '/backups/session_editor/WORKFLOW_TEST_ALL_TYPES.recipe.json';
$jsonPath = $argv[1] ?? $defaultJson;

if (!is_file($jsonPath)) {
    fwrite(STDERR, "Workflow JSON not found: {$jsonPath}\n");
    fwrite(STDERR, "Usage: php run_catalog_workflow.php [workflow.json]\n");
    exit(1);
}

$recipe = operational_steps_load_recipe_from_json_file($jsonPath);
$recipe['source_workflow'] = basename($jsonPath);

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
echo "  Workflow: {$jsonPath}\n";
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
