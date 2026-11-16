<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\WriteSyncSourceRequest;
use App\Services\SyncSources\SourceReader;
use App\Services\SyncSources\SourceScope;
use App\Services\SyncSources\SourceService;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SyncSourceController extends Controller
{
    public function __construct(private readonly SourceScope $scope, private readonly SourceReader $reader, private readonly SourceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $actor = RequestContext::requirePrincipal($request);
        $this->scope->authorize($actor);
        $list = ListQuery::fromRequest($request, ['id' => 's.id', 'name' => 's.name', 'remoteHost' => 's.host1', 'scope' => 's.scope', 'active' => 's.active'], 'name');
        $query = $list->applySearch($this->scope->query($actor), ['s.name', 's.host1', 's.description']);

        return response()->json($list->paginate($query, fn ($row): array => $this->reader->present($row, $actor), 's.id'));
    }

    public function show(Request $request, int $sourceId): JsonResponse
    {
        $actor = RequestContext::requirePrincipal($request);
        $this->scope->authorize($actor);

        return $this->response($this->reader->present($this->reader->row($sourceId, $actor), $actor));
    }

    public function store(WriteSyncSourceRequest $request): JsonResponse
    {
        $data = $this->service->save(null, $request->values(), RequestContext::requirePrincipal($request), null);

        return $this->response($data, 201)->header('Location', '/api/v2/sync-sources/'.$data['id']);
    }

    public function update(WriteSyncSourceRequest $request, int $sourceId): JsonResponse
    {
        return $this->response($this->service->save($sourceId, $request->values(), RequestContext::requirePrincipal($request), $request->header('If-Match')));
    }

    public function destroy(Request $request, int $sourceId): Response
    {
        $this->service->delete($sourceId, RequestContext::requirePrincipal($request), $request->header('If-Match'));

        return response()->noContent();
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status, ['ETag' => $data['etag']]);
    }
}
