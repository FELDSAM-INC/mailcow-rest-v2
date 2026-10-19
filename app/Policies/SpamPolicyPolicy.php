<?php

namespace App\Policies;

use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;
use InvalidArgumentException;

/**
 * Who may use the spam score, allow and block list and statistics operations of feature 006
 * (FR-006, research R15), checked before any lookup: the role (`403 forbidden`), then the flag
 * (`403 permission-flag-required`). Object scope (`404`) is SpamFilterScope's.
 */
class SpamPolicyPolicy extends SpamFilterFlags
{
    public const VIEW_SCORE = 'viewScore';

    public const CHANGE_SCORE = 'changeScore';

    /** Allow and block lists of domains and alias domains. */
    public const VIEW_DOMAIN_ENTRIES = 'viewDomainEntries';

    public const CHANGE_DOMAIN_ENTRIES = 'changeDomainEntries';

    /** Allow and block lists of mailboxes. */
    public const VIEW_MAILBOX_ENTRIES = 'viewMailboxEntries';

    public const CHANGE_MAILBOX_ENTRIES = 'changeMailboxEntries';

    public const VIEW_STATISTICS = 'viewStatistics';

    public const ABILITIES = [
        self::VIEW_SCORE, self::CHANGE_SCORE, self::VIEW_DOMAIN_ENTRIES, self::CHANGE_DOMAIN_ENTRIES,
        self::VIEW_MAILBOX_ENTRIES, self::CHANGE_MAILBOX_ENTRIES, self::VIEW_STATISTICS,
    ];

    /**
     * @throws ProblemException `forbidden` or `permission-flag-required`
     */
    public function authorize(Principal $principal, string $ability): void
    {
        if (! in_array($ability, self::ABILITIES, true)) {
            throw new InvalidArgumentException("Unknown spam policy ability {$ability}.");
        }

        if ($principal->role === Role::Admin) {
            return;
        }

        $mailboxUser = $principal->role === Role::MailboxUser;

        match ($ability) {
            self::VIEW_SCORE, self::VIEW_MAILBOX_ENTRIES => null,
            self::CHANGE_SCORE => $this->require($principal, [PermissionFlag::SpamScore], 'Changing the spam score needs the spamScore permission.'),
            self::VIEW_DOMAIN_ENTRIES => $mailboxUser ? throw new ProblemException(ProblemType::Forbidden, 'Mailbox users cannot read the lists of domains.') : null,
            self::CHANGE_DOMAIN_ENTRIES => $mailboxUser
                ? throw new ProblemException(ProblemType::Forbidden, 'Mailbox users cannot change the lists of domains.')
                : $this->require($principal, [PermissionFlag::SpamPolicy], 'Changing allow and block lists needs the spamPolicy permission.'),
            self::CHANGE_MAILBOX_ENTRIES => $this->require($principal, [PermissionFlag::SpamPolicy], 'Changing allow and block lists needs the spamPolicy permission.'),
            self::VIEW_STATISTICS => throw new ProblemException(ProblemType::Forbidden, 'Only administrators read the spam filter statistics.'),
        };
    }
}
