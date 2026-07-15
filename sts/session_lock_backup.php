<?php
/**
 * Lock a session backup from the session overview.
 *
 * Copies a rolling dump whose name matches this session number to
 * {name}_locked (and {name}_photos → {name}_locked_photos when present).
 * Filesystem only — does not restore or modify the live database.
 */
require_once __DIR__ . '/session_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /sts/session.php');
    exit;
}

$session = (int) ($_POST['session'] ?? 0);
$backup = trim((string) ($_POST['backup'] ?? ''));
$result = session_lock_backup($session, $backup !== '' ? $backup : null);

$dest = '/sts/session_overview.php?session=' . max(0, $session);
if (empty($result['ok'])) {
    $msg = rawurlencode((string) ($result['message'] ?? 'Lock backup failed.'));
    header('Location: ' . $dest . '&lock_error=' . $msg);
    exit;
}

$msg = rawurlencode((string) ($result['message'] ?? 'Backup locked.'));
header('Location: ' . $dest . '&lock_ok=' . $msg);
exit;
