<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\UpdateMailboxSenderSettingsRequest;
use App\Http\Resources\Api\V2\AliasResource;
use App\Models\Mailcow\Alias;
use App\Services\Mailboxes\MailboxScope;
use App\Services\SenderSettings\SenderSettingsReader;
use App\Services\SenderSettings\SenderSettingsUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * getMailboxSenderSettings and updateMailboxSenderSettings (feature 004 FR-053–FR-057): the role
 * first (`403`), then the mailbox in the caller's scope through 003's MailboxScope (`404` for
 * resources and every other address too).
 */
class MailboxSenderSettingsController extends Controller
{
    public function __construct(private readonly SenderSettingsReader $reader) {}

    public function show(Request $request, string $mailbox, MailboxScope $scope): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('view', Alias::class);

        $address = $scope->resolve($principal, $mailbox) ?? throw new NotFoundHttpException('The mailbox does not exist.');

        return AliasResource::response($this->reader->read($principal, $address));
    }

    public function update(UpdateMailboxSenderSettingsRequest $request, SenderSettingsUpdater $updater): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $updater->update($principal, $request->mailbox(), $request->members(), $request->header('If-Match'));

        return AliasResource::response($this->reader->read($principal, $request->mailbox()));
    }
}
