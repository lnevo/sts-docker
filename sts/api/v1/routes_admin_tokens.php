<?php
/** Admin: token management. Tokens are shown once, at creation. */
if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

api_route('GET', '/admin/tokens', static function ($req) {
    return api_tokens_public_list();
}, 'admin', ['summary' => 'List API tokens (hashes are never returned)']);

api_route('POST', '/admin/tokens', static function ($req) {
    $body = api_body();
    $name = isset($body['name']) && is_string($body['name']) ? $body['name'] : '';
    $role = isset($body['role']) && is_string($body['role']) ? $body['role'] : '';
    try {
        $rec = api_token_create($name, $role);
    } catch (InvalidArgumentException $e) {
        api_error(422, $e->getMessage());
    } catch (RuntimeException $e) {
        api_internal_error($e->getMessage());
    }
    api_ok($rec, null, 201);
}, 'admin', ['summary' => 'Create an API token (returned once)', 'maint' => 'none']);

api_route('DELETE', '/admin/tokens/{id}', static function ($req) {
    try {
        $rec = api_token_revoke($req['params']['id']);
    } catch (RuntimeException $e) {
        api_internal_error($e->getMessage());
    }
    if ($rec === null) {
        api_error(404, 'No such token');
    }
    return $rec;
}, 'admin', ['summary' => 'Revoke an API token', 'maint' => 'none']);
