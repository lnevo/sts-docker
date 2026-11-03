<?php
// Kept so old links and bookmarks work: the report now lives in
// display_switchlist.php, which runs it when given the same parameters (GET or
// POST). This page always showed the x2010 layout, so format=x2010 is forced.
$params = $_GET + $_POST;
$params['format'] = 'x2010';
$qs = http_build_query($params);
header('Location: display_switchlist.php' . ($qs !== '' ? '?' . $qs : ''), true, 302);
exit;
