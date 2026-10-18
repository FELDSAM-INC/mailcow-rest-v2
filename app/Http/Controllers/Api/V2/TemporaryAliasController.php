<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\ValidationProblemException;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Http\Requests\Api\V2\StoreTemporaryAliasRequest;
use App\Http\Requests\Api\V2\UpdateTemporaryAliasRequest;
use App\Rules\QueryBoolean;
use App\Services\SelfService\MailboxAccess;
use App\Services\TemporaryAliases\TemporaryAliasService;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listTemporaryAliases, createTemporaryAlias, getTemporaryAlias, updateTemporaryAlias,
 * deleteTemporaryAlias and listTemporaryAliasDomains (feature 005 FR-042–FR-046, FR-081): the
 * mailbox in the caller's scope, reads without a flag, changes with `spamAlias`.
 */
class TemporaryAliasController extends Controller
{
    public function __construct(
        private readonly TemporaryAliasService $aliases,
        private readonly MailboxAccess $access,
    ) {}

    public function index(Request $request, string $mailbox): JsonResponse
    {
        $address = $this->access->resolve(RequestContext::requirePrincipal($request), $mailbox);
        $query = ListQuery::fromRequest($request, TemporaryAliasService::SORT_COLUMNS, 'createdAt', $request->query('sort') === null ? 'desc' : 'asc');
        $validator = Validator::make($request->query(), ['permanent' => ['bail', 'sometimes', new QueryBoolean]]);

        if ($validator->fails()) {
            throw new ValidationProblemException(ApiFormRequest::violations($validator));
        }

        $permanent = $request->query('permanent');

        return response()->json($this->aliases->list($address, $query, is_string($permanent) ? $permanent === 'true' : null), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, string $mailbox, string $temporaryAlias): JsonResponse
    {
        $address = $this->access->resolve(RequestContext::requirePrincipal($request), $mailbox);

        return $this->respond($this->aliases->find($address, $temporaryAlias) ?? throw new NotFoundHttpException('The temporary alias does not exist.'));
    }

    public function store(StoreTemporaryAliasRequest $request, TemporaryAliasService $aliases): JsonResponse
    {
        // Resolved per request: the address generator holds per-request state in tests.
        $alias = $aliases->create(RequestContext::requirePrincipal($request), $request->mailbox(), $request->members());

        return $this->respond($alias, 201)->header('Location', '/api/v2/mailboxes/'.$request->mailbox().'/temporary-aliases/'.$alias['address']);
    }

    public function update(UpdateTemporaryAliasRequest $request, string $mailbox, string $temporaryAlias): JsonResponse
    {
        return $this->respond($this->aliases->update($request, RequestContext::requirePrincipal($request), $request->mailbox(), $temporaryAlias, $request->patch()));
    }

    public function destroy(Request $request, string $mailbox, string $temporaryAlias): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->aliases->delete($request, $principal, $this->access->resolve($principal, $mailbox), $temporaryAlias);

        return response()->noContent();
    }

    public function domains(Request $request, string $mailbox): JsonResponse
    {
        $address = $this->access->resolve(RequestContext::requirePrincipal($request), $mailbox);
        $query = ListQuery::fromRequest($request, ['domain' => 'domain'], 'domain');
        $entries = $this->aliases->domains($address);

        if ($query->search !== null) {
            $needle = mb_strtolower($query->search);
            $entries = array_values(array_filter($entries, fn (array $e): bool => str_contains(mb_strtolower($e['domain']), $needle) || str_contains(mb_strtolower($e['domainUnicode']), $needle)));
        }

        return response()->json([
            'data' => array_slice($entries, $query->offset, $query->limit),
            'meta' => $query->meta(count($entries)),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $alias
     */
    private function respond(array $alias, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $alias], $status, ['ETag' => $alias['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
