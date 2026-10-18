<?php

namespace App\Audit;

/**
 * Audited events (contract `AuditEventType`). Later features append their cases and detail keys.
 */
enum AuditEventType: string
{
    case SignIn = 'signIn';
    case SignOut = 'signOut';
    case PreferencesUpdate = 'preferencesUpdate';
    case PasskeyRegistration = 'passkeyRegistration';
    case PasskeyRename = 'passkeyRename';
    case PasskeyRemoval = 'passkeyRemoval';
    case ApiKeyCreation = 'apiKeyCreation';
    case ApiKeyRevocation = 'apiKeyRevocation';

    // Feature 002: domains, their footer and domain templates.
    case DomainCreation = 'domainCreation';
    case DomainUpdate = 'domainUpdate';
    case DomainDeletion = 'domainDeletion';
    case DomainFooterUpdate = 'domainFooterUpdate';
    case DomainTemplateCreation = 'domainTemplateCreation';
    case DomainTemplateUpdate = 'domainTemplateUpdate';
    case DomainTemplateDeletion = 'domainTemplateDeletion';

    // Feature 010: DKIM keys and MTA-STS policies (the DKIM core is delivered with feature 002).
    case DkimKeyGeneration = 'dkimKeyGeneration';
    case DkimKeyImport = 'dkimKeyImport';
    case DkimKeyCopy = 'dkimKeyCopy';
    case DkimKeyDeletion = 'dkimKeyDeletion';
    case DkimPrivateKeyExport = 'dkimPrivateKeyExport';
    case DkimDnsPublication = 'dkimDnsPublication';
    case MtaStsPolicyCreation = 'mtaStsPolicyCreation';
    case MtaStsPolicyUpdate = 'mtaStsPolicyUpdate';

    // Feature 003: mailboxes and mailbox templates.
    case MailboxCreation = 'mailboxCreation';
    case MailboxUpdate = 'mailboxUpdate';
    case MailboxDeletion = 'mailboxDeletion';
    case MailboxRename = 'mailboxRename';
    case MailboxTemplateCreation = 'mailboxTemplateCreation';
    case MailboxTemplateUpdate = 'mailboxTemplateUpdate';
    case MailboxTemplateDeletion = 'mailboxTemplateDeletion';

    // Feature 004: aliases, alias domains, resources and sender settings.
    case AliasCreation = 'aliasCreation';
    case AliasUpdate = 'aliasUpdate';
    case AliasDeletion = 'aliasDeletion';
    case AliasDomainCreation = 'aliasDomainCreation';
    case AliasDomainUpdate = 'aliasDomainUpdate';
    case AliasDomainDeletion = 'aliasDomainDeletion';
    case ResourceCreation = 'resourceCreation';
    case ResourceUpdate = 'resourceUpdate';
    case ResourceDeletion = 'resourceDeletion';
    case MailboxSenderSettingsUpdate = 'mailboxSenderSettingsUpdate';

    // Feature 011: monitoring and operations.
    case ContainerStart = 'containerStart';
    case ContainerStop = 'containerStop';
    case ContainerRestart = 'containerRestart';
    case MailQueueDelivery = 'mailQueueDelivery';
    case MailQueueHold = 'mailQueueHold';
    case MailQueueRelease = 'mailQueueRelease';
    case MailQueueDeletion = 'mailQueueDeletion';
    case MailQueueFlush = 'mailQueueFlush';
    case MailQueuePurge = 'mailQueuePurge';
    case MailQueueMessageView = 'mailQueueMessageView';
    case Fail2banSettingsUpdate = 'fail2banSettingsUpdate';
    case Fail2banRulesReplacement = 'fail2banRulesReplacement';
    case Fail2banRulesReset = 'fail2banRulesReset';
    case Fail2banBanLift = 'fail2banBanLift';
    case Fail2banBanAllowlisting = 'fail2banBanAllowlisting';
    case Fail2banBanDenylisting = 'fail2banBanDenylisting';
    case Fail2banBanListIdRotation = 'fail2banBanListIdRotation';

    /**
     * Keys of refusal records written by App\Services\Audit\RefusalAuditor (docs/conventions.md
     * "Refusal auditing"): the problem type, the status and, for `422`, the field errors (rule codes
     * and parameters only, never input values).
     */
    private const REFUSAL = ['problemType', 'status', 'errors'];

    /** Keys of bulk actions: the request ID on every item's record, and the whole-request refusal. */
    private const BULK = ['bulkRequestId', 'targetIds', 'bulkAction'];

    /** Feature 011: the list entries a ban service change added and removed. */
    private const BAN_LISTS = ['allowlistAdded', 'allowlistRemoved', 'denylistAdded', 'denylistRemoved'];

