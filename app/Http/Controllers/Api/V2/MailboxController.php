<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListMailboxesRequest;
use App\Http\Requests\Api\V2\StoreMailboxRequest;
use App\Http\Requests\Api\V2\UpdateMailboxRequest;
use App\Http\Resources\Api\V2\MailboxResource;
use App\Models\Mailcow\Mailbox;
use App\Services\Mailboxes\MailboxCreator;
use App\Services\Mailboxes\MailboxReader;
use App\Services\Mailboxes\MailboxScope;
use App\Services\Mailboxes\MailboxUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listMailboxes, getMailbox, createMailbox, updateMailbox and deleteMailbox (feature 003). The role
 * is checked first (`403`), then the scope (`404`, also for resources), then the request.
 */
class MailboxController extends Controller
{
    public function __construct(
        private readonly MailboxReader $reader,
        private readonly MailboxScope $scope,
    ) {}

    public function index(ListMailboxesRequest $request): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $list = $request->listQuery();
        $page = $this->reader->page($principal, $list, $request->filters());

        return MailboxResource::list($page['data'], $list->meta($page['total']));
    }

    public function store(StoreMailboxRequest $request, MailboxCreator $creator): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $creation = $creator->create($principal, $request->members());
        $data = $this->reader->one($principal, $creation->address, true) ?? throw new NotFoundHttpException('The mailbox does not exist.');
        $data['creationReport'] = $creation->report->toArray();

        return MailboxResource::response($data, 201, ['Location' => MailboxResource::location($creation->address)]);
    }

    public function update(UpdateMailboxRequest $request, MailboxUpdater $updater): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $updater->update($principal, $request->address(), $request->members(), $request->header('If-Match'));

        return MailboxResource::response($this->reader->one($principal, $request->address()) ?? throw new NotFoundHttpException('The mailbox does not exist.'));
    }

    public function show(Request $request, string $mailbox): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('view', Mailbox::class);

        $address = $this->scope->resolve($principal, $mailbox) ?? throw new NotFoundHttpException('The mailbox does not exist.');

        return MailboxResource::response($this->reader->one($principal, $address) ?? throw new NotFoundHttpException('The mailbox does not exist.'));
    }
}
