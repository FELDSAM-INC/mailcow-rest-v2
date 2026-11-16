<?php

namespace App\Services\MailboxGroups;

use App\Auth\Principal;
use App\Auth\Role;
use App\Casts\MailcowDateTime;
use App\Support\Http\ETag;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GroupReader
{
    public function __construct(private readonly GroupScope $scope) {}

    /** @return array<string, mixed> */
    public function read(int $id, Principal $actor): array
    {
        $row = $this->scope->query($actor)->where('id', $id)->first();
        if (! $row instanceof stdClass) {
            throw new NotFoundHttpException;
        }

        return $this->present($row, $actor);
    }

    /** @return array<string, mixed> */
    public function present(stdClass $row, Principal $actor): array
    {
        $id = (int) $row->id;
        $members = DB::table('mailbox_group_member')->where('group_id', $id)->orderBy('username')->get();
        $editableMembers = array_fill_keys($this->scope->editableMembers($actor, $members->pluck('username')->all()), true);
        $sendAs = DB::table('mailbox_group_send_as')->where('group_id', $id)->orderBy('address')->pluck('address')->all();
        $imap = DB::table('mailbox_group_imap_grant')->where('group_id', $id)->orderBy('owner')->get();
        $sogo = DB::table('mailbox_group_sogo_grant')->where('group_id', $id)->orderBy('owner')->orderBy('folder_type')->orderBy('folder')->get();

        return [
            'id' => $id, 'domain' => $row->domain, 'name' => $row->name, 'description' => $row->description,
            'address' => $row->address, 'active' => (bool) $row->active, 'senderAllowed' => (bool) $row->sender_allowed,
            'sogoVisible' => (bool) $row->sogo_visible, 'internal' => (bool) $row->internal,
            'templateId' => $row->template_id === null ? null : (int) $row->template_id,
            'priority' => (int) $row->priority, 'identityReference' => $row->idp_ref,
            'members' => $members->map(fn ($m): array => ['mailbox' => $m->username, 'source' => $m->source,
                'editable' => $m->source === 'manual' && isset($editableMembers[$m->username])])->all(),
            'sendAs' => $sendAs,
            'imapGrants' => $imap->map(fn ($g): array => ['id' => (int) $g->id, 'owner' => $g->owner, 'rights' => $g->rights,
                'subscribe' => (bool) $g->subscribe, 'state' => $g->state, 'error' => $g->last_error])->all(),
            'sogoGrants' => $sogo->map(fn ($g): array => ['id' => (int) $g->id, 'owner' => $g->owner, 'type' => $g->folder_type,
                'folder' => $g->folder, 'access' => $g->access, 'subscribe' => (bool) $g->subscribe,
                'state' => $g->state, 'error' => $g->last_error])->all(),
            'editable' => $this->scope->editable($actor, $id), 'adminFieldsEditable' => $actor->role === Role::Admin && $actor->apiKey?->accessLevel !== 'readOnly',
            'createdAt' => MailcowDateTime::toUtcString($row->created), 'modifiedAt' => MailcowDateTime::toUtcString($row->modified),
            // Include relations: a cron or a legacy edit must invalidate this version too.
            'etag' => ETag::of([(array) $row, $members->all(), $sendAs, $imap->all(), $sogo->all()]),
            'warnings' => $row->address !== null && $members->count() > 1000 ? ['recipientExpansionLimit'] : [],
        ];
    }
}
