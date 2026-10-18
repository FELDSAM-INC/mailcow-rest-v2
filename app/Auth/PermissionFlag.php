<?php

namespace App\Auth;

/**
 * The 29 permission flags (contract `PermissionFlags`, R20): the columns of mailcow's `user_acl`
 * and `da_acl` in camelCase. Five names exist in both tables. tests/Feature/Schema/AclColumnsTest
 * fails when an upstream release adds a column, so that the contract is updated deliberately.
 */
enum PermissionFlag: string
{
    // user_acl (the first five of them also exist in da_acl where noted)
    case SpamAlias = 'spamAlias';
    case TlsPolicy = 'tlsPolicy';
    case SpamScore = 'spamScore';
    case SpamPolicy = 'spamPolicy';
    case DelimiterAction = 'delimiterAction';
    case Syncjobs = 'syncjobs';
    case EasReset = 'easReset';
    case SogoProfileReset = 'sogoProfileReset';
    case Pushover = 'pushover';
    case Quarantine = 'quarantine';
    case QuarantineAttachments = 'quarantineAttachments';
    case QuarantineNotification = 'quarantineNotification';
    case QuarantineCategory = 'quarantineCategory';
    case AppPasswds = 'appPasswds';
    case PwReset = 'pwReset';

    // da_acl only
    case LoginAs = 'loginAs';
    case SogoAccess = 'sogoAccess';
    case BccMaps = 'bccMaps';
    case Filters = 'filters';
    case Ratelimit = 'ratelimit';
    case ExtendSenderAcl = 'extendSenderAcl';
    case UnlimitedQuota = 'unlimitedQuota';
    case ProtocolAccess = 'protocolAccess';
    case SmtpIpAccess = 'smtpIpAccess';
    case AliasDomains = 'aliasDomains';
    case MailboxRelayhost = 'mailboxRelayhost';
    case DomainRelayhost = 'domainRelayhost';
    case DomainDesc = 'domainDesc';
    case AliasExternalGoto = 'aliasExternalGoto';

    private const USER_ACL = [
        'spam_alias', 'tls_policy', 'spam_score', 'spam_policy', 'delimiter_action', 'syncjobs',
        'eas_reset', 'sogo_profile_reset', 'pushover', 'quarantine', 'quarantine_attachments',
        'quarantine_notification', 'quarantine_category', 'app_passwds', 'pw_reset',
    ];

    private const DA_ACL = [
        'syncjobs', 'quarantine', 'login_as', 'sogo_access', 'app_passwds', 'bcc_maps', 'pushover',
        'filters', 'ratelimit', 'spam_policy', 'extend_sender_acl', 'unlimited_quota',
        'protocol_access', 'smtp_ip_access', 'alias_domains', 'mailbox_relayhost',
        'domain_relayhost', 'domain_desc', 'alias_external_goto',
    ];

    /**
     * The snake_case column name of the flag.
     */
    public function column(): string
    {
        return strtolower((string) preg_replace('/[A-Z]/', '_$0', $this->value));
    }

    /**
     * The `user_acl` column of the flag, or null when the flag exists only in `da_acl`.
     */
    public function userAclColumn(): ?string
    {
        return in_array($this->column(), self::USER_ACL, true) ? $this->column() : null;
    }

    /**
     * The `da_acl` column of the flag, or null when the flag exists only in `user_acl`.
     */
    public function daAclColumn(): ?string
    {
        return in_array($this->column(), self::DA_ACL, true) ? $this->column() : null;
    }
}
