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

// Default to the editor's ACTIVE saved workflow (the one the UI runs), so this
// CLI drives the same recipe as the app instead of the catalog test fixture.
$editorDir = operational_steps_editor_dir();
$activeFile = operational_steps_active_workflow($editorDir);
$defaultJson = $activeFile !== ''
    ? operational_steps_workflow_path($editorDir, $activeFile)
    : ($editorDir . '/WORKFLOW_TEST_ALL_TYPES.recipe.json');

$arg = $argv[1] ?? '';
if ($arg === '') {
    $jsonPath = $defaultJson;
} elseif (is_file($arg)) {
    $jsonPath = $arg;
} else {
    // Treat a bare name as a workflow file inside the editor dir.
    $jsonPath = operational_steps_workflow_path($editorDir, $arg);
}

if (!is_file($jsonPath)) {
    fwrite(STDERR, "Workflow JSON not found: {$jsonPath}\n");
    fwrite(STDERR, "Usage: php run_catalog_workflow.php [workflow.json | workflow-name | (blank = active workflow)]\n");
    exit(1);
}

$recipe = operational_steps_load_recipe_from_json_file($jsonPath);
$recipe['source_workflow'] = basename($jsonPath);

$dbc = open_db();
$result = session_run_recipe($dbc, $recipe, ['format' => 'all']);

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

// Per-phase switch-list summary (job + car count) so the caller can see empty
// phases at a glance instead of inspecting the session output tree by hand.
$phaseLines = [];
foreach ($result['log'] ?? [] as $entry) {
    // Only generate_switchlists log entries carry a 'written' job list; skip
    // generate_waybills (which also records a phase but no switch-list output).
    if (!is_array($entry) || !isset($entry['phase']) || !array_key_exists('written', $entry)) {
        continue;
    }
    $cars = 0;
    foreach ((array) ($entry['written'] ?? []) as $w) {
        if (is_array($w)) {
            $cars += (int) ($w['cars'] ?? 0);
        }
    }
    $jobs = [];
    foreach ((array) ($entry['written'] ?? []) as $w) {
        if (is_array($w) && isset($w['job'])) {
            $jobs[] = $w['job'];
        }
    }
    $phaseLines[] = sprintf(
        "  phase %-2s (step %-3s) %-14s %d car(s)%s",
        $entry['phase'],
        $entry['step'] ?? '?',
        implode(',', $jobs),
        $cars,
        $cars === 0 ? '  <== EMPTY' : ''
    );
}
if ($phaseLines) {
    echo "Switch-list phases:\n" . implode("\n", $phaseLines) . "\n";
}

if ($errors) {
    echo "  Errors:   " . count($errors) . "\n";
    foreach ($errors as $err) {
        echo "    - {$err}\n";
    }
    exit(1);
}

echo "  Status:   OK\n";
exit(0);
