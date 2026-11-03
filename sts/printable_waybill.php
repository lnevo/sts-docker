<?php
// Kept so old links and bookmarks work: the waybill now lives in
// display_waybill.php, which runs it when given the waybill number as a GET
// parameter. This page used to take a POST, so accept either.
$params = $_GET;
if (isset($_POST['waybill_number'])) {
  $params['waybill_number'] = $_POST['waybill_number'];
}
if (isset($_POST['display_btn']) || isset($_GET['display_btn'])) {
  $params['display_btn'] = $_POST['display_btn'] ?? $_GET['display_btn'];
}
$qs = http_build_query($params);
header('Location: display_waybill.php' . ($qs !== '' ? '?' . $qs : ''), true, 302);
exit;
