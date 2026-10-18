<?php

namespace App\Logging;

use App\Http\RequestContext;
use Illuminate\Http\Request;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Adds the request context to every log record (R24): `request_id`, `route`, `method`,
 * `principal` (`username (role)` or `apiKey:<id>`) and `client_ip`.
 */
class RequestContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            $request = app()->bound('request') ? app('request') : null;
        } catch (Throwable) {
            $request = null;
        }

        if (! $request instanceof Request) {
            return $record;
        }

        return $record->with(extra: [
            ...$record->extra,
            'request_id' => RequestContext::requestId($request),
            'route' => $request->route()?->uri(),
            'method' => $request->getMethod(),
            'principal' => RequestContext::principal($request)?->logLabel(),
            'client_ip' => $request->ip(),
        ]);
    }
}
