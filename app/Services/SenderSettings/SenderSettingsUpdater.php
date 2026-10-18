<?php

namespace App\Services\SenderSettings;

use App\Audit\AuditEventType;
use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Services\Aliases\AliasScope;
use App\Services\Audit\AuditLogger;
use App\Services\Groupware\GroupwareCacheFlush;
use App\Services\Groupware\GroupwareOutcome;
use App\Services\Groupware\GroupwareRefresh;
use App\Services\Identity\IdentityService;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Replaces a mailbox's explicit sender permissions, external sender addresses, or both (feature 004
 * FR-054, FR-055, FR-057, research R14), legacy mailbox('edit', 'mailbox') sender ACL branches as set
 * replacement. In one transaction on the locked mailbox row:
 *
 *  1. the version check (`412`);
 *  2. every entry checked, all violations together (`422`): an explicit permission is an address of
 *     an alias or mailbox, or a domain or alias domain, in the caller's scope, or `*` from an
 *     administrator; an entry already stored is accepted as it is (it may be sent back); an external
 *     address or domain must not lie in a domain or alias domain of the server;
 *  3. a domain administrator without `extendSenderAcl` may send the external addresses only
 *     unchanged (`403 attribute-not-permitted`);
 *  4. the explicit set is the valid entries plus the stored entries the caller may not change; with
 *     `*` it is `*` alone; entries for aliases that already let the mailbox send are not stored;
 *  5. legacy's `DELETE` and `INSERT` statements per replaced set, the mailbox's groupware entry when
 *     the external addresses changed (`ext_acl`), the audit record.
 */
