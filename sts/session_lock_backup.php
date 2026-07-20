<?php
/**
 * Lock session backup(s) from the session overview.
 *
 * With no backup selected, freezes every scheme dump for the session
 * (hart_session_pre{N} + hart_session_post{N}, plus legacy hart_session{N})
 * to *_locked companions. Filesystem only — does not restore or modify the
 * live database.
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
