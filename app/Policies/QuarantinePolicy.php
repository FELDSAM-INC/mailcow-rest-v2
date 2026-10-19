<?php

namespace App\Policies;

use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;
use InvalidArgumentException;

/**
 * Who may use the quarantine operations of feature 006 (FR-006, research R15), checked before any
 * lookup so that a refusal never reveals whether an item exists: the role (`403 forbidden`), then
 * the flags (`403 permission-flag-required`), or for a mailbox user's notification change the
 * members whose flag is missing (`403 attribute-not-permitted`). Object scope (`404`) is
 * App\Services\Access\SpamFilterScope's. A key principal acts as its owner. Not a Gate policy: the
 * Gate cannot answer the flag refusals.
 */
class QuarantinePolicy extends SpamFilterFlags
{
    /** List and inspect items, including their text: every role, whatever its flags. */
    public const VIEW_ITEMS = 'viewItems';

    /** See attachment details and download attachments. */
    public const VIEW_ATTACHMENTS = 'viewAttachments';

    /** Download the whole stored message. */
    public const DOWNLOAD_MESSAGE = 'downloadMessage';

    /** Release, learn as spam and delete items, singly or in bulk. */
    public const ACT = 'act';

    /** Read the quarantine settings (the public subset for non-administrators). */
    public const VIEW_SETTINGS = 'viewSettings';

    public const CHANGE_SETTINGS = 'changeSettings';

    public const PREVIEW_TEMPLATE = 'previewTemplate';

    /** Read a mailbox's notification settings (scoped). */
    public const VIEW_NOTIFICATIONS = 'viewNotifications';

    /** Change notification settings of several mailboxes at once. */
    public const BULK_NOTIFICATIONS = 'bulkNotifications';

    public const VIEW_CAPABILITIES = 'viewCapabilities';

    public const ABILITIES = [
        self::VIEW_ITEMS, self::VIEW_ATTACHMENTS, self::DOWNLOAD_MESSAGE, self::ACT, self::VIEW_SETTINGS,
        self::CHANGE_SETTINGS, self::PREVIEW_TEMPLATE, self::VIEW_NOTIFICATIONS, self::BULK_NOTIFICATIONS,
        self::VIEW_CAPABILITIES,
    ];

    /** The flag each notification member needs for a mailbox user (FR-036). */
    public const NOTIFICATION_FLAGS = ['frequency' => PermissionFlag::QuarantineNotification, 'category' => PermissionFlag::QuarantineCategory];

    /**
     * @throws ProblemException `forbidden` or `permission-flag-required`
     */
    public function authorize(Principal $principal, string $ability): void
    {
        if (! in_array($ability, self::ABILITIES, true)) {
            throw new InvalidArgumentException("Unknown quarantine ability {$ability}.");
        }

        if ($principal->role === Role::Admin) {
            return;
        }

        match ($ability) {
            self::VIEW_ITEMS, self::VIEW_SETTINGS, self::VIEW_NOTIFICATIONS, self::VIEW_CAPABILITIES => null,
            self::CHANGE_SETTINGS => throw new ProblemException(ProblemType::Forbidden, 'Only administrators change the quarantine settings.'),
            self::PREVIEW_TEMPLATE => throw new ProblemException(ProblemType::Forbidden, 'Only administrators preview the notification template.'),
            self::BULK_NOTIFICATIONS => $principal->role === Role::DomainAdmin
                ? null
                : throw new ProblemException(ProblemType::Forbidden, 'Mailbox users cannot change several mailboxes at once.'),
            self::VIEW_ATTACHMENTS => $this->require($principal, [PermissionFlag::QuarantineAttachments], 'Attachments need the quarantineAttachments permission.'),
            self::ACT => $this->require($principal, [PermissionFlag::Quarantine], 'Releasing, learning and deleting quarantine items needs the quarantine permission.'),
            self::DOWNLOAD_MESSAGE => $this->require(
                $principal,
                $principal->role === Role::MailboxUser ? [PermissionFlag::Quarantine, PermissionFlag::QuarantineAttachments] : [PermissionFlag::Quarantine],
                'Downloading the whole message needs the quarantine permission'.($principal->role === Role::MailboxUser ? ' and the quarantineAttachments permission.' : '.'),
            ),
        };
    }

    /**
     * Refuses a mailbox user's change of notification members whose flag they lack, naming each
     * member and its flag; members sent with their current value are not in $changedMembers.
     * Administrators and domain administrators may always change.
     *
     * @param  list<string>  $changedMembers  `frequency`, `category`
     *
     * @throws ProblemException `attribute-not-permitted`
     */
    public function authorizeNotificationChange(Principal $principal, array $changedMembers): void
    {
        $refused = [];

        foreach ($changedMembers as $member) {
            $flag = self::NOTIFICATION_FLAGS[$member] ?? null;

            if ($flag !== null && ! $this->has($principal, $flag)) {
                $refused[$member] = $flag->value;
            }
        }

        if ($refused !== []) {
            throw ProblemException::attributeNotPermitted(array_keys($refused), $refused);
        }
    }
}
