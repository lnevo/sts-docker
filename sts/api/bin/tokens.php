<?php
/**
 * API token CLI.
 *
 *   php sts/api/bin/tokens.php list
 *   php sts/api/bin/tokens.php create <name> <role>     (prints the token once)
 *   php sts/api/bin/tokens.php revoke <id>
 *   php sts/api/bin/tokens.php bootstrap                (used by start.sh)
 *
 * Output is JSON. Exit code 0 on success, 1 on usage errors, 2 on failures.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/tokens.php';

function tokens_out($data)
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

$cmd = $argv[1] ?? '';
try {
    switch ($cmd) {
        case 'list':
            tokens_out(api_tokens_public_list());
            break;
        case 'create':
            if (count($argv) < 4) {
                fwrite(STDERR, "usage: tokens.php create <name> <reader|operator|editor|admin>\n");
                exit(1);
            }
            tokens_out(api_token_create($argv[2], $argv[3]));
            break;
        case 'revoke':
            if (count($argv) < 3) {
                fwrite(STDERR, "usage: tokens.php revoke <id>\n");
                exit(1);
            }
            $rec = api_token_revoke($argv[2]);
            if ($rec === null) {
                fwrite(STDERR, "no such token: {$argv[2]}\n");
                exit(2);
            }
            tokens_out($rec);
            break;
        case 'bootstrap':
            $res = api_tokens_bootstrap();
            echo $res['created']
                ? "Bootstrap admin token written to {$res['path']}\n"
                : "Active admin token exists; nothing to do\n";
            break;
        default:
            fwrite(STDERR, "usage: tokens.php list|create <name> <role>|revoke <id>|bootstrap\n");
            exit(1);
    }
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(2);
}
