<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\SyncSourceOAuthRequest;
use App\Services\SyncSources\SourceOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class SyncSourceOAuthController extends Controller
{
    public function __construct(private readonly SourceOAuth $oauth) {}

    public function start(SyncSourceOAuthRequest $request, int $sourceId): JsonResponse
    {
        return response()->json(['data' => $this->oauth->start($request, $sourceId, $request->value('mailbox'))]);
    }

    public function complete(SyncSourceOAuthRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->oauth->complete($request, $request->value('state'), $request->value('code'))]);
    }

    public function connections(Request $request, int $sourceId): JsonResponse
    {
        $owner = $request->query('mailbox');
        if (! is_string($owner) || ! filter_var($owner, FILTER_VALIDATE_EMAIL)) {
            throw new BadRequestHttpException('A mailbox address is required.');
        }

        return response()->json(['data' => $this->oauth->connections($sourceId, $owner, RequestContext::requirePrincipal($request))]);
    }

    public function disconnect(SyncSourceOAuthRequest $request, int $sourceId): Response
    {
        $this->oauth->disconnect($sourceId, $request->value('mailbox'), $request->value('remoteUser'), RequestContext::requirePrincipal($request));

        return response()->noContent();
    }

    public function refresh(Request $request, int $sourceId): JsonResponse
    {
        $data = $this->oauth->refresh($sourceId, RequestContext::requirePrincipal($request), $request->header('If-Match'));

        return response()->json(['data' => $data], 200, ['ETag' => $data['etag']]);
    }
}
