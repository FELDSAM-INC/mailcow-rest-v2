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

    /**
     * Keys of refusal records written by App\Services\Audit\RefusalAuditor (docs/conventions.md
     * "Refusal auditing"): the problem type, the status and, for `422`, the field errors (rule codes
     * and parameters only, never input values).
     */
    private const REFUSAL = ['problemType', 'status', 'errors'];

    /** Keys of bulk actions: the request ID on every item's record, and the whole-request refusal. */
    private const BULK = ['bulkRequestId', 'targetIds', 'bulkAction'];

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
            self::DomainTemplateCreation, self::DomainTemplateDeletion => ['values', ...self::REFUSAL],
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
            self::MailboxTemplateCreation, self::MailboxTemplateDeletion => ['values', ...self::REFUSAL],
            self::MailboxTemplateUpdate => ['changes', ...self::REFUSAL],
        };
    }
}
