<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ReplaceRelayhostAssignmentRequest;
use App\Http\Resources\Api\V2\RoutingResource;
use App\Policies\RoutingPolicy;
use App\Services\Domains\DomainScope;
use App\Services\Mailboxes\MailboxScope;
use App\Services\Routing\RelayhostAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * getDomainRelayhostAssignment, replaceDomainRelayhostAssignment, getMailboxRelayhostAssignment
 * and replaceMailboxRelayhostAssignment (feature 009 US2). Reads need no permission flag; objects
 * outside the caller's scope answer `404` exactly like unknown ones.
 */
class RelayhostAssignmentController extends Controller
{
    public function __construct(
        private readonly RelayhostAssignmentService $assignments,
        private readonly RoutingPolicy $policy,
    ) {}

    public function showDomain(Request $request, string $domain, DomainScope $scope): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->policy->authorize($principal, RoutingPolicy::VIEW_RELAYHOST_ASSIGNMENT);
        $name = $scope->resolve($principal, $domain) ?? throw new NotFoundHttpException('The domain does not exist.');

        return RoutingResource::response($this->assignments->domain($name));
    }

    public function updateDomain(ReplaceRelayhostAssignmentRequest $request): JsonResponse
    {
        $this->assignments->replaceDomain(RequestContext::requirePrincipal($request), $request->target(), $request->relayhostId(), $request->header('If-Match'));

        return RoutingResource::response($this->assignments->domain($request->target()));
    }

    public function showMailbox(Request $request, string $mailbox, MailboxScope $scope): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->policy->authorize($principal, RoutingPolicy::VIEW_RELAYHOST_ASSIGNMENT);
        $address = $scope->resolve($principal, $mailbox) ?? throw new NotFoundHttpException('The mailbox does not exist.');

        return RoutingResource::response($this->assignments->mailbox($address));
    }

    public function updateMailbox(ReplaceRelayhostAssignmentRequest $request): JsonResponse
    {
        $this->assignments->replaceMailbox(RequestContext::requirePrincipal($request), $request->target(), $request->relayhostId(), $request->header('If-Match'));

        return RoutingResource::response($this->assignments->mailbox($request->target()));
    }
}
