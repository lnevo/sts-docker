<?php
// Kept so old links and bookmarks work: the report now lives in
// display_switchlist.php, which runs it when given the same parameters (GET or
// POST). The x2010 layout is picked there by format=x2010.
$params = $_GET + $_POST;
$qs = http_build_query($params);
header('Location: display_switchlist.php' . ($qs !== '' ? '?' . $qs : ''), true, 302);
exit;
