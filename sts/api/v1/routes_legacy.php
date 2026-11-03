<?php
/**
 * Legacy wagon routes: /sts/api/index.php/wagon/... and /sts/api/wagon/...
 * Same paths, methods and bare response shapes as before, on the old handlers.
 * Auth: open unless STS_API_LEGACY_OPEN=0, then operator (RFID scanners).
 */
if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

function api_legacy_wagon_handler(array $req)
{
    require_once dirname(__DIR__) . '/endpoints/wagon.php';
    $parts = array_values(array_filter(explode('/', $req['params']['rest'] ?? ''), 'strlen'));
    handleWagonEndpoint($req['method'], $parts);
}

api_route('*', '/wagon', 'api_legacy_wagon_handler', 'operator', ['legacy' => true]);
api_route('*', '/wagon/{rest*}', 'api_legacy_wagon_handler', 'operator', ['legacy' => true]);
