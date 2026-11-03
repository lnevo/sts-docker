<?php
/**
 * Mark every queued or running task failed ("container restarted"). Called by
 * start.sh before Apache starts, when no task process can still be alive.
 *
 *   php sts/api/bin/fail_tasks.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/tasks.php';

$n = 0;
try {
    foreach (glob(api_tasks_dir() . '/*.json') ?: [] as $file) {
        $id = basename($file, '.json');
        $rec = api_task_id_valid($id) ? api_task_read($id) : null;
        if ($rec === null || !in_array($rec['status'] ?? '', ['queued', 'running'], true)) {
            continue;
        }
        api_task_update($id, static function ($r) {
            if (in_array($r['status'] ?? '', ['queued', 'running'], true)) {
                $r['status'] = 'failed';
                $r['finished_at'] = api_task_now();
                $r['error'] = 'container restarted';
            }
            return $r;
        });
        $n++;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'fail_tasks: ' . $e->getMessage() . "\n");
    exit(2);
}
echo $n > 0 ? "Marked $n stale task(s) failed (container restarted)\n" : "No stale tasks\n";
