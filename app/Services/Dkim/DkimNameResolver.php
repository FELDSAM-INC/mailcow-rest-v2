<?php

namespace App\Services\Dkim;

use App\Auth\Principal;
use App\Auth\Role;
use App\Clients\SharedStore\DkimKeyStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\Identity\IdentityService;
use App\Support\Domain\DomainName;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a DKIM path or body name for a caller (feature 010 research R7, FR-001, FR-003, FR-006):
 *
 *  1. administrators first: a raw name that is a field of `DKIM_PUB_KEYS` or `DKIM_SELECTORS` and,
 *     spelled exactly so, neither a domain nor an alias domain is that unused key, even when legacy
 *     stored it in upper case or Unicode;
 *  2. otherwise the name is normalised as feature 002 does (trim, lower case, UTS #46) and must be a
 *     valid domain name;
 *  3. its kind is `domain`, `aliasDomain` (with its target) or `none`;
 *  4. administrators reach every name; domain administrators reach the domains of their active
 *     assignments (whatever the domain's `active`) and the alias domains pointing to them, never a
 *     `none` name; mailbox users reach nothing.
 *
 * Null means "not found", identical for a name outside the scope and a name that does not exist.
 * Evaluated from mailcow's data on every call (FR-002).
 */
class DkimNameResolver
{
    public function __construct(
        private readonly DkimKeyStore $store,
        private readonly IdentityService $identity,
    ) {}

    public function resolve(Principal $principal, string $raw): ?DkimName
    {
        if ($principal->role === Role::Admin && trim($raw) !== '' && $this->isUnusedLegacyName($raw)) {
            return new DkimName($raw, $raw, DkimName::NONE, null);
        }

        $name = self::normalize($raw);

        if ($name === null) {
            return null;
        }

        $resolved = $this->kindOf($name);

        return match ($principal->role) {
            Role::Admin => $resolved,
            Role::DomainAdmin => $this->inDomainAdminScope($principal, $resolved) ? $resolved : null,
            default => null,
        };
    }

    /**
     * The kind of a normalised name, whatever the caller's scope.
     */
    public function kindOf(string $name): DkimName
    {
        $display = DomainName::toUnicode($name);

        if (DB::table('domain')->where('domain', $name)->exists()) {
            return new DkimName($name, $display, DkimName::DOMAIN, null);
        }

        $target = DB::table('alias_domain')->where('alias_domain', $name)->value('target_domain');

        return is_string($target)
            ? new DkimName($name, $display, DkimName::ALIAS_DOMAIN, $target)
            : new DkimName($name, $display, DkimName::NONE, null);
    }

    /**
     * Feature 002's normalisation, for names that must be valid domain names (import names, copy
     * targets, lookups); null when the name cannot be converted or is not a valid domain name.
     */
    public static function normalize(string $raw): ?string
    {
        return DomainName::normalizeValid($raw);
    }

    /**
     * Whether the exact spelling is a stored key name that is neither a domain nor an alias
     * domain. The store is asked first; when it is unavailable, the name is resolved normally.
     */
    private function isUnusedLegacyName(string $raw): bool
    {
        try {
            if (! $this->store->hasKey($raw)) {
                return false;
            }
        } catch (SharedStoreUnavailable) {
            return false;
        }

        // MariaDB compares case-insensitively; the exact spelling decides here.
        $domains = DB::table('domain')->where('domain', $raw)->pluck('domain')->all();
        $aliases = DB::table('alias_domain')->where('alias_domain', $raw)->pluck('alias_domain')->all();

        return ! in_array($raw, [...$domains, ...$aliases], true);
    }

    private function inDomainAdminScope(Principal $principal, DkimName $name): bool
    {
        $domains = $this->identity->scope($principal)->domains;

        return match ($name->kind) {
            DkimName::DOMAIN => in_array($name->name, $domains, true),
            DkimName::ALIAS_DOMAIN => in_array((string) $name->targetDomain, $domains, true),
            default => false,
        };
    }
}
