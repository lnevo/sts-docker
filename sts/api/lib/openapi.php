<?php
/**
 * Merge the OpenAPI fragments in sts/api/openapi/*.json into one 3.1 document.
 * base.json provides info/servers/security schemes; every other fragment adds
 * `paths` and `components`. The catalog command ids are injected as the enum of
 * components.schemas.CommandId (operate routes reference it for {command}).
 */

if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

function api_openapi_merge(array $a, array $b)
{
    foreach ($b as $k => $v) {
        if (is_array($v) && isset($a[$k]) && is_array($a[$k]) && array_keys($v) !== range(0, count($v) - 1)) {
            $a[$k] = api_openapi_merge($a[$k], $v);
        } else {
            $a[$k] = $v;
        }
    }
    return $a;
}

/** Catalog command ids (runnable commands, including plugin-supplied ones). */
function api_catalog_command_ids()
{
    require_once dirname(__DIR__, 2) . '/operational_steps_catalog.php';
    $ids = [];
    foreach (array_merge(operational_steps_catalog_definitions(), operational_steps_catalog_adder_definitions()) as $def) {
        if (!empty($def['id'])) {
            $ids[$def['id']] = true;
        }
    }
    $ids = array_keys($ids);
    sort($ids);
    return $ids;
}

function api_openapi_build()
{
    $dir = dirname(__DIR__) . '/openapi';
    $spec = json_decode((string) file_get_contents($dir . '/base.json'), true) ?: [];
    $fragments = glob($dir . '/*.json') ?: [];
    sort($fragments);
    foreach ($fragments as $file) {
        if (basename($file) === 'base.json') {
            continue;
        }
        $frag = json_decode((string) file_get_contents($file), true);
        if (!is_array($frag)) {
            error_log('[sts-api] unreadable OpenAPI fragment ' . basename($file));
            continue;
        }
        $spec = api_openapi_merge($spec, array_intersect_key($frag, ['paths' => 1, 'components' => 1, 'tags' => 1]));
    }
    if (empty($spec['paths'])) {
        $spec['paths'] = new stdClass();
    }
    $spec['components']['schemas']['CommandId'] = [
        'type' => 'string',
        'description' => 'Operational steps catalog command id (GET /catalog). destructive: restore_database, wipe, reset, backup_database need admin.',
        'enum' => api_catalog_command_ids(),
    ];
    ksort($spec['paths']);
    // json_decode(assoc) turns the empty security requirement {} into []; restore it.
    foreach ($spec['paths'] as &$item) {
        foreach ($item as &$op) {
            if (is_array($op) && isset($op['security'])) {
                foreach ($op['security'] as &$req) {
                    if ($req === []) {
                        $req = new stdClass();
                    }
                }
                unset($req);
            }
        }
        unset($op);
    }
    unset($item);
    return $spec;
}
