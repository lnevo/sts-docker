<?php
/**
 * Per-session overview page (layout owner view). Reads generated output from
 * the session output root (sts/backups/session_state/sessions; see
 * session_web_root()) and renders it under a clean URL:
 * session_overview.php?session=N. The shared markup lives in
 * session_index_template.php.
 */
require __DIR__ . '/session_index_template.php';
