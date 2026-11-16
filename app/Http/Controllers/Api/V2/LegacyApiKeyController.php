<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\WriteLegacyApiKeyRequest;
use App\Services\LegacyApiKeys\LegacyKeyService;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class LegacyApiKeyController extends Controller
{
    public function __construct(private readonly LegacyKeyService $keys) {}

    public function index(Request $request): JsonResponse
    {
        $this->keys->authorize(RequestContext::requirePrincipal($request));
        $list = ListQuery::fromRequest($request, ['id' => 'id', 'description' => 'description', 'accessLevel' => 'access', 'active' => 'active', 'service' => 'service'], 'id');

        return response()->json($list->paginate($list->applySearch(DB::table('api'), ['description']), $this->keys->present(...), 'id'));
    }

    public function show(Request $request, int $legacyKeyId): JsonResponse
    {
        $this->keys->authorize(RequestContext::requirePrincipal($request));

        return $this->response($this->keys->present($this->keys->row($legacyKeyId)));
    }

    public function store(WriteLegacyApiKeyRequest $request): JsonResponse
    {
        $data = $this->keys->save(null, $request->validated(), RequestContext::requirePrincipal($request), null);

        return $this->response($data, 201)->header('Location', '/api/v2/legacy-api-keys/'.$data['id']);
    }

    public function update(WriteLegacyApiKeyRequest $request, int $legacyKeyId): JsonResponse
    {
        return $this->response($this->keys->save($legacyKeyId, $request->validated(), RequestContext::requirePrincipal($request), $request->header('If-Match')));
    }

    public function regenerate(Request $request, int $legacyKeyId): JsonResponse
    {
        return $this->response($this->keys->save($legacyKeyId, [], RequestContext::requirePrincipal($request), $request->header('If-Match'), true));
    }

    public function destroy(Request $request, int $legacyKeyId): Response
    {
        $this->keys->delete($legacyKeyId, RequestContext::requirePrincipal($request), $request->header('If-Match'));

        return response()->noContent();
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status, ['ETag' => $data['etag'], 'Cache-Control' => 'no-store']);
    }
}
