<?php

namespace App\Services\Mailboxes;

use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Services\Identity\IdentityService;
use App\Support\Http\ProblemType;

/**
 * What a domain administrator may choose for a mailbox (feature 003 FR-006, FR-035, research R8).
 * A member is chosen when the request sets it to a value other than the baseline: the current value
 * on a change, the value from the template and defaults on a creation. Chosen members need these
 * flags (`PermissionFlags` names):
 *
 *  - `quota` of 0 (unlimited): `unlimitedQuota`, whatever its source, unless the mailbox already
 *    has an unlimited quota (on a creation also when the template gives it);
 *  - `protocolAccess.*`: `protocolAccess`; `webmailRedirect`: `sogoAccess`; `rateLimit`:
 *    `ratelimit`;
 *  - `userPermissions.{syncjobs, quarantine, appPasswds, pushover, spamPolicy}`: the flag of the
 *    same name.
 *
 * Every refused member is reported together in one `403 attribute-not-permitted` with `attributes`
 * (dot paths) and `requiredFlags` (path => flag); nothing changes. Administrators are never refused.
 */
class MailboxPermissionGuard
{
    public function __construct(private readonly IdentityService $identity) {}

    /**
     * @param  array<string, mixed>  $members  the request's members (contract names), only those sent
     * @param  array<string, mixed>  $baseline  the values the members are compared with (contract names)
     */
    public function check(Principal $principal, array $members, array $baseline, bool $creation): void
    {
        if ($principal->role === Role::Admin) {
            return;
        }

        $flags = $this->identity->permissions($principal);
        $required = [];

        $quota = array_key_exists('quota', $members) ? $members['quota'] : ($creation ? ($baseline['quota'] ?? null) : null);
        $currentQuota = $creation ? null : ($baseline['quota'] ?? null);

        if ($quota === 0 && $currentQuota !== 0) {
            $required['quota'] = 'unlimitedQuota';
        }

        foreach ((array) ($members['protocolAccess'] ?? []) as $protocol => $value) {
            if ($value !== ($baseline['protocolAccess'][$protocol] ?? null)) {
                $required['protocolAccess.'.$protocol] = 'protocolAccess';
            }
        }

        if (array_key_exists('webmailRedirect', $members) && $members['webmailRedirect'] !== ($baseline['webmailRedirect'] ?? null)) {
            $required['webmailRedirect'] = 'sogoAccess';
        }

        if ($creation && array_key_exists('rateLimit', $members) && $members['rateLimit'] != ($baseline['rateLimit'] ?? null)) {
            $required['rateLimit'] = 'ratelimit';
        }

        foreach ((array) ($members['userPermissions'] ?? []) as $permission => $value) {
            if (in_array($permission, UserPermissions::OVERLAPPING, true) && $value !== ($baseline['userPermissions'][$permission] ?? null)) {
                $required['userPermissions.'.$permission] = (string) $permission;
            }
        }

        $refused = array_filter($required, fn (string $flag): bool => ! ($flags[$flag] ?? false));
        ksort($refused);

        if ($refused !== []) {
            throw new ProblemException(
                ProblemType::AttributeNotPermitted,
                'Your permission flags do not allow these settings: '.implode(', ', array_keys($refused)).'.',
                ['attributes' => array_keys($refused), 'requiredFlags' => $refused],
            );
        }
    }
}
