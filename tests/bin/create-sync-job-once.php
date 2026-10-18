<?php

/*
 * Feature 008 T046: handles one `POST /api/v2/sync-jobs` through the HTTP kernel of the testing
 * environment and prints the response status (tests/Feature/SyncJobs/ConcurrentCreateSyncJobTest).
 *
 *   php tests/bin/create-sync-job-once.php <X-API-Key> <JSON body>
 */

if ($argc !== 3) {
    fwrite(STDERR, "usage: create-sync-job-once.php <X-API-Key> <JSON body>\n");
    exit(64);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

require __DIR__.'/../../vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$request = Illuminate\Http\Request::create('/api/v2/sync-jobs', 'POST', [], [], [], [
    'HTTP_X_API_KEY' => $argv[1],
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
    'REMOTE_ADDR' => '127.0.0.1',
], $argv[2]);

$response = $kernel->handle($request);
echo $response->getStatusCode();
$kernel->terminate($request, $response);
