<?php
declare(strict_types=1);
$port = getenv('PORT') ?: getenv('SERVER_PORT') ?: '8000';
if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) { fwrite(STDERR, "Invalid server port\n"); exit(1); }
passthru(escapeshellarg(PHP_BINARY).' artisan serve --host=0.0.0.0 --port='.escapeshellarg($port), $code);
exit($code);
