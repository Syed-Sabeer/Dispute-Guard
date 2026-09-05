<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;

// Run the suite in a newly created, isolated local MySQL database; never touch the configured app database.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = config('database.connections.mysql');
if (! in_array($db['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Only a local MySQL server is allowed by this test helper.\n");
    exit(1);
}
$name = 'chargeguard_test_'.bin2hex(random_bytes(6));
$path = dirname(__DIR__).'/phpunit.mysql.generated.xml';
$created = false;
try {
    $pdo = new PDO('mysql:host='.$db['host'].';port='.$db['port'].';charset=utf8mb4', $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $xml = new DOMDocument;
    $xml->load(dirname(__DIR__).'/phpunit.xml');
    foreach ($xml->getElementsByTagName('env') as $env) {
        if ($env->getAttribute('name') === 'DB_CONNECTION') {
            $env->setAttribute('value', 'mysql');
        }
        if ($env->getAttribute('name') === 'DB_DATABASE') {
            $env->setAttribute('value', $name);
        }
        putenv($env->getAttribute('name').'='.$env->getAttribute('value'));
    }
    putenv('DATABASE_URL');
    $xml->save($path);
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/vendor/phpunit/phpunit/phpunit').' -c '.escapeshellarg($path).' --stop-on-error', $code);
} catch (Throwable $e) {
    fwrite(STDERR, "Local isolated MySQL validation could not run (connection or permission issue).\n");
    $code = 1;
} finally {
    if ($created) {
        $pdo->exec('DROP DATABASE `'.$name.'`');
    }
    if (is_file($path)) {
        unlink($path);
    }
}
exit($code);
