<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListSpamPolicyEntriesRequest;
use App\Http\Requests\Api\V2\StoreSpamPolicyEntryRequest;
use App\Http\Resources\Api\V2\SpamPolicyEntryResource;
use App\Services\SpamPolicy\SpamPolicyEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;

/**
 * The allow and block list operations of domains, alias domains and mailboxes (feature 006
 * FR-043 to FR-048): one controller for the three owner families, the owner kind from the route
 * default `restv2.ownerKind` and the owner from the path parameter of that kind.
 */
class SpamPolicyEntryController extends Controller
{
    public function index(ListSpamPolicyEntriesRequest $request, SpamPolicyEntryService $entries): JsonResponse
    {
        $owner = $entries->owner(RequestContext::requirePrincipal($request), self::kind($request), self::ownerName($request), false);

        return SpamPolicyEntryResource::list($entries->page($owner, $request->listQuery(), $request->filters()));
    }

    public function store(StoreSpamPolicyEntryRequest $request, SpamPolicyEntryService $entries): JsonResponse
    {
        $owner = $request->owner();
        $entry = $entries->create(RequestContext::requirePrincipal($request), $owner, $request->list(), $request->pattern());

        return SpamPolicyEntryResource::response($entry, 201, SpamPolicyEntryService::location($owner, (int) $entry['id']));
    }

    public function show(Request $request, SpamPolicyEntryService $entries): JsonResponse
    {
        $owner = $entries->owner(RequestContext::requirePrincipal($request), self::kind($request), self::ownerName($request), false);

        return SpamPolicyEntryResource::response($entries->find($owner, (int) $request->route('entryId')));
    }

    public function destroy(Request $request, SpamPolicyEntryService $entries): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $owner = $entries->owner($principal, self::kind($request), self::ownerName($request), true);
        $entries->delete($principal, $owner, (int) $request->route('entryId'), $request->header('If-Match'));

        return response()->noContent();
    }

    private static function kind(Request $request): string
    {
        $route = $request->route();

        return $route instanceof Route ? (string) ($route->defaults['restv2.ownerKind'] ?? 'mailbox') : 'mailbox';
    }

    private static function ownerName(Request $request): string
    {
        return (string) $request->route(self::kind($request));
    }
}
