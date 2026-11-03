<?php
/**
 * Response Helper Functions
 *
 * Used by the wagon handlers. Under the legacy routes they answer with the old
 * bare shapes ({...} / {"error": "msg"}). When the router sets
 * $GLOBALS['api_format'] = 'v1' (the /v1/cars/{car}/load etc. routes) the same
 * calls emit the v1 envelope instead.
 */

/**
 * Send a JSON success response
 */
function jsonResponse($data, $statusCode = 200) {
    if (($GLOBALS['api_format'] ?? 'legacy') === 'v1' && function_exists('api_ok')) {
        api_ok($data, null, $statusCode);
    }
    $GLOBALS['api_responded'] = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Send a JSON error response
 */
function jsonError($message, $statusCode = 400) {
    if (($GLOBALS['api_format'] ?? 'legacy') === 'v1' && function_exists('api_error')) {
        api_error($statusCode, $message);
    }
    $GLOBALS['api_responded'] = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}
?>
