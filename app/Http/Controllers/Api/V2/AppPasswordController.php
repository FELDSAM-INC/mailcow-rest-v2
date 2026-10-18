<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\ValidationProblemException;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Http\Requests\Api\V2\StoreAppPasswordRequest;
use App\Http\Requests\Api\V2\UpdateAppPasswordRequest;
use App\Rules\QueryBoolean;
use App\Services\AppPasswords\AppPasswordService;
use App\Services\SelfService\MailboxAccess;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listAppPasswords, createAppPassword, getAppPassword, updateAppPassword and deleteAppPassword
 * (feature 005 FR-037–FR-041): the mailbox in the caller's scope (`404` otherwise), reads without a
 * flag, changes with `appPasswds`.
 */
class AppPasswordController extends Controller
{
    public function __construct(
        private readonly AppPasswordService $appPasswords,
        private readonly MailboxAccess $access,
    ) {}

    public function index(Request $request, string $mailbox): JsonResponse
    {
        $address = $this->access->resolve(RequestContext::requirePrincipal($request), $mailbox);
        $query = ListQuery::fromRequest($request, AppPasswordService::SORT_COLUMNS, 'name');
        $validator = Validator::make($request->query(), ['active' => ['bail', 'sometimes', new QueryBoolean]]);

        if ($validator->fails()) {
            throw new ValidationProblemException(ApiFormRequest::violations($validator));
        }

        $active = $request->query('active');

        return response()->json($this->appPasswords->list($address, $query, is_string($active) ? $active === 'true' : null), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, string $mailbox, string $appPasswordId): JsonResponse
    {
        $address = $this->access->resolve(RequestContext::requirePrincipal($request), $mailbox);
        $appPassword = $this->appPasswords->find($address, (int) $appPasswordId) ?? throw new NotFoundHttpException('The app password does not exist.');

        return $this->respond($appPassword);
    }

    public function store(StoreAppPasswordRequest $request): JsonResponse
    {
        $appPassword = $this->appPasswords->create(RequestContext::requirePrincipal($request), $request->mailbox(), $request->members());

        return $this->respond($appPassword, 201)->header('Location', '/api/v2/mailboxes/'.$request->mailbox().'/app-passwords/'.$appPassword['id']);
    }

    public function update(UpdateAppPasswordRequest $request, string $mailbox, string $appPasswordId): JsonResponse
    {
        return $this->respond($this->appPasswords->update($request, RequestContext::requirePrincipal($request), $request->mailbox(), (int) $appPasswordId, $request->members()));
    }

    public function destroy(Request $request, string $mailbox, string $appPasswordId): Response
    {
        $address = $this->access->resolve(RequestContext::requirePrincipal($request), $mailbox);
        $this->appPasswords->delete($request, RequestContext::requirePrincipal($request), $address, (int) $appPasswordId);

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $appPassword
     */
    private function respond(array $appPassword, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $appPassword], $status, ['ETag' => $appPassword['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
