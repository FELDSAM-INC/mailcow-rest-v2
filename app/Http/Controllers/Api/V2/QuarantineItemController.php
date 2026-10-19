<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListQuarantineItemsRequest;
use App\Http\Resources\Api\V2\QuarantineItemDetailsResource;
use App\Http\Resources\Api\V2\QuarantineItemResource;
use App\Policies\QuarantinePolicy;
use App\Services\Quarantine\QuarantineActionService;
use App\Services\Quarantine\QuarantineContent;
use App\Services\Quarantine\QuarantineQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listQuarantineItems, getQuarantineItem and deleteQuarantineItem (feature 006 FR-007 to FR-015,
 * FR-024).
 */
class QuarantineItemController extends Controller
{
    public function index(ListQuarantineItemsRequest $request, QuarantinePolicy $policy, QuarantineQuery $query): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->authorize($principal, QuarantinePolicy::VIEW_ITEMS);
        $list = $request->listQuery();
        $page = $query->page($principal, $list, $request->filters());

        return QuarantineItemResource::list($page['rows'], $list->meta($page['total']));
    }

    public function show(Request $request, string $itemId, QuarantinePolicy $policy, QuarantineQuery $query, QuarantineContent $content): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->authorize($principal, QuarantinePolicy::VIEW_ITEMS);
        $item = $query->find($principal, (int) $itemId) ?? throw new NotFoundHttpException('The quarantine item does not exist.');
        $status = $content->status($item);

        return QuarantineItemDetailsResource::response(
            $item,
            $status,
            $status === QuarantineContent::AVAILABLE ? $content->parse($item) : null,
            $policy->allows($principal, QuarantinePolicy::VIEW_ATTACHMENTS),
            $principal->role === Role::Admin,
        );
    }

    public function destroy(Request $request, string $itemId, QuarantineActionService $actions): Response
    {
        $actions->delete(RequestContext::requirePrincipal($request), (int) $itemId, $request->header('If-Match'));

        return response()->noContent();
    }
}
