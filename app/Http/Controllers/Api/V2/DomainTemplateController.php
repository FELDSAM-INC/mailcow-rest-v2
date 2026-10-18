<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListDomainTemplatesRequest;
use App\Http\Requests\Api\V2\StoreDomainTemplateRequest;
use App\Http\Requests\Api\V2\UpdateDomainTemplateRequest;
use App\Models\Mailcow\DomainTemplate;
use App\Services\DomainTemplates\DomainTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Domain templates (FR-045–FR-050): administrators only, read-only keys may read. The role is
 * checked before the template is looked up.
 */
class DomainTemplateController extends Controller
{
    public function __construct(private readonly DomainTemplateService $templates) {}

    public function index(ListDomainTemplatesRequest $request): JsonResponse
    {
        return response()->json($this->templates->list($request->listQuery()), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, string $templateId): JsonResponse
    {
        Gate::forUser(RequestContext::requirePrincipal($request))->authorize('view', DomainTemplate::class);
        $row = $this->templates->find((int) $templateId) ?? throw new NotFoundHttpException('The domain template does not exist.');

        return $this->single($this->templates->present($row));
    }

    public function store(StoreDomainTemplateRequest $request): JsonResponse
    {
        $row = $this->templates->create(RequestContext::requirePrincipal($request), $request->members());
        $data = $this->templates->present($row);

        return $this->single($data, 201)->header('Location', '/api/v2/domain-templates/'.$data['id']);
    }

    public function update(UpdateDomainTemplateRequest $request, string $templateId): JsonResponse
    {
        $row = $this->templates->update(RequestContext::requirePrincipal($request), (int) $templateId, $request->members(), $request->header('If-Match'));

        return $this->single($this->templates->present($row));
    }

    public function destroy(Request $request, string $templateId): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('delete', DomainTemplate::class);
        $this->templates->delete($principal, (int) $templateId, $request->header('If-Match'));

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function single(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status, ['ETag' => (string) $data['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
