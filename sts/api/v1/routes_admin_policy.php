<?php
/** Admin: command roles and the open flags (lib/policy.php). */
if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/openapi.php';

api_route('GET', '/admin/policy', static function ($req) {
    return api_policy_get(api_catalog_command_ids());
}, 'admin', ['summary' => 'Effective policy: command roles and open flags']);

api_route('PUT', '/admin/policy', static function ($req) {
    $errors = api_policy_put(api_body(), api_catalog_command_ids());
    if ($errors) {
        api_error(422, implode('; ', $errors));
    }
    return api_policy_get(api_catalog_command_ids());
}, 'admin', ['summary' => 'Change command roles and open flags', 'maint' => 'none']);
