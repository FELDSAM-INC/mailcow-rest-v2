<?php

namespace App\Services\SenderSettings;

use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Aliases\AliasAddress;
use App\Services\Aliases\AliasScope;
use App\Support\Http\ETag;
use Illuminate\Support\Facades\DB;

/**
 * A mailbox's sender settings (feature 004 FR-053, research R14), legacy
 * mailbox('get', 'sender_acl_handles') with the spec's deviations:
 *
 *  - implicit addresses: the own address, its local part in the domain's alias domains, and the
 *    aliases that deliver to it (legacy's `goto REGEXP`), catch-all aliases included (legacy hides
 *    them); a mailbox's own row is never an alias of this feature (FR-013);
 *  - explicit permissions (`sender_acl` with `external = 0`), each marked changeable for the caller
 *    (administrators always; domain administrators within their scope, legacy hasDomainAccess and
 *    hasAliasObjectAccess; `*` never) and stale when its domain or address no longer exists;
 *  - external sender addresses (`external = 1`);
 *  - the version tag over the stored entries only.
 */
class SenderSettingsReader
{
    public function __construct(private readonly AliasScope $scope) {}

    /**
     * @return array<string, mixed> contract `MailboxSenderSettings`
     */
    public function read(Principal $principal, string $mailbox): array
    {
        $rows = DB::table('sender_acl')->where('logged_in_as', $mailbox)->get(['external', 'send_as']);
        $explicit = $rows->filter(fn (object $r): bool => (int) $r->external === 0)->map(fn (object $r): SenderEntry => SenderEntry::stored((string) $r->send_as))->values()->all();
        $external = $rows->filter(fn (object $r): bool => (int) $r->external === 1)->map(fn (object $r): string => (string) $r->send_as)->sort(fn (string $a, string $b): int => strcasecmp($a, $b) ?: strcmp($a, $b))->values()->all();

        return [
            'mailbox' => $mailbox,
            'mailboxUnicode' => AliasAddress::unicodeOf($mailbox),
            'implicitAddresses' => $this->implicit($mailbox),
            'explicitPermissions' => $this->explicit($principal, $explicit),
            'senderCheckDisabled' => array_filter($explicit, fn (SenderEntry $e): bool => $e->type === SenderEntry::ANY_ADDRESS) !== [],
            'externalSenderAddresses' => $external,
            'externalSenderAddressesUnicode' => array_map(AliasAddress::unicodeOf(...), $external),
            'etag' => $this->etagFor($mailbox),
        ];
    }

    /**
     * The version tag over the mailbox's stored `sender_acl` rows (explicit permissions and external
     * sender addresses), independent of row order and IDs (research R18).
     *
     * @phpstan-impure
     */
    public function etagFor(string $mailbox): string
    {
        $pairs = DB::table('sender_acl')->where('logged_in_as', $mailbox)->get(['external', 'send_as'])
            ->map(fn (object $row): array => [(int) $row->external, (string) $row->send_as])
            ->all();

        usort($pairs, fn (array $a, array $b): int => [$a[0], mb_strtolower($a[1]), $a[1]] <=> [$b[0], mb_strtolower($b[1]), $b[1]]);

        return ETag::of(['mailboxSenderSettings', mb_strtolower($mailbox), $pairs]);
    }

    /**
     * Legacy's expression for the aliases that deliver to a mailbox: the address as a whole entry
     * of `goto`.
     */
    public static function deliversTo(string $mailbox): string
    {
        return '(^|,)'.preg_quote($mailbox, '/').'($|,)';
    }

