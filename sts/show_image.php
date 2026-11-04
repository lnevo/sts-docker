<?php
  // Loads the rolling-stock photo popup script (strict CSP: no inline script).
  // The page must also load sts-actions.js (page_chrome.php does). Markup uses data-sts-click="showImage" data-car-id="..." data-marks="...".
  print '<script src="/sts/js/show_image.js?v=1"></script>' . "\n";
?>