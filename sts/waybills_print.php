<?php
// Print-only page for the waybills on one train's switch-list phase (work leg).
// Rendered from the frozen waybill snapshot store so it always matches the list
// shown on job.php. Callers pass the workflow phase (wp) and work leg (leg);
// each job.php leg links here with its own wp/leg so the link tracks the phase.

$session = (int) ($_GET['session'] ?? 0);
$job = trim($_GET['job'] ?? '');
$wp = (int) ($_GET['wp'] ?? 0);
$leg = (int) ($_GET['leg'] ?? 0);
$style = trim($_GET['style'] ?? '');

if ($session <= 0 || $job === '') {
    header('Location: session_overview.php');
    exit;
}

require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/session_helpers.php';
require_once __DIR__ . '/waybill_print_helpers.php';

$root = session_web_root();
$dbc = open_db();
$legs = session_train_switchlist_legs($dbc, $session, $job, $root);
mysqli_close($dbc);

// Pick the numbers to print: a specific work leg when wp+leg match, otherwise
// all legs in the requested workflow phase, otherwise the whole train.
$numbers = [];
$scope_label = 'all phases';
$matched = false;
foreach ($legs as $lg) {
    $lg_wp = (int) ($lg['workflow_phase'] ?? 0);
    $lg_leg = (int) ($lg['work_leg'] ?? 0);
    $take = false;
    if ($wp > 0 && $leg > 0) {
        $take = ($lg_wp === $wp && $lg_leg === $leg);
    } elseif ($wp > 0) {
        $take = ($lg_wp === $wp);
    } else {
        $take = true;
    }
    if (!$take) {
        continue;
    }
    $matched = true;
    if ($wp > 0 && $leg > 0) {
        $scope_label = 'Phase ' . $lg_leg . ' of ' . (int) ($lg['work_leg_total'] ?? $lg_leg);
    } elseif ($wp > 0) {
        $scope_label = 'workflow phase ' . $lg_wp;
    }
    foreach ($lg['waybills'] ?? [] as $wb) {
        $num = (string) ($wb['number'] ?? '');
        if ($num !== '' && !in_array($num, $numbers, true)) {
            $numbers[] = $num;
        }
    }
}

$store = session_waybill_store_load($session, $root);

$back_href = 'job.php?session=' . (int) $session . '&job=' . urlencode($job);
if ($wp > 0) {
    $back_href .= '&wp=' . (int) $wp;
}
if ($leg > 0) {
    $back_href .= '&leg=' . (int) $leg;
}
if ($style !== '') {
    $back_href .= '&style=' . urlencode($style);
}

$title = htmlspecialchars($job) . ' waybills — session ' . (int) $session;

$sheets = '';
$missing = [];
foreach ($numbers as $num) {
    $body = $store['bodies'][$num] ?? '';
    if (trim((string) $body) !== '') {
        $sheets .= '<div class="waybill-sheet">' . $body . '</div>';
    } else {
        $missing[] = $num;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $title; ?></title>
  <style><?php echo waybill_print_page_styles(); ?></style>
  <style>
    .wb-toolbar { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; margin: 12px; }
    .wb-toolbar a, .wb-toolbar button { font-size: 14px; }
    .wb-toolbar .wb-title { font-weight: 600; margin-right: 12px; }
    .wb-empty { font-family: system-ui, sans-serif; margin: 24px 12px; color: #555; }
    .wb-missing { font-family: system-ui, sans-serif; margin: 12px; color: #a00; font-size: 13px; }
  </style>
</head>
<body>
  <div class="noprint wb-toolbar">
    <span class="wb-title"><?php echo htmlspecialchars($job); ?> — <?php echo htmlspecialchars($scope_label); ?></span>
    <button type="button" onclick="window.print()">Print all waybills</button>
    &nbsp; <a href="<?php echo htmlspecialchars($back_href); ?>">&larr; Back to switch lists</a>
  </div>
  <?php if ($missing): ?>
    <div class="noprint wb-missing">
      Print file not generated for: <?php echo htmlspecialchars(implode(', ', $missing)); ?>.
      Run <em>Generate Waybills</em> in the Session Editor workflow.
    </div>
  <?php endif; ?>
  <?php if ($sheets !== ''): ?>
    <?php echo $sheets; ?>
  <?php else: ?>
    <p class="wb-empty">
      <?php echo $matched
        ? 'No waybills snapshotted for this switch list yet.'
        : 'No switch list found for this phase.'; ?>
    </p>
  <?php endif; ?>
</body>
</html>