    /**
     * The addresses of aliases that deliver to the mailbox and allow sending, not catch-alls, the
     * mailbox's own row included (legacy's `fixed_sender_aliases_allowed`): explicit permissions for
     * them are redundant.
     *
     * @return list<string> lower-cased addresses
     */
    public static function allowedThroughAliases(string $mailbox): array
    {
        return DB::table('alias')->whereRaw('`goto` REGEXP ?', [self::deliversTo($mailbox)])->where('address', 'not like', '@%')->where('sender_allowed', 1)
            ->pluck('address')->map(fn (mixed $a): string => mb_strtolower((string) $a))->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function implicit(string $mailbox): array
    {
        $own = [['address' => $mailbox, 'addressUnicode' => AliasAddress::unicodeOf($mailbox), 'source' => 'ownAddress', 'aliasId' => null, 'senderAllowed' => true, 'catchAll' => false]];

        $aliasDomains = DB::table('mailbox as m')->join('alias_domain as ad', 'ad.target_domain', '=', 'm.domain')
            ->where('m.username', $mailbox)->orderBy('ad.alias_domain')
            ->get([DB::raw("CONCAT(`m`.`local_part`, '@', `ad`.`alias_domain`) AS `address`")])
            ->map(fn (object $r): array => ['address' => (string) $r->address, 'addressUnicode' => AliasAddress::unicodeOf((string) $r->address), 'source' => 'aliasDomainAddress', 'aliasId' => null, 'senderAllowed' => true, 'catchAll' => false])
            ->values()->all();

        $aliases = DB::table('alias as a')->whereRaw('`a`.`goto` REGEXP ?', [self::deliversTo($mailbox)])
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('mailbox as own')->whereColumn('own.username', 'a.address'))
            ->orderBy('a.address')->get(['a.id', 'a.address', 'a.sender_allowed'])
            ->map(fn (object $r): array => [
                'address' => (string) $r->address,
                'addressUnicode' => AliasAddress::unicodeOf((string) $r->address),
                'source' => 'alias',
                'aliasId' => (int) $r->id,
                'senderAllowed' => (int) $r->sender_allowed !== 0,
                'catchAll' => str_starts_with((string) $r->address, '@'),
            ])->values()->all();

        return [...$own, ...$aliasDomains, ...$aliases];
    }

    /**
     * @param  list<SenderEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function explicit(Principal $principal, array $entries): array
    {
        $rank = [SenderEntry::ANY_ADDRESS => 0, SenderEntry::DOMAIN => 1, SenderEntry::ADDRESS => 2];
        usort($entries, fn (SenderEntry $a, SenderEntry $b): int => [$rank[$a->type], $a->key(), $a->value] <=> [$rank[$b->type], $b->key(), $b->value]);

        return array_map(function (SenderEntry $entry) use ($principal): array {
            $kind = $this->kindOf($entry);

            return [
                'value' => $entry->value,
                'valueUnicode' => $entry->unicode(),
                'type' => $entry->type,
                'kind' => $kind,
                'changeable' => $this->changeable($principal, $entry),
                'stale' => $kind === null,
            ];
        }, $entries);
    }

    /**
     * What an entry names (G12): `domain`, `aliasDomain`, `mailbox`, `alias` or `anyAddress`; null
     * when its domain or address no longer exists (stale).
     */
    public function kindOf(SenderEntry $entry): ?string
    {
        if ($entry->type === SenderEntry::ANY_ADDRESS) {
            return 'anyAddress';
        }

        if ($entry->type === SenderEntry::DOMAIN) {
            $domain = (string) $entry->domain();

            return match (true) {
                DB::table('domain')->where('domain', $domain)->exists() => 'domain',
                DB::table('alias_domain')->where('alias_domain', $domain)->exists() => 'aliasDomain',
                default => null,
            };
        }

        return match (true) {
            DB::table('mailbox')->where('username', $entry->value)->where('kind', '')->exists() => 'mailbox',
            DB::table('alias')->where('address', $entry->value)->exists() => 'alias',
            default => null,
        };
    }

    /**
     * Whether the caller may change (add or remove) the entry: administrators always; domain
     * administrators a domain or alias domain they reach, or an alias address (mailboxes included)
     * whose domain they reach; never `*`.
     */
    public function changeable(Principal $principal, SenderEntry $entry): bool
    {
        if ($principal->role === Role::Admin) {
            return true;
        }

        return match ($entry->type) {
            SenderEntry::ANY_ADDRESS => false,
            SenderEntry::DOMAIN => $this->scope->reachesDomain($principal, (string) $entry->domain()),
            default => $this->scope->restrict(DB::table('alias as a')->where('a.address', $entry->value), $principal)->exists(),
        };
    }
}
