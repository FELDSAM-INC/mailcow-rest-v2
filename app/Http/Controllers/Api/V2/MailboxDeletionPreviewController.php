<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Models\Mailcow\Mailbox;
use App\Services\Mailboxes\MailboxDeletionPlanner;
use App\Services\Mailboxes\MailboxScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * previewMailboxDeletion (feature 003 FR-065): what a deletion would remove and move now, its
 * blockers and the version tag. Changes nothing and writes no audit record.
 */
class MailboxDeletionPreviewController extends Controller
{
    public function show(Request $request, string $mailbox, MailboxScope $scope, MailboxDeletionPlanner $planner): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('previewDeletion', Mailbox::class);
        $address = $scope->resolve($principal, $mailbox) ?? throw new NotFoundHttpException('The mailbox does not exist.');

        return response()->json(['data' => $planner->preview($address)], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
