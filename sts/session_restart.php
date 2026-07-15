<?php
/**
 * Restart the current (last) operating session from the session overview.
 *
 * Restores a backup whose name contains the prior session number (end of N-1 =
 * start of N, before workflow steps) and removes session_N+ output trees.
 * POST only; requires confirm on the form. Optional "backup" selects among
 * multiple matching dumps.
 */
require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/session_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /sts/session.php');
    exit;
}

$session = (int) ($_POST['session'] ?? 0);
$backup = trim((string) ($_POST['backup'] ?? ''));
$dbc = open_db();
$result = session_restart_operating_session($dbc, $session, null, $backup !== '' ? $backup : null);
mysqli_close($dbc);

if (empty($result['ok'])) {
    $msg = rawurlencode((string) ($result['message'] ?? 'Restart failed.'));
    header('Location: /sts/session_overview.php?session=' . max(1, $session) . '&restart_error=' . $msg);
    exit;
}

$prev = (int) ($result['previous_session'] ?? max(0, $session - 1));
$msg = rawurlencode((string) ($result['message'] ?? 'Session restarted.'));
if ($prev >= 1) {
    header('Location: /sts/session_overview.php?session=' . $prev . '&restart_ok=' . $msg);
} else {
    header('Location: /sts/session.php?restart_ok=' . $msg);
}
exit;
