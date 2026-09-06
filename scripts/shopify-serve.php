<?php

declare(strict_types=1);
$port = getenv('PORT') ?: getenv('SERVER_PORT') ?: '8000';
if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
    fwrite(STDERR, "Invalid server port\n");
    exit(1);
}
// Laravel's reload mode removes inherited variables, including the URL and
// credentials supplied by Shopify CLI. Preserve them in the HTTP worker.
passthru(escapeshellarg(PHP_BINARY).' artisan serve --no-reload --host=0.0.0.0 --port='.escapeshellarg($port), $code);
exit($code);
