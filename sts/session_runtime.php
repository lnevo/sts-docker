<?php
/**
 * Bootstrap runtime for the session editor and workflow simulator.
 *
 * Loads session_simulator_ops.php (filtered fill/reposition/load-unload) and, when
 * present, warm_start_helpers.php for catalog dispatch (pick up, set out, staging, etc.).
 */

function session_runtime_bootstrap()
{
    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }
    $bootstrapped = true;

    $warm = __DIR__ . '/warm_start_helpers.php';
    if (is_readable($warm)) {
        require_once $warm;
    }

    $ops = __DIR__ . '/session_simulator_ops.php';
    if (is_readable($ops)) {
        require_once $ops;
    }
}

function session_runtime_available()
{
    session_runtime_bootstrap();

    return function_exists('warm_start_get_session');
}

/** Current session number from DB (works without warm_start_helpers). */
function session_get_db_session($dbc)
{
    if (function_exists('warm_start_get_session')) {
        return (int) warm_start_get_session($dbc);
    }
    $rs = mysqli_query($dbc, 'SELECT setting_value FROM settings WHERE setting_name = "session_nbr"');
    $row = $rs ? mysqli_fetch_array($rs) : null;

    return (int) ($row['setting_value'] ?? 0);
}

function session_runtime_notice()
{
    return 'Session simulator dispatch requires warm_start_helpers.php in sts/. Rebuild the web image (docker compose --profile build up -d --build).';
}
