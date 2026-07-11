<?php
/**
 * Deprecated consolidated switch-list index. The per-session switch lists are
 * now browsed via the session overview (trains → job.php) and printed via the
 * session-wide "Print all switch lists" page. This endpoint now redirects to the
 * session overview so any existing links/bookmarks keep working.
 */
$session = (int) ($_GET['session'] ?? 0);
if ($session < 1) {
    header('Location: session.php');
    exit;
}
header('Location: session_overview.php?session=' . $session, true, 301);
exit;
