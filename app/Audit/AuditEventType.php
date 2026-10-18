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
    // Feature 008: sync jobs.
    case SyncJobCreation = 'syncJobCreation';
    case SyncJobUpdate = 'syncJobUpdate';
    case SyncJobReschedule = 'syncJobReschedule';
    case SyncJobDeletion = 'syncJobDeletion';
    // Feature 009: routing and address rewriting.
    case RelayhostCreation = 'relayhostCreation';
    case RelayhostUpdate = 'relayhostUpdate';
    case RelayhostDeletion = 'relayhostDeletion';
    case RelayhostTest = 'relayhostTest';
    case RelayhostAssignmentUpdate = 'relayhostAssignmentUpdate';
    case TransportCreation = 'transportCreation';
    case TransportUpdate = 'transportUpdate';
    case TransportDeletion = 'transportDeletion';
    case TransportTest = 'transportTest';
    case BccMapCreation = 'bccMapCreation';
    case BccMapUpdate = 'bccMapUpdate';
    case BccMapDeletion = 'bccMapDeletion';
    case RecipientMapCreation = 'recipientMapCreation';
    case RecipientMapUpdate = 'recipientMapUpdate';
    case RecipientMapDeletion = 'recipientMapDeletion';
    case TlsPolicyMapCreation = 'tlsPolicyMapCreation';
    case TlsPolicyMapUpdate = 'tlsPolicyMapUpdate';
    case TlsPolicyMapDeletion = 'tlsPolicyMapDeletion';
    case ForwardingHostCreation = 'forwardingHostCreation';
    case ForwardingHostUpdate = 'forwardingHostUpdate';
    case ForwardingHostDeletion = 'forwardingHostDeletion';

    // Feature 012: identity provider configuration.
    case IdentityProviderCreation = 'identityProviderCreation';
    case IdentityProviderUpdate = 'identityProviderUpdate';
    case IdentityProviderDeletion = 'identityProviderDeletion';
    case IdentityProviderConnectionTest = 'identityProviderConnectionTest';
    case IdentityProviderSessionEnd = 'identityProviderSessionEnd';

    // Feature 012: OAuth2 clients.
    case Oauth2ClientCreation = 'oauth2ClientCreation';
    case Oauth2ClientUpdate = 'oauth2ClientUpdate';
    case Oauth2ClientSecretRegeneration = 'oauth2ClientSecretRegeneration';
    case Oauth2ClientTokenRevocation = 'oauth2ClientTokenRevocation';
    case Oauth2ClientDeletion = 'oauth2ClientDeletion';

    // Feature 005: account self-service.
    case OwnPasswordChange = 'ownPasswordChange';
    case OwnSecondFactorEnrolment = 'ownSecondFactorEnrolment';
    case OwnSecondFactorRemoval = 'ownSecondFactorRemoval';
    case OwnRecoveryEmailChange = 'ownRecoveryEmailChange';
    case PasswordResetRequest = 'passwordResetRequest';
    case PasswordResetCompletion = 'passwordResetCompletion';
    case AppPasswordCreation = 'appPasswordCreation';
    case AppPasswordUpdate = 'appPasswordUpdate';
    case AppPasswordDeletion = 'appPasswordDeletion';
    case TemporaryAliasCreation = 'temporaryAliasCreation';
    case TemporaryAliasUpdate = 'temporaryAliasUpdate';
    case TemporaryAliasDeletion = 'temporaryAliasDeletion';
    case SieveFilterCreation = 'sieveFilterCreation';
    case SieveFilterUpdate = 'sieveFilterUpdate';
    case SieveFilterDeletion = 'sieveFilterDeletion';
    case PushNotificationSettingsUpdate = 'pushNotificationSettingsUpdate';
    case PushNotificationSettingsDeletion = 'pushNotificationSettingsDeletion';
    case PushNotificationVerification = 'pushNotificationVerification';
    case MailSettingsUpdate = 'mailSettingsUpdate';
    case RecentLoginsClear = 'recentLoginsClear';
    case ActiveSyncCacheReset = 'activeSyncCacheReset';
    case GroupwareProfileReset = 'groupwareProfileReset';
    case WebmailHandOff = 'webmailHandOff';

    /**
     * Keys of refusal records written by App\Services\Audit\RefusalAuditor (docs/conventions.md
     * "Refusal auditing"): the problem type, the status and, for `422`, the field errors (rule codes
     * and parameters only, never input values).
     */
    private const REFUSAL = ['problemType', 'status', 'errors'];

    /**
     * Feature 012: keys of sign-ins through the identity provider (the provider type, a mailbox
     * created or updated on the way, the provider's error code, the cause of a failed creation).
     */
    private const PROVIDER_SIGN_IN = ['identityProvider', 'mailboxCreated', 'templateApplied', 'providerError', 'cause'];

    /** Feature 012: keys of a mailbox created or updated by a sign-in through the identity provider. */
    private const PROVISIONING = ['trigger', 'template', 'signInRequestId'];

    /**
     * Refusal keys of feature 005's events: those of REFUSAL plus the flags a refused change needed
     * (`permission-flag-required`).
     */
    private const SELF_SERVICE_REFUSAL = ['problemType', 'status', 'errors', 'requiredFlags'];

    /** Keys of bulk actions: the request ID on every item's record, and the whole-request refusal. */
    private const BULK = ['bulkRequestId', 'targetIds', 'bulkAction'];

    /** Feature 011: the list entries a ban service change added and removed. */
    private const BAN_LISTS = ['allowlistAdded', 'allowlistRemoved', 'denylistAdded', 'denylistRemoved'];

    /** Refusal keys of feature 009, which also records the missing permission flags. */
    private const ROUTING_REFUSAL = ['problemType', 'status', 'errors', 'requiredFlags'];

    /** Keys of a connection test record (feature 009 FR-085): never the transcript. */
    private const CONNECTION_TEST = ['sender', 'recipient', 'accepted', 'step', 'failureReason'];

    /**
     * The `details` keys this event may record (docs/audit-events.md). Every other key is dropped,
     * so that no secret can reach the audit log.
     *
     * @return list<string>
     */
    public function detailKeys(): array
    {
        return match ($this) {
            self::SignIn => ['requestedRole', 'secondFactorType', 'restricted', 'step', ...self::PROVIDER_SIGN_IN],
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
            self::MailboxCreation => ['values', 'templateId', 'passwordSet', 'preHashed', 'creationReport', ...self::PROVISIONING, ...self::REFUSAL],
            self::MailboxUpdate => ['changes', ...self::BULK, ...self::PROVISIONING, ...self::REFUSAL],
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
            self::SyncJobCreation => ['mailbox', 'values', ...self::REFUSAL],
            self::SyncJobUpdate => ['mailbox', 'changes', ...self::BULK, ...self::REFUSAL],
            self::SyncJobReschedule => ['mailbox', 'cleared', 'previous', ...self::BULK, ...self::REFUSAL],
            self::SyncJobDeletion => ['mailbox', 'remoteHost', 'remoteUser', ...self::BULK, ...self::REFUSAL],
            self::IdentityProviderCreation => ['type', 'changes', 'mailboxCounts', ...self::REFUSAL],
            self::IdentityProviderUpdate => ['type', 'previousType', 'changes', 'mailboxCounts', ...self::REFUSAL],
            self::IdentityProviderDeletion => ['type', 'mailboxCounts', ...self::REFUSAL],
            self::IdentityProviderSessionEnd => ['identityProvider', 'reason'],
            self::IdentityProviderConnectionTest => ['type', 'testedAddress', 'outcome', 'failureCategory', 'providerStatus', ...self::REFUSAL],
            self::Oauth2ClientCreation => ['redirectUris', ...self::REFUSAL],
            self::Oauth2ClientUpdate => ['changes', ...self::REFUSAL],
            self::Oauth2ClientSecretRegeneration => [...self::BULK, ...self::REFUSAL],
            self::Oauth2ClientTokenRevocation, self::Oauth2ClientDeletion => ['removed', ...self::BULK, ...self::REFUSAL],
            self::RelayhostCreation, self::BccMapCreation, self::RecipientMapCreation, self::TlsPolicyMapCreation => ['values', ...self::ROUTING_REFUSAL],
            self::RelayhostUpdate, self::BccMapUpdate, self::RecipientMapUpdate, self::TlsPolicyMapUpdate, self::ForwardingHostUpdate => ['changes', ...self::BULK, ...self::ROUTING_REFUSAL],
            self::RelayhostDeletion => ['host', 'clearedDomains', 'clearedMailboxes', ...self::BULK, ...self::ROUTING_REFUSAL],
            self::RelayhostTest, self::TransportTest => [...self::CONNECTION_TEST, ...self::ROUTING_REFUSAL],
            self::RelayhostAssignmentUpdate => ['changes', ...self::ROUTING_REFUSAL],
            self::TransportCreation => ['values', 'affectedTransportIds', ...self::ROUTING_REFUSAL],
            self::TransportUpdate => ['changes', 'affectedTransportIds', ...self::BULK, ...self::ROUTING_REFUSAL],
            self::TransportDeletion => ['destination', 'nextHop', ...self::BULK, ...self::ROUTING_REFUSAL],
            self::BccMapDeletion => ['localDestination', 'type', ...self::BULK, ...self::ROUTING_REFUSAL],
            self::RecipientMapDeletion => ['originalDestination', ...self::BULK, ...self::ROUTING_REFUSAL],
            self::TlsPolicyMapDeletion => ['destination', ...self::BULK, ...self::ROUTING_REFUSAL],
            self::ForwardingHostCreation => ['entries', 'spamFilter', ...self::ROUTING_REFUSAL],
            self::ForwardingHostDeletion => ['source', ...self::BULK, ...self::ROUTING_REFUSAL],
            self::OwnPasswordChange => ['password', 'forcedChangeResolved', 'sessionsEnded', ...self::SELF_SERVICE_REFUSAL],
            self::OwnSecondFactorEnrolment => ['secondFactorId', 'secondFactorType', 'replacedIds', 'restrictionLifted', 'attemptsRemaining', ...self::SELF_SERVICE_REFUSAL],
            self::OwnSecondFactorRemoval => ['secondFactorId', 'secondFactorType', 'removedIds', ...self::SELF_SERVICE_REFUSAL],
            self::OwnRecoveryEmailChange => ['changes', ...self::SELF_SERVICE_REFUSAL],
            self::PasswordResetRequest => [],
            self::PasswordResetCompletion => ['password', 'forcedChangeResolved', 'secondFactorType', 'sessionsEnded'],
            self::AppPasswordCreation => ['appPasswordId', 'values', 'passwordGenerated', 'source', ...self::SELF_SERVICE_REFUSAL],
            self::AppPasswordUpdate => ['appPasswordId', 'changes', ...self::SELF_SERVICE_REFUSAL],
            self::AppPasswordDeletion => ['appPasswordId', 'removed', ...self::SELF_SERVICE_REFUSAL],
            self::TemporaryAliasCreation => ['temporaryAlias', 'values', ...self::SELF_SERVICE_REFUSAL],
            self::TemporaryAliasUpdate => ['temporaryAlias', 'changes', ...self::SELF_SERVICE_REFUSAL],
            self::TemporaryAliasDeletion => ['temporaryAlias', 'removed', ...self::SELF_SERVICE_REFUSAL],
            self::SieveFilterCreation => ['sieveFilterId', 'values', 'deactivatedIds', ...self::SELF_SERVICE_REFUSAL],
            self::SieveFilterUpdate => ['sieveFilterId', 'changes', 'deactivatedIds', ...self::SELF_SERVICE_REFUSAL],
            self::SieveFilterDeletion => ['sieveFilterId', 'removed', ...self::SELF_SERVICE_REFUSAL],
            self::PushNotificationSettingsUpdate => ['changes', 'created', ...self::SELF_SERVICE_REFUSAL],
            self::PushNotificationSettingsDeletion => ['removed', ...self::SELF_SERVICE_REFUSAL],
            self::PushNotificationVerification => ['accepted', ...self::SELF_SERVICE_REFUSAL],
            self::MailSettingsUpdate => ['changes', ...self::BULK, ...self::SELF_SERVICE_REFUSAL],
            self::RecentLoginsClear => ['removedRecentLogins', 'removedSignInRecords', ...self::SELF_SERVICE_REFUSAL],
            self::ActiveSyncCacheReset, self::GroupwareProfileReset => ['removed', ...self::SELF_SERVICE_REFUSAL],
            self::WebmailHandOff => [...self::SELF_SERVICE_REFUSAL],
        };
    }
}
