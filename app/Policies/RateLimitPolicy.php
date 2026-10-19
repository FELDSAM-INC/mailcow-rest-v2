<?php

namespace App\Policies;

use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;
use InvalidArgumentException;

/**
 * Who may use the rate limit operations of feature 006 (FR-006, research R15), checked before any
 * lookup: mailbox users never (`403 forbidden`); domain administrators read, and change with their
 * own `ratelimit` flag (`403 permission-flag-required`); only administrators reset buckets.
 */
class RateLimitPolicy extends SpamFilterFlags
{
    public const VIEW = 'view';

    public const CHANGE = 'change';

    public const RESET_BUCKET = 'resetBucket';

    public const ABILITIES = [self::VIEW, self::CHANGE, self::RESET_BUCKET];

    /**
     * @throws ProblemException `forbidden` or `permission-flag-required`
     */
    public function authorize(Principal $principal, string $ability): void
    {
        if (! in_array($ability, self::ABILITIES, true)) {
            throw new InvalidArgumentException("Unknown rate limit ability {$ability}.");
        }

        if ($principal->role === Role::Admin) {
            return;
        }

        if ($principal->role === Role::MailboxUser) {
            throw new ProblemException(ProblemType::Forbidden, 'Mailbox users cannot read or change rate limits.');
        }

        match ($ability) {
            self::VIEW => null,
            self::CHANGE => $this->require($principal, [PermissionFlag::Ratelimit], 'Changing rate limits needs the ratelimit permission.'),
            self::RESET_BUCKET => throw new ProblemException(ProblemType::Forbidden, 'Only administrators reset rate-limit buckets.'),
            default => null,
        };
    }
}
