<?php

namespace App\Support\Http;

use App\Http\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RFC 9457 problem details (`application/problem+json`): `type`, `title`, `status`, `detail`,
 * `requestId` and extension members.
 */
final class Problem
{
    public const CONTENT_TYPE = 'application/problem+json';

    /**
     * A documented problem type.
     *
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string>  $headers
     */
    public static function response(
        ProblemType $type,
        Request $request,
        ?string $detail = null,
        array $extensions = [],
        array $headers = [],
    ): JsonResponse {
        return self::build($type->uri(), $type->status(), $type->title(), $detail ?? $type->detail(), $request, $extensions, $headers);
    }

    /**
     * An `about:blank` problem, identified by its status code.
     *
     * @param  array<string, string>  $headers
     */
    public static function blank(int $status, Request $request, ?string $detail = null, array $headers = []): JsonResponse
    {
        $title = Response::$statusTexts[$status] ?? 'Error';

        return self::build('about:blank', $status, $title, $detail, $request, [], $headers);
    }

    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string>  $headers
     */
    private static function build(
        string $type,
        int $status,
        string $title,
        ?string $detail,
        Request $request,
        array $extensions,
        array $headers,
    ): JsonResponse {
        $body = ['type' => $type, 'title' => $title, 'status' => $status];

        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        $body['requestId'] = RequestContext::requestId($request);

        $response = new JsonResponse($body + $extensions, $status, $headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);

        return $response;
    }
}
