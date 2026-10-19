<?php

namespace App\Services\Accounts;

use App\Auth\PermissionFlag;
use stdClass;

/**
 * The 19 permission flags of a domain administrator (feature 007 FR-037, contract
 * `DomainAdminPermissions`): the `da_acl` columns of the supported schema in their column order,
 * named as in feature 001's `PermissionFlags`. tests/Feature/Schema/AclColumnsTest fails when an
 * upstream release adds a column.
 */
final class DomainAdminFlags
{
    /** Contract name => `da_acl` column, in the schema's column order. */
    public const COLUMNS = [
        'syncjobs' => 'syncjobs',
        'quarantine' => 'quarantine',
        'loginAs' => 'login_as',
        'sogoAccess' => 'sogo_access',
        'appPasswds' => 'app_passwds',
        'bccMaps' => 'bcc_maps',
        'pushover' => 'pushover',
        'filters' => 'filters',
        'ratelimit' => 'ratelimit',
        'spamPolicy' => 'spam_policy',
        'extendSenderAcl' => 'extend_sender_acl',
        'unlimitedQuota' => 'unlimited_quota',
        'protocolAccess' => 'protocol_access',
        'smtpIpAccess' => 'smtp_ip_access',
        'aliasDomains' => 'alias_domains',
        'mailboxRelayhost' => 'mailbox_relayhost',
        'domainRelayhost' => 'domain_relayhost',
        'domainDesc' => 'domain_desc',
        'aliasExternalGoto' => 'alias_external_goto',
    ];

    /**
     * The flags of a `da_acl` row by contract name; every flag `false` without a row (FR-041).
     *
     * @return array<string, bool>
     */
    public static function fromRow(?stdClass $row): array
    {
        $flags = [];

        foreach (self::COLUMNS as $name => $column) {
            $flags[$name] = $row !== null && (int) ($row->{$column} ?? 0) === 1;
        }

        return $flags;
    }

    /**
     * The `da_acl` column of a contract name, or null for an unknown name.
     */
    public static function column(string $name): ?string
    {
        return self::COLUMNS[$name] ?? null;
    }

    /**
     * Feature 001's flag of a contract name.
     */
    public static function flag(string $name): PermissionFlag
    {
        return PermissionFlag::from($name);
    }
}