class SenderSettingsUpdater
{
    public function __construct(
        private readonly SenderSettingsReader $reader,
        private readonly AliasScope $scope,
        private readonly IdentityService $identity,
        private readonly GroupwareRefresh $groupware,
        private readonly GroupwareCacheFlush $flush,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $patch  the sent members of `MailboxSenderSettingsPatch`
     */
    public function update(Principal $principal, string $mailbox, array $patch, ?string $ifMatch): void
    {
        $outcome = DB::transaction(fn (): ?GroupwareOutcome => $this->apply($principal, $mailbox, $patch, $ifMatch), 3);

        if ($outcome === GroupwareOutcome::Done) {
            $this->flush->flush();
        }
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function apply(Principal $principal, string $mailbox, array $patch, ?string $ifMatch): ?GroupwareOutcome
    {
        if (! DB::table('mailbox')->where('username', $mailbox)->where('kind', '')->lockForUpdate()->first() instanceof stdClass) {
            throw new NotFoundHttpException('The mailbox does not exist.');
        }

        if (ETag::preconditionFails($ifMatch, $this->reader->etagFor($mailbox))) {
            throw new PreconditionFailedHttpException('The sender settings were changed since they were read.');
        }

        $rows = DB::table('sender_acl')->where('logged_in_as', $mailbox)->get(['external', 'send_as']);
        $storedExplicit = $rows->filter(fn (object $r): bool => (int) $r->external === 0)->map(fn (object $r): string => (string) $r->send_as)->values()->all();
        $storedExternal = $rows->filter(fn (object $r): bool => (int) $r->external === 1)->map(fn (object $r): string => (string) $r->send_as)->values()->all();
        $violations = new FieldViolations;

        $explicit = array_key_exists('explicitPermissions', $patch) ? $this->explicit($principal, $mailbox, (array) $patch['explicitPermissions'], $storedExplicit, $violations) : null;
        $external = array_key_exists('externalSenderAddresses', $patch) ? $this->external((array) $patch['externalSenderAddresses'], $violations) : null;
        $violations->throwIfAny();

        if ($external !== null && $principal->role !== Role::Admin && ! self::sameSet($external, $storedExternal)
            && ! ($this->identity->permissions($principal)[PermissionFlag::ExtendSenderAcl->value] ?? false)) {
            throw new ProblemException(ProblemType::AttributeNotPermitted, 'Changing external sender addresses needs the extendSenderAcl permission.', [
                'attributes' => ['externalSenderAddresses'],
                'requiredFlags' => ['externalSenderAddresses' => PermissionFlag::ExtendSenderAcl->value],
            ]);
        }

        $changes = [];

        if ($explicit !== null) {
            DB::table('sender_acl')->where('external', 0)->where('logged_in_as', $mailbox)->delete();

            foreach ($explicit as $value) {
                DB::table('sender_acl')->insert(['send_as' => $value, 'logged_in_as' => $mailbox]);
            }

            if (self::sorted($explicit) !== self::sorted($storedExplicit)) {
                $changes['explicitPermissions'] = ['old' => self::sorted($storedExplicit), 'new' => self::sorted($explicit)];
            }
        }

        $outcome = null;

        if ($external !== null) {
            DB::table('sender_acl')->where('external', 1)->where('logged_in_as', $mailbox)->delete();

            foreach ($external as $value) {
                DB::table('sender_acl')->insert(['send_as' => $value, 'logged_in_as' => $mailbox, 'external' => 1]);
            }

            if (self::sorted($external) !== self::sorted($storedExternal)) {
                $changes['externalSenderAddresses'] = ['old' => self::sorted($storedExternal), 'new' => self::sorted($external)];
                $outcome = $this->groupware->inSavepoint([$mailbox]);
            }
        }

        $details = ['changes' => $changes === [] ? new stdClass : $changes];

        if ($outcome !== null) {
            $details['groupwareUpdate'] = $outcome->value;
        }

        $this->audit->record(AuditEventType::MailboxSenderSettingsUpdate, true, $principal, ['type' => 'mailboxSenderSettings', 'id' => $mailbox, 'name' => $mailbox], null, null, $details);

        return $outcome;
    }

    /**
     * The explicit permissions to store.
     *
     * @param  array<int|string, mixed>  $sent
     * @param  list<string>  $stored
     * @return list<string>
     */
    private function explicit(Principal $principal, string $mailbox, array $sent, array $stored, FieldViolations $violations): array
    {
        $storedByKey = [];

        foreach ($stored as $value) {
            $storedByKey[mb_strtolower($value)] ??= $value;
        }

        $result = [];

        foreach (array_values($sent) as $i => $raw) {
            $entry = is_string($raw) ? SenderEntry::explicit($raw) : null;

            if ($entry === null) {
                $violations->add("explicitPermissions.{$i}", 'invalidFormat', ['format' => 'senderPermission'], 'Not an address, a domain or *.');

                continue;
            }

            if (isset($storedByKey[$entry->key()])) {
                $result[] = $storedByKey[$entry->key()];

                continue;
            }

            $violation = match ($entry->type) {
                SenderEntry::ANY_ADDRESS => $principal->role === Role::Admin ? null : ['notEligible', ['allowedRoles' => ['admin']], 'Only administrators may grant any address.'],
                SenderEntry::DOMAIN => $this->scope->reachesDomain($principal, (string) $entry->domain()) ? null : ['notFound', ['resource' => 'domain'], 'The domain does not exist.'],
                default => $this->scope->restrict(DB::table('alias as a')->where('a.address', $entry->value), $principal)->exists() ? null : ['notFound', ['resource' => 'address'], 'No alias or mailbox has this address.'],
            };

            if ($violation !== null) {
                $violations->add("explicitPermissions.{$i}", $violation[0], $violation[1], $violation[2]);

                continue;
            }

            $result[] = $entry->value;
        }

        // A domain administrator keeps the stored entries outside their scope (and `*`).
        foreach ($stored as $value) {
            if (! $this->reader->changeable($principal, SenderEntry::stored($value))) {
                $result[] = $value;
            }
        }

        $unique = [];

        foreach ($result as $value) {
            $unique[mb_strtolower($value)] ??= $value;
        }

        if (isset($unique['*'])) {
            return ['*'];
        }

        $redundant = array_flip(SenderSettingsReader::allowedThroughAliases($mailbox));

        return array_values(array_filter($unique, fn (string $v): bool => ! isset($redundant[mb_strtolower($v)])));
    }

    /**
     * The external sender addresses to store: addresses, or domains as `@domain`, outside the
     * server's domains and alias domains.
     *
     * @param  array<int|string, mixed>  $sent
     * @return list<string>
     */
    private function external(array $sent, FieldViolations $violations): array
    {
        $unique = [];

        foreach (array_values($sent) as $i => $raw) {
            $entry = is_string($raw) ? SenderEntry::external($raw) : null;

            if ($entry === null) {
                $violations->add("externalSenderAddresses.{$i}", 'invalidFormat', ['format' => 'emailOrDomain'], 'Not an e-mail address or a domain name.');

                continue;
            }

            $domain = (string) $entry->domain();

            if (DB::table('domain')->where('domain', $domain)->exists() || DB::table('alias_domain')->where('alias_domain', $domain)->exists()) {
                $violations->add("externalSenderAddresses.{$i}", 'notEligible', ['kind' => 'localDomain'], 'The domain is a domain or alias domain of this server.');

                continue;
            }

            $unique[$entry->key()] ??= $entry->value;
        }

        return array_values($unique);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function sameSet(array $a, array $b): bool
    {
        $lower = fn (array $list): array => array_values(array_unique(array_map('mb_strtolower', $list)));
        $x = $lower($a);
        $y = $lower($b);
        sort($x);
        sort($y);

        return $x === $y;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        usort($values, fn (string $a, string $b): int => strcasecmp($a, $b) ?: strcmp($a, $b));

        return array_values($values);
    }
}
