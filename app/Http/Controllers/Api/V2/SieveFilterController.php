<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Role;
use App\Clients\Dovecot\SieveScriptReader;
use App\Clients\Dovecot\SieveScriptUnavailable;
use App\Exceptions\ProblemException;
use App\Exceptions\ValidationProblemException;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Http\Requests\Api\V2\StoreSieveFilterRequest;
use App\Http\Requests\Api\V2\UpdateSieveFilterRequest;
use App\Http\Requests\Api\V2\ValidateSieveScriptRequest;
use App\Rules\QueryBoolean;
use App\Services\SelfService\MailboxAccess;
use App\Services\SieveFilters\SieveFilterService;
use App\Support\Http\ListQuery;
use App\Support\Http\ProblemType;
use App\Support\Sieve\SieveValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Sieve filter operations of feature 005 (FR-047 to FR-053): listSieveFilters (every mailbox in
 * scope), the filters of one mailbox (list, get, create, change, delete), validateSieveScript and
 * getActiveSieveScript. Reads need no flag; changes need `filters` (SieveFilterService).
 */
class SieveFilterController extends Controller
{
    public function __construct(
        private readonly SieveFilterService $filters,
        private readonly MailboxAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);

        if ($principal->role === Role::MailboxUser) {
            throw new AccessDeniedHttpException('Mailbox users list their filters under their mailbox.');
        }

        $query = ListQuery::fromRequest($request, SieveFilterService::SORT_COLUMNS, 'mailbox');
        $filters = $this->filtersOf($request, [
            'mailbox' => ['bail', 'sometimes', 'string', 'max:255'],
            'id' => ['bail', 'sometimes', 'integer', 'between:1,4294967295'],
        ]);

        return $this->json($this->filters->listInScope($principal, $query, $filters));
    }

    public function mailboxIndex(Request $request, string $mailbox): JsonResponse
    {
        $address = $this->access->resolve(RequestContext::requirePrincipal($request), $mailbox);
        $query = ListQuery::fromRequest($request, SieveFilterService::SORT_COLUMNS, 'mailbox');

        return $this->json($this->filters->listOfMailbox($address, $query, $this->filtersOf($request, [])));
    }

    public function show(Request $request, string $mailbox, string $sieveFilterId): JsonResponse
    {
        $address = $this->access->resolve(RequestContext::requirePrincipal($request), $mailbox);

        return $this->respond($this->filters->find($address, (int) $sieveFilterId) ?? throw new NotFoundHttpException('The Sieve filter does not exist.'));
    }

    public function store(StoreSieveFilterRequest $request): JsonResponse
    {
        $filter = $this->filters->create(RequestContext::requirePrincipal($request), $request->mailbox(), $request->members());

        return $this->respond($filter, 201)->header('Location', '/api/v2/mailboxes/'.$request->mailbox().'/sieve-filters/'.$filter['id']);
    }

    public function update(UpdateSieveFilterRequest $request, string $mailbox, string $sieveFilterId): JsonResponse
    {
        return $this->respond($this->filters->update(RequestContext::requirePrincipal($request), $request->mailbox(), (int) $sieveFilterId, $request->members(), $request->header('If-Match')));
    }

    public function destroy(Request $request, string $mailbox, string $sieveFilterId): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $address = $this->access->resolve($principal, $mailbox);
        $this->filters->delete($principal, $address, (int) $sieveFilterId, $request->header('If-Match'));

        return response()->noContent();
    }

    public function validateScript(ValidateSieveScriptRequest $request, SieveValidator $validator): JsonResponse
    {
        $message = $validator->error($request->script());

        return $this->json(['data' => ['valid' => $message === null, 'message' => $message]]);
    }

    public function active(Request $request, string $mailbox, SieveScriptReader $reader): JsonResponse
    {
        $address = $this->access->resolve(RequestContext::requirePrincipal($request), $mailbox);

        try {
            $script = $reader->active($address);
        } catch (SieveScriptUnavailable $e) {
            throw new ProblemException(ProblemType::TemporaryFailure, 'The mail server cannot be reached. Retry later.', ['dependency' => 'mailStore'], ['Retry-After' => '10']);
        }

        return $this->json(['data' => ['mailbox' => $address, 'name' => $script->name, 'script' => $script->script]]);
    }

    /**
     * The validated `kind` and `active` filters, plus the given extra ones.
     *
     * @param  array<string, list<mixed>>  $extra
     * @return array{mailbox?: string|null, kind?: string|null, active?: bool|null, id?: int|null}
     */
    private function filtersOf(Request $request, array $extra): array
    {
        $validator = Validator::make($request->query(), [
            'kind' => ['bail', 'sometimes', 'string', 'in:'.implode(',', SieveFilterService::KINDS)],
            'active' => ['bail', 'sometimes', new QueryBoolean],
            ...$extra,
        ]);

        if ($validator->fails()) {
            throw new ValidationProblemException(ApiFormRequest::violations($validator));
        }

        $active = $request->query('active');
        $kind = $request->query('kind');
        $mailbox = $request->query('mailbox');
        $id = $request->query('id');

        return [
            'mailbox' => isset($extra['mailbox']) && is_string($mailbox) ? $mailbox : null,
            'kind' => is_string($kind) && $kind !== '' ? $kind : null,
            'active' => is_string($active) ? $active === 'true' : null,
            'id' => isset($extra['id']) && is_string($id) ? (int) $id : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function respond(array $filter, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $filter], $status, ['ETag' => $filter['etag']], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function json(array $body): JsonResponse
    {
        return response()->json($body, 200, [], JSON_UNESCAPED_UNICODE);
    }
}
