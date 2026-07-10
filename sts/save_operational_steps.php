<?php
/**
 * Save STS operational steps CSV from the browser editor.
 * Writes to sts/backups/session_editor/ (host-mounted sts-backups).
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/operational_steps_catalog.php';

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload) || !isset($payload['rows']) || !is_array($payload['rows'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON: expected { rows: [...] }']);
    exit;
}

function operational_steps_csv_field($value)
{
    $value = str_replace(["\r\n", "\r", "\n"], ' ', (string) $value);
    if (preg_match('/[",\n\r]/', $value)) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

$lines = ['Step #,STS GUI Instruction,Full Description'];
$row_num = 0;
foreach ($payload['rows'] as $row) {
    if (!is_array($row)) {
        continue;
    }
    $row_num++;
    $instruction = $row['instruction'] ?? '';
    $description = $row['description'] ?? '';
    $lines[] = operational_steps_csv_field((string) $row_num)
        . ',' . operational_steps_csv_field($instruction)
        . ',' . operational_steps_csv_field($description);
}

$csv = implode("\n", $lines) . "\n";
$editor_dir = operational_steps_editor_dir();
$path = $editor_dir . '/STS_OPERATIONAL_STEPS.csv';

if (!is_dir($editor_dir) && !@mkdir($editor_dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not create session_editor directory']);
    exit;
}

if (@file_put_contents($path, $csv) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Write failed: ' . $path]);
    exit;
}

echo json_encode([
    'ok' => true,
    'written' => ['session_editor' => $path],
    'rows' => $row_num,
]);
