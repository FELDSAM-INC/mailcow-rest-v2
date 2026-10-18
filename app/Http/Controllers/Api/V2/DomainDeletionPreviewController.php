<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Models\Mailcow\Domain;
use App\Services\Domains\DomainDeletionPlanner;
use App\Services\Domains\DomainScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * previewDomainDeletion (FR-065): what a deletion would be blocked by, remove and keep. Changes
 * nothing and writes no audit record. Administrators only, read-only keys included.
 */
class DomainDeletionPreviewController extends Controller
{
    public function show(Request $request, string $domain, DomainScope $scope, DomainDeletionPlanner $planner): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('previewDeletion', Domain::class);

        $name = $scope->resolve($principal, $domain) ?? throw new NotFoundHttpException('The domain does not exist.');
        $plan = $planner->plan($name) ?? throw new NotFoundHttpException('The domain does not exist.');

        return response()->json(['data' => $plan->toArray()], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
