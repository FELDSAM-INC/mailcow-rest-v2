<?php

namespace App\Services\SyncSources;

use App\Auth\Principal;
use App\Auth\Role;
use App\Models\Mailcow\SchemaVersion;
use App\Policies\SyncJobPolicy;
use App\Services\Identity\IdentityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SourceScope
{
    public function __construct(private readonly IdentityService $identity, private readonly SyncJobPolicy $policy) {}

    public function authorize(Principal $actor): void
    {
        $this->policy->authorize($actor);
        if (! SchemaVersion::hasNovember2026Features()) {
            throw new ConflictHttpException('Sync sources require mailcow 2026-11.');
        }
    }

    public function query(Principal $actor): Builder
    {
        $query = DB::table('imapsync_source as s')->select('s.*');
        if ($actor->role === Role::Admin) {
            return $query;
        }
        $assigned = $this->identity->scope($actor);
        $domains = $actor->role === Role::MailboxUser ? [$assigned->domain] : $assigned->domains;

        return $query->where(function (Builder $q) use ($actor, $domains): void {
            $q->where('s.created_by', $actor->username)->orWhere('s.scope', 'all');
            $q->orWhere(function (Builder $q) use ($domains): void {
                $q->where('s.scope', 'domain')->whereExists(fn (Builder $t) => $t->selectRaw('1')->from('imapsync_source_domain as d')
                    ->whereColumn('d.source_id', 's.id')->whereIn('d.domain', $domains));
            });
            $q->orWhere(function (Builder $q) use ($actor, $domains): void {
                $q->where('s.scope', 'user')->whereExists(function (Builder $t) use ($actor, $domains): void {
                    $t->selectRaw('1')->from('imapsync_source_user as u')->whereColumn('u.source_id', 's.id');
                    if ($actor->role === Role::MailboxUser) {
                        $t->where('u.username', $actor->username);
                    } else {
                        $t->whereIn('u.username', DB::table('mailbox')->select('username')->whereIn('domain', $domains));
                    }
                });
            });
            if ($actor->role === Role::DomainAdmin) {
                $q->orWhereIn('s.created_by', DB::table('mailbox')->select('username')->whereIn('domain', $domains));
            }
        });
    }

    public function mailbox(Principal $actor, string $mailbox): bool
    {
        $query = DB::table('mailbox')->where('username', $mailbox)->where('kind', '');
        if ($actor->role === Role::MailboxUser) {
            $query->where('username', $actor->username);
        } elseif ($actor->role === Role::DomainAdmin) {
            $query->whereIn('domain', $this->identity->scope($actor)->domains);
        }

        return $query->exists();
    }

    public function domain(Principal $actor, string $domain): bool
    {
        return DB::table('domain')->where('domain', $domain)->exists()
            && ($actor->role === Role::Admin || ($actor->role === Role::DomainAdmin && in_array($domain, $this->identity->scope($actor)->domains, true)));
    }

    public function editable(object $source, Principal $actor): bool
    {
        if ($actor->apiKey?->accessLevel === 'readOnly') {
            return false;
        }
        // Both the old and new auth type are checked before a source can be changed to password auth.
        if ($actor->role === Role::MailboxUser && $source->auth_type === 'XOAUTH2') {
            return false;
        }

        return $actor->role === Role::Admin || $source->created_by === $actor->username
            || ($actor->role === Role::DomainAdmin && $this->mailbox($actor, $source->created_by));
    }

    public function allJobsAccessible(int $id, Principal $actor): bool
    {
        if ($actor->role === Role::Admin) {
            return true;
        }
        foreach (DB::table('imapsync')->where('source_id', $id)->distinct()->pluck('user2') as $mailbox) {
            if (! $this->mailbox($actor, $mailbox)) {
                return false;
            }
        }

        return true;
    }
}
