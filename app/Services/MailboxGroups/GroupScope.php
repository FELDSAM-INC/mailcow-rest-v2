<?php

namespace App\Services\MailboxGroups;

use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\PermissionFlagRequiredException;
use App\Models\Mailcow\SchemaVersion;
use App\Services\Identity\IdentityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class GroupScope
{
    public function __construct(private readonly IdentityService $identity) {}

    public function authorize(Principal $actor): void
    {
        if ($actor->role === Role::MailboxUser) {
            throw new AccessDeniedHttpException;
        }
        if (! SchemaVersion::hasNovember2026Features()) {
            throw new ConflictHttpException('Mailbox groups require mailcow 2026-11.');
        }
        if (! ($this->identity->permissions($actor)['mailboxGroups'] ?? false)) {
            throw new PermissionFlagRequiredException([PermissionFlag::MailboxGroups]);
        }
    }

    public function query(Principal $actor): Builder
    {
        $query = DB::table('mailbox_group');
        if ($actor->role !== Role::Admin) {
            $query->whereIn('domain', $this->identity->scope($actor)->domains);
        }

        return $query;
    }

    public function domain(Principal $actor, string $domain): bool
    {
        if ($actor->role === Role::Admin) {
            return true;
        }
        $primary = DB::table('alias_domain')->where('alias_domain', $domain)->value('target_domain') ?? $domain;

        return $actor->role === Role::DomainAdmin && in_array($primary, $this->identity->scope($actor)->domains, true);
    }

    public function mailbox(Principal $actor, string $address): bool
    {
        $domain = DB::table('mailbox')->where('username', $address)->where('kind', '')->value('domain');

        return is_string($domain) && $this->domain($actor, $domain);
    }

    /** @param list<string> $members
     * @return list<string>
     */
    public function editableMembers(Principal $actor, array $members): array
    {
        if ($actor->role === Role::Admin) {
            return $members;
        }

        return DB::table('mailbox')->whereIn('username', $members)->where('kind', '')
            ->whereIn('domain', $this->identity->scope($actor)->domains)->pluck('username')->all();
    }

    /** Foreign grants, including removals still pending in Dovecot, make a group read-only. */
    public function editable(Principal $actor, int $id): bool
    {
        if ($actor->apiKey?->accessLevel === 'readOnly') {
            return false;
        }
        if ($actor->role === Role::Admin) {
            return true;
        }
        foreach (DB::table('mailbox_group_send_as')->where('group_id', $id)->pluck('address') as $address) {
            if (! $this->domain($actor, substr(strrchr($address, '@'), 1))) {
                return false;
            }
        }
        foreach (['imap', 'sogo'] as $kind) {
            foreach (DB::table('mailbox_group_'.$kind.'_grant')->where('group_id', $id)->pluck('owner') as $owner) {
                if (! $this->mailbox($actor, $owner)) {
                    return false;
                }
            }
        }

        return true;
    }
}
