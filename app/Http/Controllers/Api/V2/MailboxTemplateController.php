<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\StoreMailboxTemplateRequest;
use App\Http\Requests\Api\V2\UpdateMailboxTemplateRequest;
use App\Models\Mailcow\MailboxTemplate;
use App\Services\Mailboxes\MailboxTemplateService;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listMailboxTemplates, getMailboxTemplate, createMailboxTemplate, updateMailboxTemplate and
 * deleteMailboxTemplate (feature 003 FR-049–FR-053): administrators manage templates, domain
 * administrators read them.
 */
class MailboxTemplateController extends Controller
{
    public function __construct(private readonly MailboxTemplateService $templates) {}

    public function index(Request $request): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('viewAny', MailboxTemplate::class);

        return response()->json($this->templates->list($principal, ListQuery::fromRequest($request, MailboxTemplateService::SORTS, 'name')), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, int $mailboxTemplateId): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('view', MailboxTemplate::class);
        $row = $this->templates->find($mailboxTemplateId) ?? throw new NotFoundHttpException('The mailbox template does not exist.');

        return self::response($this->templates->present($row, $principal));
    }

    public function store(StoreMailboxTemplateRequest $request): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $row = $this->templates->create($principal, $request->members());

        return self::response($this->templates->present($row, $principal), 201)->header('Location', '/api/v2/mailbox-templates/'.$row->id);
    }

    public function update(UpdateMailboxTemplateRequest $request, int $mailboxTemplateId): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $row = $this->templates->update($principal, $mailboxTemplateId, $request->members(), $request->header('If-Match'));

        return self::response($this->templates->present($row, $principal));
    }

    public function destroy(Request $request, int $mailboxTemplateId): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('delete', MailboxTemplate::class);
        $this->templates->delete($principal, $mailboxTemplateId, $request->header('If-Match'));

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status, ['ETag' => (string) $data['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
