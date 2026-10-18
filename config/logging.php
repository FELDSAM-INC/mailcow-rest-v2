<?php

use App\Logging\RedactSecrets;
use App\Logging\RequestContextProcessor;
use App\Support\Config\Env;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;

/*
|--------------------------------------------------------------------------
| Logging (research R24)
|--------------------------------------------------------------------------
|
| One JSON line per event on stderr (`docker logs`). The processors of the `stderr_json` channel
| add the request context (request ID, route, method, principal, client address) and remove
| secrets from the context. LOG_STREAM redirects the stream (the test environment writes to
| storage/logs/testing.log).
|
*/

$stream = (string) Env::get('LOG_STREAM', 'php://stderr');

if (! str_contains($stream, '://') && ! str_starts_with($stream, '/')) {
    $stream = base_path($stream);
}

return [

    'default' => 'stderr_json',

    'deprecations' => [
        'channel' => 'null',
        'trace' => false,
    ],

    'channels' => [

        'stderr_json' => [
            'driver' => 'monolog',
            'level' => Env::get('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => $stream,
            ],
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'batchMode' => JsonFormatter::BATCH_MODE_NEWLINES,
                'appendNewline' => true,
                'includeStacktraces' => true,
            ],
            'processors' => [
                RequestContextProcessor::class,
                RedactSecrets::class,
            ],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

    ],

];