    /**
     * The `details` keys this event may record (docs/audit-events.md). Every other key is dropped,
     * so that no secret can reach the audit log.
     *
     * @return list<string>
     */
    public function detailKeys(): array
    {
        return match ($this) {
            self::SignIn => ['requestedRole', 'secondFactorType', 'restricted', 'step'],
            self::SignOut, self::PasskeyRename, self::PasskeyRemoval => [],
            self::PreferencesUpdate => ['changedFields'],
            self::PasskeyRegistration => ['restrictionLifted'],
            self::ApiKeyCreation => ['ownerUsername', 'accessLevel', 'expiresAt', 'allowedNetworks'],
            self::ApiKeyRevocation => ['ownerUsername'],
            self::DomainCreation => ['values', 'templateId', 'creationReport', ...self::REFUSAL],
            self::DomainUpdate => ['changes', ...self::BULK, ...self::REFUSAL],
            self::DomainDeletion => ['removedAliasDomains', 'removedDomainAdmins', 'footerRemoved', 'maildirCleanup', ...self::BULK, ...self::REFUSAL],
            self::DomainFooterUpdate, self::DomainTemplateUpdate => ['changes', ...self::REFUSAL],
            self::DomainTemplateCreation => ['values', ...self::REFUSAL],
            self::DomainTemplateDeletion => ['values', ...self::BULK, ...self::REFUSAL],
            self::DkimKeyGeneration => ['selector', 'keyLength', 'overwriteRequested', 'old', 'new', 'trigger', 'dnsPublication', ...self::REFUSAL],
            self::DkimKeyImport => ['selector', 'keyLength', 'overwriteRequested', 'old', 'new', 'dnsPublication', ...self::REFUSAL],
            self::DkimKeyCopy => ['selector', 'keyLength', 'overwriteRequested', 'old', 'new', 'dnsPublication', 'sourceName', ...self::REFUSAL],
            self::DkimKeyDeletion => ['old', 'dnsPublication', ...self::BULK, ...self::REFUSAL],
            self::DkimPrivateKeyExport => ['selector', 'publicKeySha256', ...self::REFUSAL],
            self::DkimDnsPublication => ['selector', 'dnsPublication', ...self::REFUSAL],
            self::MtaStsPolicyCreation => ['version', 'mode', 'mxHosts', 'maxAge', 'active', 'policyId', ...self::REFUSAL],
            self::MtaStsPolicyUpdate => ['changes', 'oldPolicyId', 'newPolicyId', ...self::REFUSAL],
            self::MailboxCreation => ['values', 'templateId', 'passwordSet', 'preHashed', 'creationReport', ...self::REFUSAL],
            self::MailboxUpdate => ['changes', ...self::BULK, ...self::REFUSAL],
            self::MailboxDeletion => ['removed', 'mailStoreCleanup', ...self::BULK, ...self::REFUSAL],
            self::MailboxRename => ['previousAddress', 'newAddress', 'keepOldAddressAsAlias', 'steps', ...self::REFUSAL],
            self::MailboxTemplateCreation => ['values', ...self::REFUSAL],
            self::MailboxTemplateDeletion => ['values', ...self::BULK, ...self::REFUSAL],
            self::MailboxTemplateUpdate => ['changes', ...self::REFUSAL],
            self::AliasCreation => ['values', 'groupwareUpdate', 'copiedFromAliasId', ...self::BULK, ...self::REFUSAL],
            self::AliasUpdate, self::ResourceUpdate => ['changes', 'groupwareUpdate', ...self::BULK, ...self::REFUSAL],
            self::AliasDeletion, self::ResourceDeletion => ['removed', 'groupwareUpdate', ...self::BULK, ...self::REFUSAL],
            self::AliasDomainCreation => ['values', 'creationReport', ...self::REFUSAL],
            self::AliasDomainUpdate => ['changes', ...self::BULK, ...self::REFUSAL],
            self::AliasDomainDeletion => ['removed', ...self::BULK, ...self::REFUSAL],
            self::ResourceCreation => ['values', 'groupwareUpdate', ...self::REFUSAL],
            self::MailboxSenderSettingsUpdate => ['changes', 'groupwareUpdate', ...self::REFUSAL],
            self::ContainerStart, self::ContainerStop, self::ContainerRestart => ['outcome', 'stateBefore', 'stateAfter', 'serviceMessage', 'step', ...self::REFUSAL],
            self::MailQueueDelivery, self::MailQueueHold, self::MailQueueRelease, self::MailQueueDeletion => ['results', 'serviceMessage', 'step', 'itemIds', 'bulkAction', ...self::REFUSAL],
            self::MailQueueFlush, self::MailQueuePurge => ['serviceMessage', 'step', ...self::REFUSAL],
            self::MailQueueMessageView => self::REFUSAL,
            self::Fail2banSettingsUpdate => ['changes', ...self::BAN_LISTS, ...self::REFUSAL],
            self::Fail2banRulesReplacement => ['oldRules', 'newRules', ...self::REFUSAL],
            self::Fail2banRulesReset => ['oldRules', 'newRules', 'serviceMessage', 'step', ...self::REFUSAL],
            self::Fail2banBanLift => ['network', ...self::REFUSAL],
            self::Fail2banBanAllowlisting => ['network', 'allowlistAdded', 'denylistRemoved', ...self::REFUSAL],
            self::Fail2banBanDenylisting => ['network', 'denylistAdded', 'allowlistRemoved', ...self::REFUSAL],
            self::Fail2banBanListIdRotation => self::REFUSAL,
        };
    }
}
