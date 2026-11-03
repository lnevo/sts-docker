<?php
// Kept so old links and bookmarks work: the report now lives in
// display_car_qr_report.php, which runs it when given the same query string.
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: display_car_qr_report.php' . ($qs !== '' ? '?' . $qs : ''), true, 302);
exit;
