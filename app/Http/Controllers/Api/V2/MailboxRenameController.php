<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\RenameMailboxRequest;
use App\Http\Resources\Api\V2\MailboxResource;
use App\Services\Mailboxes\MailboxReader;
use App\Services\Mailboxes\MailboxRenamer;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * renameMailbox (feature 003 FR-043–FR-048): `200` with the mailbox under its new address,
 * `Content-Location` and `ETag`.
 */
class MailboxRenameController extends Controller
{
    public function store(RenameMailboxRequest $request, MailboxRenamer $renamer, MailboxReader $reader): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $old = $request->address();
        $keep = (bool) $request->validated('keepOldAddressAsAlias');
        $new = $renamer->rename($principal, $old, (string) $request->validated('newLocalPart'), $keep, $request->header('If-Match'));

        $data = $reader->one($principal, $new) ?? throw new NotFoundHttpException('The mailbox does not exist.');
        $data['previousAddress'] = $old;
        $data['oldAddressKeptAsAlias'] = $keep;

        return MailboxResource::response($data, 200, ['Content-Location' => MailboxResource::location($new)]);
    }
}
