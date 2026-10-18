<?php

namespace App\Services\Mailboxes;

use App\Auth\Principal;
use App\Auth\Role;

/**
 * The fifteen permission flags of a mailbox user (mailcow's `user_acl`, feature 003 FR-035), in the
 * contract's camelCase names (contract `MailboxUserPermissions`). Five of them overlap with
 * domain-administrator flags of the same name: for a domain administrator lacking such a flag the
 * permission is hidden and cannot be chosen, as legacy acl('get', 'user') does.
 */
final class UserPermissions
{
    /** Contract name => `user_acl` column, in the column order of the table. */
    public const COLUMNS = [
        'spamAlias' => 'spam_alias',
        'tlsPolicy' => 'tls_policy',
        'spamScore' => 'spam_score',
        'spamPolicy' => 'spam_policy',
        'delimiterAction' => 'delimiter_action',
        'syncjobs' => 'syncjobs',
        'easReset' => 'eas_reset',
        'sogoProfileReset' => 'sogo_profile_reset',
        'pushover' => 'pushover',
        'quarantine' => 'quarantine',
        'quarantineAttachments' => 'quarantine_attachments',
        'quarantineNotification' => 'quarantine_notification',
        'quarantineCategory' => 'quarantine_category',
        'appPasswds' => 'app_passwds',
        'pwReset' => 'pw_reset',
    ];

    /** The permissions that are also domain-administrator flags (`PermissionFlags` names). */
    public const OVERLAPPING = ['syncjobs', 'quarantine', 'appPasswds', 'pushover', 'spamPolicy'];

    /** The `user_acl` column defaults: everything except sync jobs and the SOGo profile reset. */
    public const DEFAULTS = [
        'spamAlias' => true,
        'tlsPolicy' => true,
        'spamScore' => true,
        'spamPolicy' => true,
        'delimiterAction' => true,
        'syncjobs' => false,
        'easReset' => true,
        'sogoProfileReset' => false,
        'pushover' => true,
        'quarantine' => true,
        'quarantineAttachments' => true,
        'quarantineNotification' => true,
        'quarantineCategory' => true,
        'appPasswds' => true,
        'pwReset' => true,
    ];

    /**
     * The permissions of a `user_acl` row; a missing row has the column defaults.
     *
     * @return array<string, bool>
     */
    public static function fromRow(?object $row): array
    {
        if ($row === null) {
            return self::DEFAULTS;
        }

        $permissions = [];

        foreach (self::COLUMNS as $name => $column) {
            $permissions[$name] = (int) ($row->{$column} ?? (int) self::DEFAULTS[$name]) === 1;
        }

        return $permissions;
    }

    /**
     * The permissions a caller may see: a domain administrator does not see an overlapping
     * permission whose flag it lacks.
     *
     * @param  array<string, bool>  $permissions
     * @param  array<string, bool>  $flags  the caller's effective `PermissionFlags`
     * @return array<string, bool>
     */
    public static function visibleTo(array $permissions, Principal $principal, array $flags): array
    {
        if ($principal->role !== Role::DomainAdmin) {
            return $permissions;
        }

        foreach (self::OVERLAPPING as $name) {
            if (! ($flags[$name] ?? false)) {
                unset($permissions[$name]);
            }
        }

        return $permissions;
    }
}
