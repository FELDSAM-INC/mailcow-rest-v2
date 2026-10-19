<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

/*
 * Feature 006 T063: handles one JSON request through the HTTP kernel of the testing environment
 * and prints the response status (concurrency tests with separate database connections).
 *
 *   php tests/bin/send-request-once.php <method> <path> <X-API-Key> [<JSON body>]
 */

if ($argc < 4) {
    fwrite(STDERR, "usage: send-request-once.php <method> <path> <X-API-Key> [<JSON body>]\n");
    exit(64);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);

$request = Request::create($argv[2], $argv[1], [], [], [], [
    'HTTP_X_API_KEY' => $argv[3],
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
    'REMOTE_ADDR' => '127.0.0.1',
], $argv[4] ?? null);

$response = $kernel->handle($request);
echo $response->getStatusCode();
$kernel->terminate($request, $response);
