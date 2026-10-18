<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\ValidationProblemException;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Services\RecentLogins\RecentLoginService;
use App\Services\SelfService\MailboxAccess;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

/**
 * listRecentLogins and clearRecentLogins (feature 005 FR-061 to FR-063): the mailbox in the
 * caller's scope (`404` otherwise); neither needs a permission flag, as in legacy.
 */
class RecentLoginController extends Controller
{
    public function __construct(
        private readonly RecentLoginService $recentLogins,
        private readonly MailboxAccess $access,
    ) {}

    public function index(Request $request, string $mailbox): JsonResponse
    {
        $address = $this->access->resolve(RequestContext::requirePrincipal($request), $mailbox);
        $sort = $request->query('sort');
        $query = ListQuery::fromRequest($request, RecentLoginService::SORT_COLUMNS, 'lastSeenAt', $sort === null || $sort === 'lastSeenAt' ? 'desc' : 'asc');
        $validator = Validator::make($request->query(), [
            'days' => ['bail', 'sometimes', 'integer', 'between:1,31'],
            'service' => ['bail', 'sometimes', 'string', 'max:32'],
        ]);

        if ($validator->fails()) {
            throw new ValidationProblemException(ApiFormRequest::violations($validator));
        }

        $service = $request->query('service');

        return response()->json(
            $this->recentLogins->list($address, (int) $request->query('days', '7'), is_string($service) && $service !== '' ? $service : null, $query),
            200,
            [],
            JSON_UNESCAPED_UNICODE,
        );
    }

    public function destroy(Request $request, string $mailbox): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->recentLogins->clear($principal, $this->access->resolve($principal, $mailbox));

        return response()->noContent();
    }
}
