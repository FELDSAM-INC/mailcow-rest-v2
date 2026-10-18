<?php

namespace App\Http;

use App\Auth\Principal;
use Illuminate\Http\Request;

/**
 * Per-request values shared by middleware, controllers, the problem renderer, the audit logger
 * and the log processor, kept in the request's attributes.
 */
final class RequestContext
{
    private const REQUEST_ID = 'restv2.requestId';

    private const PRINCIPAL = 'restv2.principal';

    private const API_KEY = 'restv2.apiKeyAuthenticated';

    private const LIVE_SESSION = 'restv2.liveSession';

    /**
     * The request ID (W3C trace ID): taken from a valid incoming `traceparent`, otherwise 16
     * random bytes in hex. Resolved once and kept, also for requests that match no route.
     */
    public static function requestId(Request $request): string
    {
        $id = $request->attributes->get(self::REQUEST_ID);

        if (is_string($id)) {
            return $id;
        }

        $id = self::traceIdFrom($request->headers->get('traceparent')) ?? bin2hex(random_bytes(16));
        $request->attributes->set(self::REQUEST_ID, $id);

        return $id;
    }

    public static function setRequestId(Request $request, string $requestId): void
    {
        $request->attributes->set(self::REQUEST_ID, $requestId);
    }

    /**
     * The trace ID of a valid `traceparent` header (`00-<32 hex>-<16 hex>-<2 hex>`, neither ID
     * all zeros), or null.
     */
    public static function traceIdFrom(?string $traceparent): ?string
    {
        if ($traceparent === null || preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', trim($traceparent), $m) !== 1) {
            return null;
        }

        if ($m[1] === 'ff' || $m[2] === str_repeat('0', 32) || $m[3] === str_repeat('0', 16)) {
            return null;
        }

        return $m[2];
    }

    /**
     * Marks the request as authenticated by an API key. Such a request never starts a session and
     * needs no anti-forgery token.
     */
    public static function setApiKeyPrincipal(Request $request, Principal $principal): void
    {
        $request->attributes->set(self::API_KEY, true);
        $request->attributes->set(self::PRINCIPAL, $principal);
    }

    public static function isApiKeyRequest(Request $request): bool
    {
        return $request->attributes->get(self::API_KEY) === true;
    }

    /**
     * Records whether the request arrived with a live session: a session that exists in Redis and
     * has not ended (R7).
     */
    public static function setLiveSession(Request $request, bool $live): void
    {
        $request->attributes->set(self::LIVE_SESSION, $live);
    }

    public static function hasLiveSession(Request $request): bool
    {
        return $request->attributes->get(self::LIVE_SESSION) === true;
    }

    public static function setPrincipal(Request $request, ?Principal $principal): void
    {
        $request->attributes->set(self::PRINCIPAL, $principal);
    }

    public static function principal(Request $request): ?Principal
    {
        $principal = $request->attributes->get(self::PRINCIPAL);

        return $principal instanceof Principal ? $principal : null;
    }
}
