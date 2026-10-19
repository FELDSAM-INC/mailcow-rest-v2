<?php

use App\Http\Controllers\Api\V2\AccountBulkActionController;
use App\Http\Controllers\Api\V2\AccountCapabilitiesController;
use App\Http\Controllers\Api\V2\AdminController;
use App\Http\Controllers\Api\V2\AdminSecondFactorSuspensionController;
use App\Http\Controllers\Api\V2\AliasBulkActionController;
use App\Http\Controllers\Api\V2\AliasController;
use App\Http\Controllers\Api\V2\AliasCopyController;
use App\Http\Controllers\Api\V2\AliasDomainBulkActionController;
use App\Http\Controllers\Api\V2\AliasDomainController;
use App\Http\Controllers\Api\V2\AliasesCapabilitiesController;
use App\Http\Controllers\Api\V2\ApiDocsController;
use App\Http\Controllers\Api\V2\ApiKeyController;
use App\Http\Controllers\Api\V2\ApiKeyOwnerCandidateController;
use App\Http\Controllers\Api\V2\ApiKeyRevocationController;
use App\Http\Controllers\Api\V2\AppPasswordController;
use App\Http\Controllers\Api\V2\AuditEventController;
use App\Http\Controllers\Api\V2\BccMapController;
use App\Http\Controllers\Api\V2\ContainerController;
use App\Http\Controllers\Api\V2\ContainerResourceUsageController;
use App\Http\Controllers\Api\V2\ContainerRestartController;
use App\Http\Controllers\Api\V2\ContainerStartController;
use App\Http\Controllers\Api\V2\ContainerStopController;
use App\Http\Controllers\Api\V2\CsrfTokenController;
use App\Http\Controllers\Api\V2\DkimDnsPublicationController;
use App\Http\Controllers\Api\V2\DkimKeyBulkActionController;
use App\Http\Controllers\Api\V2\DkimKeyController;
use App\Http\Controllers\Api\V2\DkimKeyCopyController;
use App\Http\Controllers\Api\V2\DkimKeyImportController;
use App\Http\Controllers\Api\V2\DkimPrivateKeyController;
use App\Http\Controllers\Api\V2\DnsCapabilitiesController;
use App\Http\Controllers\Api\V2\DnsDiagnosticsController;
use App\Http\Controllers\Api\V2\DomainAdminController;
use App\Http\Controllers\Api\V2\DomainAdminSecondFactorSuspensionController;
use App\Http\Controllers\Api\V2\DomainAdminSsoTokenController;
use App\Http\Controllers\Api\V2\DomainBulkActionController;
use App\Http\Controllers\Api\V2\DomainCapabilitiesController;
use App\Http\Controllers\Api\V2\DomainController;
use App\Http\Controllers\Api\V2\DomainDeletionPreviewController;
use App\Http\Controllers\Api\V2\DomainFooterController;
use App\Http\Controllers\Api\V2\DomainTagController;
use App\Http\Controllers\Api\V2\DomainTemplateBulkActionController;
use App\Http\Controllers\Api\V2\DomainTemplateController;
use App\Http\Controllers\Api\V2\Fail2BanActiveBanController;
use App\Http\Controllers\Api\V2\Fail2BanBanAllowlistController;
use App\Http\Controllers\Api\V2\Fail2BanBanDenylistController;
use App\Http\Controllers\Api\V2\Fail2BanBanLiftController;
use App\Http\Controllers\Api\V2\Fail2BanBanListRotationController;
use App\Http\Controllers\Api\V2\Fail2BanPermanentBanController;
use App\Http\Controllers\Api\V2\Fail2BanRulesController;
use App\Http\Controllers\Api\V2\Fail2BanRulesResetController;
use App\Http\Controllers\Api\V2\Fail2BanSettingsController;
use App\Http\Controllers\Api\V2\ForwardingHostController;
use App\Http\Controllers\Api\V2\GroupwareResetController;
use App\Http\Controllers\Api\V2\HostOverviewController;
use App\Http\Controllers\Api\V2\IdentityController;
use App\Http\Controllers\Api\V2\IdentityProviderCapabilitiesController;
use App\Http\Controllers\Api\V2\IdentityProviderConnectionTestController;
use App\Http\Controllers\Api\V2\IdentityProviderController;
use App\Http\Controllers\Api\V2\ImpersonationController;
use App\Http\Controllers\Api\V2\InstallationController;
use App\Http\Controllers\Api\V2\LivenessController;
use App\Http\Controllers\Api\V2\LogEntryController;
use App\Http\Controllers\Api\V2\MailboxBulkActionController;
use App\Http\Controllers\Api\V2\MailboxCapabilitiesController;
use App\Http\Controllers\Api\V2\MailboxController;
use App\Http\Controllers\Api\V2\MailboxDeletionPreviewController;
use App\Http\Controllers\Api\V2\MailboxMailSettingsController;
use App\Http\Controllers\Api\V2\MailboxRenameController;
use App\Http\Controllers\Api\V2\MailboxSenderSettingsController;
use App\Http\Controllers\Api\V2\MailboxTagController;
use App\Http\Controllers\Api\V2\MailboxTemplateBulkActionController;
use App\Http\Controllers\Api\V2\MailboxTemplateController;
use App\Http\Controllers\Api\V2\MailboxWebmailSignInController;
use App\Http\Controllers\Api\V2\MailQueueBulkActionController;
use App\Http\Controllers\Api\V2\MailQueueFlushController;
use App\Http\Controllers\Api\V2\MailQueueMessageContentController;
use App\Http\Controllers\Api\V2\MailQueueMessageController;
use App\Http\Controllers\Api\V2\MailQueuePurgeController;
use App\Http\Controllers\Api\V2\MailStorageUsageController;
use App\Http\Controllers\Api\V2\MonitoringCapabilitiesController;
use App\Http\Controllers\Api\V2\MtaStsPolicyController;
use App\Http\Controllers\Api\V2\Oauth2ClientBulkActionController;
use App\Http\Controllers\Api\V2\Oauth2ClientCapabilitiesController;
use App\Http\Controllers\Api\V2\Oauth2ClientController;
use App\Http\Controllers\Api\V2\Oauth2ClientSecretRegenerationController;
use App\Http\Controllers\Api\V2\Oauth2ClientTokenRevocationController;
use App\Http\Controllers\Api\V2\OpenApiDocumentController;
use App\Http\Controllers\Api\V2\OwnAppleProfileController;
use App\Http\Controllers\Api\V2\OwnClientConfigurationController;
use App\Http\Controllers\Api\V2\OwnMailboxOverviewController;
use App\Http\Controllers\Api\V2\OwnPasswordController;
use App\Http\Controllers\Api\V2\OwnPasswordPolicyController;
use App\Http\Controllers\Api\V2\OwnRecoveryEmailController;
use App\Http\Controllers\Api\V2\OwnSecondFactorController;
use App\Http\Controllers\Api\V2\OwnSecondFactorRemovalController;
use App\Http\Controllers\Api\V2\OwnSecondFactorWebAuthnOptionsController;
use App\Http\Controllers\Api\V2\OwnTotpEnrolmentController;
use App\Http\Controllers\Api\V2\PasskeyAssertionController;
use App\Http\Controllers\Api\V2\PasskeyChallengeController;
use App\Http\Controllers\Api\V2\PasskeyController;
use App\Http\Controllers\Api\V2\PasskeyRegistrationOptionsController;
use App\Http\Controllers\Api\V2\PasswordPolicyController;
use App\Http\Controllers\Api\V2\PasswordResetController;
use App\Http\Controllers\Api\V2\PendingSignInController;
use App\Http\Controllers\Api\V2\PreferencesController;
use App\Http\Controllers\Api\V2\ProblemTypeController;
use App\Http\Controllers\Api\V2\PublicAddressesController;
use App\Http\Controllers\Api\V2\PublicConfigController;
use App\Http\Controllers\Api\V2\PublicConfigLogoController;
use App\Http\Controllers\Api\V2\PushNotificationSettingsController;
use App\Http\Controllers\Api\V2\QuarantineBulkActionController;
use App\Http\Controllers\Api\V2\QuarantineCapabilitiesController;
use App\Http\Controllers\Api\V2\QuarantineItemController;
use App\Http\Controllers\Api\V2\QuarantineItemDownloadController;
use App\Http\Controllers\Api\V2\QuarantineItemReleaseController;
use App\Http\Controllers\Api\V2\QuarantineItemSpamLearningController;
use App\Http\Controllers\Api\V2\ReadinessController;
use App\Http\Controllers\Api\V2\RecentLoginController;
use App\Http\Controllers\Api\V2\RecipientMapController;
use App\Http\Controllers\Api\V2\RelayhostAssignmentController;
use App\Http\Controllers\Api\V2\RelayhostChoiceController;
use App\Http\Controllers\Api\V2\RelayhostController;
use App\Http\Controllers\Api\V2\RelayhostUsageController;
use App\Http\Controllers\Api\V2\ResourceBulkActionController;
use App\Http\Controllers\Api\V2\ResourceController;
use App\Http\Controllers\Api\V2\RoutingBulkActionController;
use App\Http\Controllers\Api\V2\RoutingCapabilitiesController;
use App\Http\Controllers\Api\V2\RoutingConnectionTestController;
use App\Http\Controllers\Api\V2\RspamdHistoryController;
use App\Http\Controllers\Api\V2\RspamdStatisticsController;
use App\Http\Controllers\Api\V2\SecondFactorChallengeController;
use App\Http\Controllers\Api\V2\SecondFactorController;
use App\Http\Controllers\Api\V2\SelfServiceCapabilitiesController;
use App\Http\Controllers\Api\V2\SenderPermissionCandidateController;
use App\Http\Controllers\Api\V2\SessionController;
use App\Http\Controllers\Api\V2\SieveFilterController;
use App\Http\Controllers\Api\V2\SingleSignOnCompletionController;
use App\Http\Controllers\Api\V2\SingleSignOnController;
use App\Http\Controllers\Api\V2\SingleSignOnProviderErrorController;
use App\Http\Controllers\Api\V2\SsoTokenSessionController;
use App\Http\Controllers\Api\V2\SyncJobBulkActionController;
use App\Http\Controllers\Api\V2\SyncJobCapabilitiesController;
use App\Http\Controllers\Api\V2\SyncJobController;
use App\Http\Controllers\Api\V2\SyncJobLogController;
use App\Http\Controllers\Api\V2\SyncJobScheduleResetController;
use App\Http\Controllers\Api\V2\TemporaryAliasController;
use App\Http\Controllers\Api\V2\TlsPolicyMapController;
use App\Http\Controllers\Api\V2\TransportController;
use App\Http\Controllers\Api\V2\UpdateStatusController;
use App\Http\Controllers\Api\V2\WebmailHandOffController;
use App\Http\Middleware\AuthorizeMonitoring;
use App\Rules\MailQueueId;
use App\Services\Logs\LogType;
use App\Services\Monitoring\ContainerRegistry;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| mailcow REST API v2 (contract: api/openapi.yaml)
|--------------------------------------------------------------------------
|
| Every route runs the `api.v2` pipeline (bootstrap/app.php), then per route
| `restv2.auth:<mode>`, `restv2.csrf` (unsafe session requests) and `restv2.pending:<mode>`.
| Specific routes come before general ones. tests/Unit/Architecture/RoutesMatchContractTest
| requires every route to be a contract operation of an implemented feature.
|
*/

// Feature 011: compose service names of containers (contract `ContainerName`).
$containerName = '[a-z0-9][a-z0-9_.-]{0,62}';

Route::prefix('api/v2')->middleware('api.v2')->group(function (): void {
    Route::get('/csrf-token', [CsrfTokenController::class, 'show'])
        ->middleware(['restv2.auth:browser', 'restv2.pending:allow'])
        ->name('csrf-token.show');

    Route::get('/session', [SessionController::class, 'show'])
        ->middleware(['restv2.auth:browser', 'restv2.pending:allow'])
        ->name('session.show');

    // Sign-in steps: browser sessions only, the anti-forgery token always (FR-011).
    Route::middleware(['restv2.auth:browser', 'restv2.csrf', 'restv2.pending:allow'])->group(function (): void {
        Route::post('/session', [SessionController::class, 'store'])->name('session.store');
        Route::delete('/session/pending-sign-in', [PendingSignInController::class, 'destroy'])->name('pending-sign-in.destroy');
        Route::post('/session/pending-sign-in/webauthn-challenge', [SecondFactorChallengeController::class, 'store'])->name('second-factor-challenge.store');
        Route::post('/session/pending-sign-in/second-factor', [SecondFactorController::class, 'store'])->name('second-factor.store');
        Route::post('/session/passkey-challenge', [PasskeyChallengeController::class, 'store'])->name('passkey-challenge.store');
        Route::post('/session/passkey-assertion', [PasskeyAssertionController::class, 'store'])->name('passkey-assertion.store');
        // Feature 012: single sign-on of mailbox users (ceremonies; failures are audited as sign-ins).
        Route::post('/session/single-sign-on', [SingleSignOnController::class, 'store'])->name('single-sign-on.store');
        Route::delete('/session/single-sign-on', [SingleSignOnController::class, 'destroy'])->name('single-sign-on.destroy');
        Route::post('/session/single-sign-on/completion', [SingleSignOnCompletionController::class, 'store'])->name('single-sign-on-completion.store');
        Route::post('/session/single-sign-on/provider-error', [SingleSignOnProviderErrorController::class, 'store'])->name('single-sign-on-provider-error.store');
        // Feature 007: a domain administrator's single sign-on token (failures are audited as sign-ins).
        Route::post('/session/sso-token', [SsoTokenSessionController::class, 'store'])->name('sso-token-session.store');
    });

    // Feature 007: the session's impersonation (login-as). Signed-in browser sessions only;
    // reading and ending also in restricted sessions.
    Route::get('/session/impersonation', [ImpersonationController::class, 'show'])
        ->middleware(['restv2.auth:session', 'restv2.pending:allow'])
        ->name('impersonation.show');
    Route::post('/session/impersonation', [ImpersonationController::class, 'store'])
        ->middleware(['restv2.auth:session', 'restv2.csrf', 'restv2.pending'])
        ->defaults('restv2.audit', ['event' => 'impersonationStart', 'target' => 'impersonation'])
        ->name('impersonation.store');
    Route::delete('/session/impersonation', [ImpersonationController::class, 'destroy'])
        ->middleware(['restv2.auth:session', 'restv2.csrf', 'restv2.pending:allow'])
        ->defaults('restv2.audit', ['event' => 'impersonationEnd', 'target' => 'impersonation'])
        ->name('impersonation.destroy');

    // Signing out needs the token only with a live session (R7).
    Route::delete('/session', [SessionController::class, 'destroy'])
        ->middleware(['restv2.auth:browser', 'restv2.csrf:live', 'restv2.pending:allow'])
        ->name('session.destroy');

    // Who am I (FR-028): sessions and API keys, also in restricted sessions.
    Route::get('/me', [IdentityController::class, 'show'])
        ->middleware(['restv2.auth:authenticated', 'restv2.pending:allow'])
        ->name('identity.show');

    // The signed-in user's preferences (FR-030, FR-031).
    Route::middleware('restv2.auth:session')->group(function (): void {
        Route::get('/me/preferences', [PreferencesController::class, 'show'])
            ->middleware('restv2.pending')
            ->name('preferences.show');
        Route::patch('/me/preferences', [PreferencesController::class, 'update'])
            ->middleware(['restv2.csrf', 'restv2.pending'])
            ->name('preferences.update');
    });

    // The signed-in user's own passkeys (FR-052, FR-053). Registering is also allowed when the
    // only pending action is second-factor setup, which it resolves (FR-054).
    Route::middleware('restv2.auth:session')->group(function (): void {
        Route::post('/me/passkeys/registration-options', [PasskeyRegistrationOptionsController::class, 'store'])
            ->middleware(['restv2.csrf', 'restv2.not-impersonating', 'restv2.pending:secondFactorSetup'])
            ->name('passkey-registration-options.store');
        Route::get('/me/passkeys', [PasskeyController::class, 'index'])
            ->middleware('restv2.pending')
            ->name('passkeys.index');
        Route::post('/me/passkeys', [PasskeyController::class, 'store'])
            ->middleware(['restv2.csrf', 'restv2.not-impersonating', 'restv2.pending:secondFactorSetup'])
            ->name('passkeys.store');
        Route::get('/me/passkeys/{passkeyId}', [PasskeyController::class, 'show'])
            ->middleware('restv2.pending')
            ->where('passkeyId', '[0-9a-f]{64}')
            ->name('passkeys.show');
        Route::patch('/me/passkeys/{passkeyId}', [PasskeyController::class, 'update'])
            ->middleware(['restv2.csrf', 'restv2.not-impersonating', 'restv2.pending'])
            ->where('passkeyId', '[0-9a-f]{64}')
            ->name('passkeys.update');
        Route::delete('/me/passkeys/{passkeyId}', [PasskeyController::class, 'destroy'])
            ->middleware(['restv2.csrf', 'restv2.not-impersonating', 'restv2.pending'])
            ->where('passkeyId', '[0-9a-f]{64}')
            ->name('passkeys.destroy');
    });

    // API keys (FR-032–FR-037, FR-057): administrators read them with a session or a key; only a
    // signed-in administrator creates and revokes them. owner-candidates comes before {apiKeyId}.
    Route::get('/api-keys', [ApiKeyController::class, 'index'])
        ->middleware(['restv2.auth:authenticated', 'restv2.pending'])
        ->name('api-keys.index');
    Route::post('/api-keys', [ApiKeyController::class, 'store'])
        ->middleware(['restv2.auth:session', 'restv2.csrf', 'restv2.pending'])
        ->name('api-keys.store');
    Route::get('/api-keys/owner-candidates', [ApiKeyOwnerCandidateController::class, 'index'])
        ->middleware(['restv2.auth:authenticated', 'restv2.pending'])
        ->name('api-key-owner-candidates.index');
    Route::get('/api-keys/{apiKeyId}', [ApiKeyController::class, 'show'])
        ->middleware(['restv2.auth:authenticated', 'restv2.pending'])
        ->where('apiKeyId', '[0-9A-HJKMNP-TV-Z]{26}')
        ->name('api-keys.show');
    Route::post('/api-keys/{apiKeyId}/revocation', [ApiKeyRevocationController::class, 'store'])
        ->middleware(['restv2.auth:session', 'restv2.csrf', 'restv2.pending'])
        ->where('apiKeyId', '[0-9A-HJKMNP-TV-Z]{26}')
        ->name('api-key-revocation.store');

    // Public branding configuration (FR-042–FR-044, FR-056): no authentication; an invalid API key
    // is still refused (R8).
    Route::middleware(['restv2.auth:public', 'restv2.pending:allow'])->group(function (): void {
        Route::get('/public-config', [PublicConfigController::class, 'show'])->name('public-config.show');
        Route::get('/public-config/logos/{variant}', [PublicConfigLogoController::class, 'show'])
            ->where('variant', 'light|dark')
            ->name('public-config-logo.show');
    });

    // The audit log (FR-047): administrators, with a session or a key. Read only.
    // Feature 002: domains. Specific routes (bulk-actions, sub-resources) before /domains/{domain};
    // the capabilities and the distinct tags live at top-level paths, so that single-label domain
    // names such as "tags" stay addressable.
    Route::middleware(['restv2.auth:authenticated', 'restv2.pending'])->group(function (): void {
        Route::get('/domains', [DomainController::class, 'index'])->name('domains.index');
        Route::get('/capabilities/domains', [DomainCapabilitiesController::class, 'show'])->name('domain-capabilities.show');
        Route::get('/domain-tags', [DomainTagController::class, 'index'])->name('domain-tags.index');
        Route::get('/domain-templates', [DomainTemplateController::class, 'index'])->name('domain-templates.index');
        Route::get('/domain-templates/{templateId}', [DomainTemplateController::class, 'show'])
            ->whereNumber('templateId')
            ->name('domain-templates.show');
        Route::get('/domains/{domain}/footer', [DomainFooterController::class, 'show'])
            ->where('domain', '[^/]+')
            ->name('domain-footer.show');
        Route::get('/domains/{domain}/deletion-preview', [DomainDeletionPreviewController::class, 'show'])
            ->where('domain', '[^/]+')
            ->name('domain-deletion-preview.show');
        Route::get('/domains/{domain}', [DomainController::class, 'show'])
            ->where('domain', '[^/]+')
            ->name('domains.show');
    });

    // State-changing domain routes: refusals of authenticated callers are audited (`restv2.audit`).
    Route::middleware(['restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])->group(function (): void {
        // POST-only: object paths never accept POST, so a domain named "bulk-actions" stays addressable.
        Route::post('/domains/bulk-actions', [DomainBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'domain'])
            ->name('domain-bulk-actions.store');
        Route::post('/domains', [DomainController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'domainCreation', 'target' => 'domain'])
            ->name('domains.store');
        Route::patch('/domains/{domain}', [DomainController::class, 'update'])
            ->where('domain', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'domainUpdate', 'target' => 'domain'])
            ->name('domains.update');
        Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])
            ->where('domain', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'domainDeletion', 'target' => 'domain'])
            ->name('domains.destroy');
        Route::put('/domains/{domain}/footer', [DomainFooterController::class, 'update'])
            ->where('domain', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'domainFooterUpdate', 'target' => 'domainFooter'])
            ->name('domain-footer.update');
        Route::post('/domain-templates/bulk-actions', [DomainTemplateBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'domainTemplate'])
            ->name('domain-template-bulk-actions.store');
        Route::post('/domain-templates', [DomainTemplateController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'domainTemplateCreation', 'target' => 'domainTemplate'])
            ->name('domain-templates.store');
        Route::patch('/domain-templates/{templateId}', [DomainTemplateController::class, 'update'])
            ->whereNumber('templateId')
            ->defaults('restv2.audit', ['event' => 'domainTemplateUpdate', 'target' => 'domainTemplate'])
            ->name('domain-templates.update');
        Route::delete('/domain-templates/{templateId}', [DomainTemplateController::class, 'destroy'])
            ->whereNumber('templateId')
            ->defaults('restv2.audit', ['event' => 'domainTemplateDeletion', 'target' => 'domainTemplate'])
            ->name('domain-templates.destroy');
    });

    // Feature 003: mailboxes, mailbox templates and the password policy. Literal paths
    // (bulk-actions, sub-resources) before /mailboxes/{mailbox}; the capabilities and the tags in use
    // live at top-level paths.
    Route::middleware(['restv2.auth:authenticated', 'restv2.pending'])->group(function (): void {
        Route::get('/mailboxes', [MailboxController::class, 'index'])->name('mailboxes.index');
        Route::get('/capabilities/mailboxes', [MailboxCapabilitiesController::class, 'show'])->name('mailbox-capabilities.show');
        Route::get('/mailbox-tags', [MailboxTagController::class, 'index'])->name('mailbox-tags.index');
        Route::get('/mailbox-templates', [MailboxTemplateController::class, 'index'])->name('mailbox-templates.index');
        Route::get('/mailbox-templates/{mailboxTemplateId}', [MailboxTemplateController::class, 'show'])
            ->whereNumber('mailboxTemplateId')
            ->name('mailbox-templates.show');
        Route::get('/password-policy', [PasswordPolicyController::class, 'show'])->name('password-policy.show');
        Route::get('/mailboxes/{mailbox}/deletion-preview', [MailboxDeletionPreviewController::class, 'show'])
            ->where('mailbox', '[^/]+')
            ->name('mailbox-deletion-preview.show');
        Route::get('/mailboxes/{mailbox}', [MailboxController::class, 'show'])
            ->where('mailbox', '[^/]+')
            ->name('mailboxes.show');
    });

    // State-changing mailbox routes: refusals of authenticated callers are audited (`restv2.audit`).
    Route::middleware(['restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])->group(function (): void {
        // POST-only: object paths never accept POST, so a mailbox named "bulk-actions" stays addressable.
        Route::post('/mailboxes/bulk-actions', [MailboxBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'mailbox'])
            ->name('mailbox-bulk-actions.store');
        Route::post('/mailboxes', [MailboxController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'mailboxCreation', 'target' => 'mailbox'])
            ->name('mailboxes.store');
        Route::patch('/mailboxes/{mailbox}', [MailboxController::class, 'update'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'mailboxUpdate', 'target' => 'mailbox'])
            ->name('mailboxes.update');
        Route::post('/mailboxes/{mailbox}/rename', [MailboxRenameController::class, 'store'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'mailboxRename', 'target' => 'mailbox'])
            ->name('mailbox-rename.store');
        Route::delete('/mailboxes/{mailbox}', [MailboxController::class, 'destroy'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'mailboxDeletion', 'target' => 'mailbox'])
            ->name('mailboxes.destroy');
        Route::post('/mailbox-templates/bulk-actions', [MailboxTemplateBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'mailboxTemplate'])
            ->name('mailbox-template-bulk-actions.store');
        Route::post('/mailbox-templates', [MailboxTemplateController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'mailboxTemplateCreation', 'target' => 'mailboxTemplate'])
            ->name('mailbox-templates.store');
        Route::patch('/mailbox-templates/{mailboxTemplateId}', [MailboxTemplateController::class, 'update'])
            ->whereNumber('mailboxTemplateId')
            ->defaults('restv2.audit', ['event' => 'mailboxTemplateUpdate', 'target' => 'mailboxTemplate'])
            ->name('mailbox-templates.update');
        Route::delete('/mailbox-templates/{mailboxTemplateId}', [MailboxTemplateController::class, 'destroy'])
            ->whereNumber('mailboxTemplateId')
            ->defaults('restv2.audit', ['event' => 'mailboxTemplateDeletion', 'target' => 'mailboxTemplate'])
            ->name('mailbox-templates.destroy');
    });

    // Feature 004: aliases, alias domains, resources and sender settings. Literal paths
    // (bulk-actions, capabilities) come before the parameterised ones; `{aliasId}` is digits only.
    Route::middleware(['restv2.auth:authenticated', 'restv2.pending'])->group(function (): void {
        Route::get('/aliases', [AliasController::class, 'index'])->name('aliases.index');
        Route::get('/capabilities/aliases', [AliasesCapabilitiesController::class, 'show'])->name('aliases-capabilities.show');
        Route::get('/aliases/{aliasId}', [AliasController::class, 'show'])
            ->where('aliasId', '[1-9][0-9]{0,9}')
            ->name('aliases.show');
        Route::get('/sender-permission-candidates', [SenderPermissionCandidateController::class, 'index'])->name('sender-permission-candidates.index');
        Route::get('/resources', [ResourceController::class, 'index'])->name('resources.index');
        Route::get('/resources/{resourceAddress}', [ResourceController::class, 'show'])
            ->where('resourceAddress', '[^/]+')
            ->name('resources.show');
        Route::get('/mailboxes/{mailbox}/sender-settings', [MailboxSenderSettingsController::class, 'show'])
            ->where('mailbox', '[^/]+')
            ->name('mailbox-sender-settings.show');
        Route::get('/alias-domains', [AliasDomainController::class, 'index'])->name('alias-domains.index');
        Route::get('/alias-domains/{aliasDomain}', [AliasDomainController::class, 'show'])
            ->where('aliasDomain', '[^/]+')
            ->name('alias-domains.show');
    });

    // State-changing routes of feature 004: refusals of authenticated callers are audited.
    Route::middleware(['restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])->group(function (): void {
        // POST-only bulk paths: object paths never accept POST.
        Route::post('/aliases/bulk-actions', [AliasBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'alias'])
            ->name('alias-bulk-actions.store');
        Route::post('/alias-domains/bulk-actions', [AliasDomainBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'aliasDomain'])
            ->name('alias-domain-bulk-actions.store');
        Route::post('/resources/bulk-actions', [ResourceBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'resource'])
            ->name('resource-bulk-actions.store');
        Route::post('/resources', [ResourceController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'resourceCreation', 'target' => 'resource'])
            ->name('resources.store');
        Route::patch('/resources/{resourceAddress}', [ResourceController::class, 'update'])
            ->where('resourceAddress', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'resourceUpdate', 'target' => 'resource'])
            ->name('resources.update');
        Route::delete('/resources/{resourceAddress}', [ResourceController::class, 'destroy'])
            ->where('resourceAddress', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'resourceDeletion', 'target' => 'resource'])
            ->name('resources.destroy');
        Route::post('/aliases/{aliasId}/alias-domain-copies', [AliasCopyController::class, 'store'])
            ->where('aliasId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'aliasCreation', 'target' => 'alias'])
            ->name('alias-domain-copies.store');
        Route::post('/aliases', [AliasController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'aliasCreation', 'target' => 'alias'])
            ->name('aliases.store');
        Route::patch('/aliases/{aliasId}', [AliasController::class, 'update'])
            ->where('aliasId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'aliasUpdate', 'target' => 'alias'])
            ->name('aliases.update');
        Route::delete('/aliases/{aliasId}', [AliasController::class, 'destroy'])
            ->where('aliasId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'aliasDeletion', 'target' => 'alias'])
            ->name('aliases.destroy');
        Route::patch('/mailboxes/{mailbox}/sender-settings', [MailboxSenderSettingsController::class, 'update'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'mailboxSenderSettingsUpdate', 'target' => 'mailboxSenderSettings'])
            ->name('mailbox-sender-settings.update');
        Route::post('/alias-domains', [AliasDomainController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'aliasDomainCreation', 'target' => 'aliasDomain'])
            ->name('alias-domains.store');
        Route::patch('/alias-domains/{aliasDomain}', [AliasDomainController::class, 'update'])
            ->where('aliasDomain', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'aliasDomainUpdate', 'target' => 'aliasDomain'])
            ->name('alias-domains.update');
        Route::delete('/alias-domains/{aliasDomain}', [AliasDomainController::class, 'destroy'])
            ->where('aliasDomain', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'aliasDomainDeletion', 'target' => 'aliasDomain'])
            ->name('alias-domains.destroy');
    });

    // Feature 011: monitoring and operations, administrators only (research R26). The monitoring
    // gate runs last, before any validation or lookup. Reads first; state-changing routes declare
    // `restv2.audit`, and so does the message content view (the one audited read).
    Route::middleware(['restv2.auth:authenticated', 'restv2.pending', AuthorizeMonitoring::class])->group(function (): void {
        Route::get('/host', [HostOverviewController::class, 'show'])->name('host.show');
        Route::get('/host/mail-storage', [MailStorageUsageController::class, 'show'])->name('host-mail-storage.show');
        Route::get('/capabilities/monitoring', [MonitoringCapabilitiesController::class, 'show'])->name('monitoring-capabilities.show');
        Route::get('/host/public-addresses', [PublicAddressesController::class, 'show'])->name('host-public-addresses.show');
        Route::get('/installation', [InstallationController::class, 'show'])->name('installation.show');
        Route::get('/installation/update-status', [UpdateStatusController::class, 'show'])->name('installation-update-status.show');
        Route::get('/containers', [ContainerController::class, 'index'])->name('containers.index');
        Route::get('/containers/{containerName}', [ContainerController::class, 'show'])
            ->where('containerName', ContainerRegistry::NAME_PATTERN)
            ->name('containers.show');
        Route::get('/containers/{containerName}/resource-usage', [ContainerResourceUsageController::class, 'show'])
            ->where('containerName', ContainerRegistry::NAME_PATTERN)
            ->name('container-resource-usage.show');

        Route::get('/mail-queue/messages', [MailQueueMessageController::class, 'index'])->name('mail-queue-messages.index');
        Route::get('/mail-queue/messages/{queueId}/content', [MailQueueMessageContentController::class, 'show'])
            ->where('queueId', MailQueueId::PATTERN)
            ->defaults('restv2.audit', ['event' => 'mailQueueMessageView', 'target' => 'mailQueueMessage'])
            ->name('mail-queue-message-content.show');

        Route::get('/fail2ban/settings', [Fail2BanSettingsController::class, 'show'])->name('fail2ban-settings.show');
        Route::get('/fail2ban/rules', [Fail2BanRulesController::class, 'show'])->name('fail2ban-rules.show');
        Route::get('/fail2ban/active-bans', [Fail2BanActiveBanController::class, 'index'])->name('fail2ban-active-bans.index');
        Route::get('/fail2ban/permanent-bans', [Fail2BanPermanentBanController::class, 'index'])->name('fail2ban-permanent-bans.index');

        Route::get('/rspamd/history', [RspamdHistoryController::class, 'index'])->name('rspamd-history.index');
        Route::get('/rspamd/statistics', [RspamdStatisticsController::class, 'show'])->name('rspamd-statistics.show');

        foreach (LogType::cases() as $logType) {
            Route::get('/logs/'.$logType->value, [LogEntryController::class, 'index'])
                ->defaults('logType', $logType->value)
                ->name('logs.'.$logType->value);
        }
    });

    Route::middleware(['restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending', AuthorizeMonitoring::class])->group(function (): void {
        Route::post('/containers/{containerName}/start', [ContainerStartController::class, 'store'])
            ->where('containerName', ContainerRegistry::NAME_PATTERN)
            ->defaults('restv2.audit', ['event' => 'containerStart', 'target' => 'container'])
            ->name('container-start.store');
        Route::post('/containers/{containerName}/stop', [ContainerStopController::class, 'store'])
            ->where('containerName', ContainerRegistry::NAME_PATTERN)
            ->defaults('restv2.audit', ['event' => 'containerStop', 'target' => 'container'])
            ->name('container-stop.store');
        Route::post('/containers/{containerName}/restart', [ContainerRestartController::class, 'store'])
            ->where('containerName', ContainerRegistry::NAME_PATTERN)
            ->defaults('restv2.audit', ['event' => 'containerRestart', 'target' => 'container'])
            ->name('container-restart.store');
        // POST-only: queue IDs are hexadecimal, so they never collide with `bulk-actions`.
        Route::post('/mail-queue/messages/bulk-actions', [MailQueueBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'mailQueue'])
            ->name('mail-queue-bulk-actions.store');
        Route::post('/mail-queue/flush', [MailQueueFlushController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'mailQueueFlush', 'target' => 'mailQueue'])
            ->name('mail-queue-flush.store');
        Route::post('/mail-queue/purge', [MailQueuePurgeController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'mailQueuePurge', 'target' => 'mailQueue'])
            ->name('mail-queue-purge.store');
        Route::patch('/fail2ban/settings', [Fail2BanSettingsController::class, 'update'])
            ->defaults('restv2.audit', ['event' => 'fail2banSettingsUpdate', 'target' => 'fail2banSettings'])
            ->name('fail2ban-settings.update');
        Route::post('/fail2ban/ban-list-rotation', [Fail2BanBanListRotationController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fail2banBanListIdRotation', 'target' => 'fail2banBanListId'])
            ->name('fail2ban-ban-list-rotation.store');
        Route::put('/fail2ban/rules', [Fail2BanRulesController::class, 'update'])
            ->defaults('restv2.audit', ['event' => 'fail2banRulesReplacement', 'target' => 'fail2banRules'])
            ->name('fail2ban-rules.update');
        Route::post('/fail2ban/rules/reset', [Fail2BanRulesResetController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fail2banRulesReset', 'target' => 'fail2banRules'])
            ->name('fail2ban-rules-reset.store');
        // The network carries a percent-encoded `/` (research R30), so it matches `.+`.
        Route::post('/fail2ban/active-bans/{network}/lift', [Fail2BanBanLiftController::class, 'store'])
            ->where('network', '.+')
            ->defaults('restv2.audit', ['event' => 'fail2banBanLift', 'target' => 'fail2banActiveBan'])
            ->name('fail2ban-ban-lift.store');
        Route::post('/fail2ban/active-bans/{network}/allowlist', [Fail2BanBanAllowlistController::class, 'store'])
            ->where('network', '.+')
            ->defaults('restv2.audit', ['event' => 'fail2banBanAllowlisting', 'target' => 'fail2banActiveBan'])
            ->name('fail2ban-ban-allowlist.store');
        Route::post('/fail2ban/active-bans/{network}/denylist', [Fail2BanBanDenylistController::class, 'store'])
            ->where('network', '.+')
            ->defaults('restv2.audit', ['event' => 'fail2banBanDenylisting', 'target' => 'fail2banActiveBan'])
            ->name('fail2ban-ban-denylist.store');
    });

    // Feature 008: sync jobs. Literal paths (bulk-actions) come before `{syncJobId}`, which is
    // digits only; the capabilities live at the top-level path /capabilities/sync-jobs.
    Route::middleware(['restv2.auth:authenticated', 'restv2.pending'])->group(function (): void {
        Route::get('/sync-jobs', [SyncJobController::class, 'index'])->name('sync-jobs.index');
        Route::get('/capabilities/sync-jobs', [SyncJobCapabilitiesController::class, 'show'])->name('sync-jobs-capabilities.show');
        Route::get('/sync-jobs/{syncJobId}', [SyncJobController::class, 'show'])
            ->where('syncJobId', '[1-9][0-9]{0,9}')
            ->name('sync-jobs.show');
        Route::get('/sync-jobs/{syncJobId}/log', [SyncJobLogController::class, 'show'])
            ->where('syncJobId', '[1-9][0-9]{0,9}')
            ->name('sync-job-log.show');
    });

    // State-changing sync-job routes: refusals of authenticated callers are audited (`restv2.audit`).
    Route::middleware(['restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])->group(function (): void {
        // POST-only bulk path: object paths never accept POST.
        Route::post('/sync-jobs/bulk-actions', [SyncJobBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'syncJob'])
            ->name('sync-job-bulk-actions.store');
        Route::post('/sync-jobs', [SyncJobController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'syncJobCreation', 'target' => 'syncJob'])
            ->name('sync-jobs.store');
        Route::patch('/sync-jobs/{syncJobId}', [SyncJobController::class, 'update'])
            ->where('syncJobId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'syncJobUpdate', 'target' => 'syncJob'])
            ->name('sync-jobs.update');
        Route::delete('/sync-jobs/{syncJobId}', [SyncJobController::class, 'destroy'])
            ->where('syncJobId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'syncJobDeletion', 'target' => 'syncJob'])
            ->name('sync-jobs.destroy');
        Route::post('/sync-jobs/{syncJobId}/schedule-reset', [SyncJobScheduleResetController::class, 'store'])
            ->where('syncJobId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'syncJobReschedule', 'target' => 'syncJob'])
            ->name('sync-job-schedule-reset.store');
    });

    // Feature 012: the identity provider configuration (administrators only; refusals of
    // authenticated callers of the state-changing routes are audited).
    Route::middleware(['restv2.auth:authenticated', 'restv2.pending'])->group(function (): void {
        Route::get('/capabilities/identity-provider', [IdentityProviderCapabilitiesController::class, 'show'])->name('identity-provider-capabilities.show');
        Route::get('/identity-provider', [IdentityProviderController::class, 'show'])->name('identity-provider.show');
    });
    Route::middleware(['restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])->group(function (): void {
        Route::put('/identity-provider', [IdentityProviderController::class, 'save'])
            ->defaults('restv2.audit', ['event' => 'identityProviderCreation', 'target' => 'identityProvider'])
            ->name('identity-provider.save');
        Route::patch('/identity-provider', [IdentityProviderController::class, 'update'])
            ->defaults('restv2.audit', ['event' => 'identityProviderUpdate', 'target' => 'identityProvider'])
            ->name('identity-provider.update');
        Route::delete('/identity-provider', [IdentityProviderController::class, 'destroy'])
            ->defaults('restv2.audit', ['event' => 'identityProviderDeletion', 'target' => 'identityProvider'])
            ->name('identity-provider.destroy');
        Route::post('/identity-provider/connection-tests', [IdentityProviderConnectionTestController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'identityProviderConnectionTest', 'target' => 'identityProvider'])
            ->name('identity-provider-connection-tests.store');
    });

    // Feature 012: clients of mailcow's own OAuth2 provider. bulk-actions before {oauth2ClientId},
    // which is numeric.
    Route::middleware(['restv2.auth:authenticated', 'restv2.pending'])->group(function (): void {
        Route::get('/capabilities/oauth2-clients', [Oauth2ClientCapabilitiesController::class, 'show'])->name('oauth2-client-capabilities.show');
        Route::get('/oauth2-clients', [Oauth2ClientController::class, 'index'])->name('oauth2-clients.index');
        Route::get('/oauth2-clients/{oauth2ClientId}', [Oauth2ClientController::class, 'show'])
            ->where('oauth2ClientId', '[1-9][0-9]{0,9}')
            ->name('oauth2-clients.show');
    });
    Route::middleware(['restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])->group(function (): void {
        Route::post('/oauth2-clients/bulk-actions', [Oauth2ClientBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'oauth2Client'])
            ->name('oauth2-client-bulk-actions.store');
        Route::post('/oauth2-clients', [Oauth2ClientController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'oauth2ClientCreation', 'target' => 'oauth2Client'])
            ->name('oauth2-clients.store');
        Route::patch('/oauth2-clients/{oauth2ClientId}', [Oauth2ClientController::class, 'update'])
            ->where('oauth2ClientId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'oauth2ClientUpdate', 'target' => 'oauth2Client'])
            ->name('oauth2-clients.update');
        Route::delete('/oauth2-clients/{oauth2ClientId}', [Oauth2ClientController::class, 'destroy'])
            ->where('oauth2ClientId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'oauth2ClientDeletion', 'target' => 'oauth2Client'])
            ->name('oauth2-clients.destroy');
        Route::post('/oauth2-clients/{oauth2ClientId}/secret-regeneration', [Oauth2ClientSecretRegenerationController::class, 'store'])
            ->where('oauth2ClientId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'oauth2ClientSecretRegeneration', 'target' => 'oauth2Client'])
            ->name('oauth2-client-secret-regeneration.store');
        Route::post('/oauth2-clients/{oauth2ClientId}/token-revocation', [Oauth2ClientTokenRevocationController::class, 'store'])
            ->where('oauth2ClientId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'oauth2ClientTokenRevocation', 'target' => 'oauth2Client'])
            ->name('oauth2-client-token-revocation.store');
    });

    // Feature 009: routing and address rewriting. Literal paths (bulk-actions, the helper lists,
    // the capabilities) come before the parameterised ones; entry IDs are digits only.
    Route::middleware(['restv2.auth:authenticated', 'restv2.pending'])->group(function (): void {
        Route::get('/relayhosts', [RelayhostController::class, 'index'])->name('relayhosts.index');
        Route::get('/capabilities/routing', [RoutingCapabilitiesController::class, 'show'])->name('routing-capabilities.show');
        Route::get('/relayhost-choices', [RelayhostChoiceController::class, 'index'])->name('relayhost-choices.index');
        Route::get('/relayhosts/{relayhostId}', [RelayhostController::class, 'show'])
            ->where('relayhostId', '[1-9][0-9]{0,9}')
            ->name('relayhosts.show');
        Route::get('/relayhosts/{relayhostId}/usage', [RelayhostUsageController::class, 'index'])
            ->where('relayhostId', '[1-9][0-9]{0,9}')
            ->name('relayhost-usage.index');
        Route::get('/transports', [TransportController::class, 'index'])->name('transports.index');
        Route::get('/bcc-maps', [BccMapController::class, 'index'])->name('bcc-maps.index');
        Route::get('/forwarding-hosts', [ForwardingHostController::class, 'index'])->name('forwarding-hosts.index');
        // The address carries a percent-encoded `/` (research R1), so it matches `.+`.
        Route::get('/forwarding-hosts/{forwardingHostAddress}', [ForwardingHostController::class, 'show'])
            ->where('forwardingHostAddress', '.+')
            ->name('forwarding-hosts.show');
        Route::get('/recipient-maps', [RecipientMapController::class, 'index'])->name('recipient-maps.index');
        Route::get('/recipient-maps/{recipientMapId}', [RecipientMapController::class, 'show'])
            ->where('recipientMapId', '[1-9][0-9]{0,9}')
            ->name('recipient-maps.show');
        Route::get('/tls-policy-maps', [TlsPolicyMapController::class, 'index'])->name('tls-policy-maps.index');
        Route::get('/tls-policy-maps/{tlsPolicyMapId}', [TlsPolicyMapController::class, 'show'])
            ->where('tlsPolicyMapId', '[1-9][0-9]{0,9}')
            ->name('tls-policy-maps.show');
        Route::get('/bcc-destination-choices', [BccMapController::class, 'choices'])->name('bcc-destination-choices.index');
        Route::get('/bcc-maps/{bccMapId}', [BccMapController::class, 'show'])
            ->where('bccMapId', '[1-9][0-9]{0,9}')
            ->name('bcc-maps.show');
        Route::get('/transports/{transportId}', [TransportController::class, 'show'])
            ->where('transportId', '[1-9][0-9]{0,9}')
            ->name('transports.show');
        Route::get('/domains/{domain}/relayhost-assignment', [RelayhostAssignmentController::class, 'showDomain'])
            ->where('domain', '[^/]+')
            ->name('domain-relayhost-assignment.show');
        Route::get('/mailboxes/{mailbox}/relayhost-assignment', [RelayhostAssignmentController::class, 'showMailbox'])
            ->where('mailbox', '[^/]+')
            ->name('mailbox-relayhost-assignment.show');
    });

    // State-changing routes of feature 009: refusals of authenticated callers are audited.
    Route::middleware(['restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])->group(function (): void {
        // POST-only bulk paths: object paths never accept POST.
        Route::post('/relayhosts/bulk-actions', [RoutingBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'relayhost'])
            ->name('relayhost-bulk-actions.store');
        Route::post('/relayhosts', [RelayhostController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'relayhostCreation', 'target' => 'relayhost'])
            ->name('relayhosts.store');
        Route::patch('/relayhosts/{relayhostId}', [RelayhostController::class, 'update'])
            ->where('relayhostId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'relayhostUpdate', 'target' => 'relayhost'])
            ->name('relayhosts.update');
        Route::delete('/relayhosts/{relayhostId}', [RelayhostController::class, 'destroy'])
            ->where('relayhostId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'relayhostDeletion', 'target' => 'relayhost'])
            ->name('relayhosts.destroy');
        Route::post('/relayhosts/{relayhostId}/connection-test', [RoutingConnectionTestController::class, 'relayhost'])
            ->where('relayhostId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'relayhostTest', 'target' => 'relayhost'])
            ->name('relayhost-connection-test.store');
        Route::post('/transports/{transportId}/connection-test', [RoutingConnectionTestController::class, 'transport'])
            ->where('transportId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'transportTest', 'target' => 'transport'])
            ->name('transport-connection-test.store');
        Route::post('/recipient-maps/bulk-actions', [RoutingBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'recipientMap'])
            ->name('recipient-map-bulk-actions.store');
        Route::post('/recipient-maps', [RecipientMapController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'recipientMapCreation', 'target' => 'recipientMap'])
            ->name('recipient-maps.store');
        Route::patch('/recipient-maps/{recipientMapId}', [RecipientMapController::class, 'update'])
            ->where('recipientMapId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'recipientMapUpdate', 'target' => 'recipientMap'])
            ->name('recipient-maps.update');
        Route::delete('/recipient-maps/{recipientMapId}', [RecipientMapController::class, 'destroy'])
            ->where('recipientMapId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'recipientMapDeletion', 'target' => 'recipientMap'])
            ->name('recipient-maps.destroy');
        Route::post('/tls-policy-maps/bulk-actions', [RoutingBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'tlsPolicyMap'])
            ->name('tls-policy-map-bulk-actions.store');
        Route::post('/tls-policy-maps', [TlsPolicyMapController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'tlsPolicyMapCreation', 'target' => 'tlsPolicyMap'])
            ->name('tls-policy-maps.store');
        Route::patch('/tls-policy-maps/{tlsPolicyMapId}', [TlsPolicyMapController::class, 'update'])
            ->where('tlsPolicyMapId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'tlsPolicyMapUpdate', 'target' => 'tlsPolicyMap'])
            ->name('tls-policy-maps.update');
        Route::delete('/tls-policy-maps/{tlsPolicyMapId}', [TlsPolicyMapController::class, 'destroy'])
            ->where('tlsPolicyMapId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'tlsPolicyMapDeletion', 'target' => 'tlsPolicyMap'])
            ->name('tls-policy-maps.destroy');
        Route::post('/forwarding-hosts/bulk-actions', [RoutingBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'forwardingHost'])
            ->name('forwarding-host-bulk-actions.store');
        Route::post('/forwarding-hosts', [ForwardingHostController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'forwardingHostCreation', 'target' => 'forwardingHost'])
            ->name('forwarding-hosts.store');
        Route::patch('/forwarding-hosts/{forwardingHostAddress}', [ForwardingHostController::class, 'update'])
            ->where('forwardingHostAddress', '.+')
            ->defaults('restv2.audit', ['event' => 'forwardingHostUpdate', 'target' => 'forwardingHost'])
            ->name('forwarding-hosts.update');
        Route::delete('/forwarding-hosts/{forwardingHostAddress}', [ForwardingHostController::class, 'destroy'])
            ->where('forwardingHostAddress', '.+')
            ->defaults('restv2.audit', ['event' => 'forwardingHostDeletion', 'target' => 'forwardingHost'])
            ->name('forwarding-hosts.destroy');
        Route::post('/bcc-maps/bulk-actions', [RoutingBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'bccMap'])
            ->name('bcc-map-bulk-actions.store');
        Route::post('/bcc-maps', [BccMapController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'bccMapCreation', 'target' => 'bccMap'])
            ->name('bcc-maps.store');
        Route::patch('/bcc-maps/{bccMapId}', [BccMapController::class, 'update'])
            ->where('bccMapId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'bccMapUpdate', 'target' => 'bccMap'])
            ->name('bcc-maps.update');
        Route::delete('/bcc-maps/{bccMapId}', [BccMapController::class, 'destroy'])
            ->where('bccMapId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'bccMapDeletion', 'target' => 'bccMap'])
            ->name('bcc-maps.destroy');
        Route::post('/transports/bulk-actions', [RoutingBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'transport'])
            ->name('transport-bulk-actions.store');
        Route::post('/transports', [TransportController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'transportCreation', 'target' => 'transport'])
            ->name('transports.store');
        Route::patch('/transports/{transportId}', [TransportController::class, 'update'])
            ->where('transportId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'transportUpdate', 'target' => 'transport'])
            ->name('transports.update');
        Route::delete('/transports/{transportId}', [TransportController::class, 'destroy'])
            ->where('transportId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'transportDeletion', 'target' => 'transport'])
            ->name('transports.destroy');
        Route::put('/domains/{domain}/relayhost-assignment', [RelayhostAssignmentController::class, 'updateDomain'])
            ->where('domain', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'relayhostAssignmentUpdate', 'target' => 'domainRelayhostAssignment'])
            ->name('domain-relayhost-assignment.update');
        Route::put('/mailboxes/{mailbox}/relayhost-assignment', [RelayhostAssignmentController::class, 'updateMailbox'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'relayhostAssignmentUpdate', 'target' => 'mailboxRelayhostAssignment'])
            ->name('mailbox-relayhost-assignment.update');
    });
    // Feature 005: account self-service. The capabilities are readable signed out (the reset
    // availability is needed before sign-in), with the session's account, and in restricted sessions.
    Route::get('/capabilities/self-service', [SelfServiceCapabilitiesController::class, 'show'])
        ->middleware(['restv2.auth:optional', 'restv2.pending:allow'])
        ->name('self-service-capabilities.show');

    // Feature 010: DKIM keys, MTA-STS policies and DNS diagnostics. Names are percent-encoded
    // domain names, so `[^/]+`. The sub-resources of a key and the POST-only bulk path come before
    // `/dkim-keys/{dkimName}`, which never accepts POST (a key named `bulk-actions` stays readable
    // and deletable). State-changing routes and the export declare `restv2.audit` (research R22).
    Route::middleware(['restv2.auth:authenticated', 'restv2.pending'])->group(function (): void {
        Route::get('/capabilities/dns', [DnsCapabilitiesController::class, 'show'])->name('dns-capabilities.show');
        Route::get('/dkim-keys', [DkimKeyController::class, 'index'])->name('dkim-keys.index');
        // The export is a read whose every attempt is audited (FR-026).
        Route::get('/dkim-keys/{dkimName}/private-key', [DkimPrivateKeyController::class, 'show'])
            ->where('dkimName', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'dkimPrivateKeyExport', 'target' => 'dkimKey'])
            ->name('dkim-private-key.show');
        Route::get('/dkim-keys/{dkimName}', [DkimKeyController::class, 'show'])
            ->where('dkimName', '[^/]+')
            ->name('dkim-keys.show');
        Route::get('/dns-diagnostics/{diagnosticsName}', [DnsDiagnosticsController::class, 'show'])
            ->where('diagnosticsName', '[^/]+')
            ->name('dns-diagnostics.show');
        Route::get('/mta-sts-policies', [MtaStsPolicyController::class, 'index'])->name('mta-sts-policies.index');
        Route::get('/mta-sts-policies/{policyDomain}', [MtaStsPolicyController::class, 'show'])
            ->where('policyDomain', '[^/]+')
            ->name('mta-sts-policies.show');
    });
    Route::middleware(['restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])->group(function (): void {
        // POST-only bulk path and the key's sub-resources before `/dkim-keys/{dkimName}`.
        Route::post('/dkim-keys/bulk-actions', [DkimKeyBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'dkimKey'])
            ->name('dkim-key-bulk-actions.store');
        Route::post('/dkim-keys', [DkimKeyController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'dkimKeyGeneration', 'target' => 'dkimKey'])
            ->name('dkim-keys.store');
        Route::post('/dkim-keys/{dkimName}/import', [DkimKeyImportController::class, 'store'])
            ->where('dkimName', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'dkimKeyImport', 'target' => 'dkimKey'])
            ->name('dkim-key-import.store');
        Route::post('/dkim-keys/{dkimName}/copy', [DkimKeyCopyController::class, 'store'])
            ->where('dkimName', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'dkimKeyCopy', 'target' => 'dkimKey'])
            ->name('dkim-key-copy.store');
        Route::post('/dkim-keys/{dkimName}/dns-publication', [DkimDnsPublicationController::class, 'store'])
            ->where('dkimName', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'dkimDnsPublication', 'target' => 'dkimKey'])
            ->name('dkim-dns-publication.store');
        Route::delete('/dkim-keys/{dkimName}', [DkimKeyController::class, 'destroy'])
            ->where('dkimName', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'dkimKeyDeletion', 'target' => 'dkimKey'])
            ->name('dkim-keys.destroy');
        Route::post('/mta-sts-policies', [MtaStsPolicyController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'mtaStsPolicyCreation', 'target' => 'mtaStsPolicy'])
            ->name('mta-sts-policies.store');
        Route::patch('/mta-sts-policies/{policyDomain}', [MtaStsPolicyController::class, 'update'])
            ->where('policyDomain', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'mtaStsPolicyUpdate', 'target' => 'mtaStsPolicy'])
            ->name('mta-sts-policies.update');
    });

    // The signed-in account's own password and second factors (feature 005 US1, US2): sessions
    // only, every role. A restricted session may resolve its forced actions here (research R5).
    Route::middleware(['restv2.own', 'restv2.auth:session'])->group(function (): void {
        Route::get('/me/password-policy', [OwnPasswordPolicyController::class, 'show'])
            ->middleware('restv2.pending:includes,passwordChange')
            ->name('own-password-policy.show');
        Route::put('/me/password', [OwnPasswordController::class, 'update'])
            ->middleware(['restv2.csrf', 'restv2.not-impersonating', 'restv2.pending:includes,passwordChange'])
            ->defaults('restv2.audit', ['event' => 'ownPasswordChange', 'target' => 'accountPassword'])
            ->name('own-password.update');

        Route::get('/me/second-factors', [OwnSecondFactorController::class, 'index'])
            ->middleware('restv2.pending:includes,secondFactorSetup')
            ->name('own-second-factors.index');
        Route::post('/me/second-factors', [OwnSecondFactorController::class, 'store'])
            ->middleware(['restv2.csrf', 'restv2.not-impersonating', 'restv2.pending:includes,secondFactorSetup'])
            ->defaults('restv2.audit', ['event' => 'ownSecondFactorEnrolment', 'target' => 'secondFactor'])
            ->name('own-second-factors.store');
        // Ceremony requests (TOTP start, WebAuthn options) are not audited (plan Complexity Tracking).
        Route::post('/me/second-factors/totp-enrolment', [OwnTotpEnrolmentController::class, 'store'])
            ->middleware(['restv2.csrf', 'restv2.not-impersonating', 'restv2.pending:includes,secondFactorSetup'])
            ->name('own-totp-enrolment.store');
        Route::post('/me/second-factors/webauthn-registration-options', [OwnSecondFactorWebAuthnOptionsController::class, 'store'])
            ->middleware(['restv2.csrf', 'restv2.not-impersonating', 'restv2.pending:includes,secondFactorSetup'])
            ->name('own-second-factor-webauthn-options.store');
        Route::post('/me/second-factors/removal', [OwnSecondFactorRemovalController::class, 'store'])
            ->middleware(['restv2.csrf', 'restv2.not-impersonating', 'restv2.pending'])
            ->defaults('restv2.audit', ['event' => 'ownSecondFactorRemoval', 'target' => 'secondFactor'])
            ->name('own-second-factor-removal.store');
        Route::get('/me/second-factors/{secondFactorId}', [OwnSecondFactorController::class, 'show'])
            ->middleware('restv2.pending')
            ->where('secondFactorId', '[1-9][0-9]{0,9}')
            ->name('own-second-factors.show');
        Route::delete('/me/second-factors/{secondFactorId}', [OwnSecondFactorController::class, 'destroy'])
            ->middleware(['restv2.csrf', 'restv2.not-impersonating', 'restv2.pending'])
            ->where('secondFactorId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'ownSecondFactorRemoval', 'target' => 'secondFactor'])
            ->name('own-second-factors.destroy');
    });

    // The signed-in mailbox user's recovery address (feature 005 US3).
    Route::middleware(['restv2.own', 'restv2.auth:session'])->group(function (): void {
        Route::get('/me/recovery-email', [OwnRecoveryEmailController::class, 'show'])
            ->middleware('restv2.pending')
            ->name('own-recovery-email.show');
        Route::put('/me/recovery-email', [OwnRecoveryEmailController::class, 'update'])
            ->middleware(['restv2.csrf', 'restv2.not-impersonating', 'restv2.pending'])
            ->defaults('restv2.audit', ['event' => 'ownRecoveryEmailChange', 'target' => 'recoveryEmail'])
            ->name('own-recovery-email.update');
    });

    // Password reset through the recovery address (feature 005 US3): browser sessions, signed in or
    // not, with the anti-forgery token, like sign-in. The service audits requests and completions.
    Route::middleware(['restv2.auth:browser', 'restv2.csrf', 'restv2.pending:allow'])->group(function (): void {
        Route::post('/password-resets', [PasswordResetController::class, 'store'])->name('password-resets.store');
        Route::post('/password-resets/token-check', [PasswordResetController::class, 'check'])->name('password-reset-token-check.store');
        Route::post('/password-resets/webauthn-challenge', [PasswordResetController::class, 'webauthnChallenge'])->name('password-reset-webauthn-challenge.store');
        Route::post('/password-resets/completion', [PasswordResetController::class, 'complete'])->name('password-reset-completion.store');
    });

    // The signed-in mailbox user's own mailbox (feature 005 US5): overview and webmail hand-off.
    Route::middleware(['restv2.own', 'restv2.auth:session'])->group(function (): void {
        Route::get('/me/mailbox', [OwnMailboxOverviewController::class, 'show'])
            ->middleware('restv2.pending')
            ->name('own-mailbox.show');
        Route::post('/me/webmail-handoff', [WebmailHandOffController::class, 'store'])
            ->middleware(['restv2.csrf', 'restv2.not-impersonating', 'restv2.pending'])
            ->defaults('restv2.audit', ['event' => 'webmailHandOff', 'target' => 'webmailHandOff'])
            ->name('webmail-handoff.store');
        Route::get('/me/mailbox/client-configuration', [OwnClientConfigurationController::class, 'show'])
            ->middleware('restv2.pending')
            ->name('own-client-configuration.show');
        Route::get('/me/mailbox/apple-profile', [OwnAppleProfileController::class, 'show'])
            ->middleware('restv2.pending')
            ->name('own-apple-profile.show');
        Route::post('/me/mailbox/apple-profile', [OwnAppleProfileController::class, 'store'])
            ->middleware(['restv2.csrf', 'restv2.pending'])
            ->defaults('restv2.audit', ['event' => 'appPasswordCreation', 'target' => 'appPassword'])
            ->name('own-apple-profile.store');
    });

    // Self-service objects of the mailboxes in the caller's scope (feature 005 US4, US6–US10):
    // sessions and keys; reads need no permission flag. Literal paths before {mailbox} ones.
    Route::middleware(['restv2.auth:authenticated', 'restv2.pending'])->group(function (): void {
        Route::get('/mailboxes/{mailbox}/app-passwords', [AppPasswordController::class, 'index'])
            ->where('mailbox', '[^/]+')
            ->name('app-passwords.index');
        Route::get('/mailboxes/{mailbox}/app-passwords/{appPasswordId}', [AppPasswordController::class, 'show'])
            ->where(['mailbox' => '[^/]+', 'appPasswordId' => '[1-9][0-9]{0,9}'])
            ->name('app-passwords.show');
        Route::get('/mailboxes/{mailbox}/temporary-aliases', [TemporaryAliasController::class, 'index'])
            ->where('mailbox', '[^/]+')
            ->name('temporary-aliases.index');
        Route::get('/mailboxes/{mailbox}/temporary-aliases/{temporaryAlias}', [TemporaryAliasController::class, 'show'])
            ->where(['mailbox' => '[^/]+', 'temporaryAlias' => '[^/]+'])
            ->name('temporary-aliases.show');
        Route::get('/mailboxes/{mailbox}/temporary-alias-domains', [TemporaryAliasController::class, 'domains'])
            ->where('mailbox', '[^/]+')
            ->name('temporary-alias-domains.index');
        Route::get('/mailboxes/{mailbox}/mail-settings', [MailboxMailSettingsController::class, 'show'])
            ->where('mailbox', '[^/]+')
            ->name('mailbox-mail-settings.show');
        Route::get('/mailboxes/{mailbox}/push-notifications', [PushNotificationSettingsController::class, 'show'])
            ->where('mailbox', '[^/]+')
            ->name('push-notifications.show');
        Route::get('/mailboxes/{mailbox}/recent-logins', [RecentLoginController::class, 'index'])
            ->where('mailbox', '[^/]+')
            ->name('recent-logins.index');
        Route::get('/sieve-filters', [SieveFilterController::class, 'index'])
            ->name('sieve-filters.index');
        Route::get('/mailboxes/{mailbox}/sieve-filters', [SieveFilterController::class, 'mailboxIndex'])
            ->where('mailbox', '[^/]+')
            ->name('mailbox-sieve-filters.index');
        Route::get('/mailboxes/{mailbox}/sieve-filters/{sieveFilterId}', [SieveFilterController::class, 'show'])
            ->where(['mailbox' => '[^/]+', 'sieveFilterId' => '[1-9][0-9]{0,9}'])
            ->name('mailbox-sieve-filters.show');
        Route::get('/mailboxes/{mailbox}/active-sieve-script', [SieveFilterController::class, 'active'])
            ->where('mailbox', '[^/]+')
            ->name('active-sieve-script.show');
    });

    Route::middleware(['restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])->group(function (): void {
        // POST-only bulk path: object paths never accept POST.
        Route::post('/mailboxes/mail-settings/bulk-actions', [MailboxMailSettingsController::class, 'bulk'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'mailSettings'])
            ->name('mailbox-mail-settings-bulk-actions.store');
        Route::patch('/mailboxes/{mailbox}/mail-settings', [MailboxMailSettingsController::class, 'update'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'mailSettingsUpdate', 'target' => 'mailSettings'])
            ->name('mailbox-mail-settings.update');
        Route::put('/mailboxes/{mailbox}/push-notifications', [PushNotificationSettingsController::class, 'update'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'pushNotificationSettingsUpdate', 'target' => 'pushNotificationSettings'])
            ->name('push-notifications.update');
        Route::delete('/mailboxes/{mailbox}/push-notifications', [PushNotificationSettingsController::class, 'destroy'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'pushNotificationSettingsDeletion', 'target' => 'pushNotificationSettings'])
            ->name('push-notifications.destroy');
        // A read (FR-004): read-only keys may validate.
        Route::post('/sieve-filters/validation', [SieveFilterController::class, 'validateScript'])
            ->defaults('restv2.readOnly', true)
            ->name('sieve-filter-validation.store');
        Route::post('/mailboxes/{mailbox}/sieve-filters', [SieveFilterController::class, 'store'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'sieveFilterCreation', 'target' => 'sieveFilter'])
            ->name('mailbox-sieve-filters.store');
        Route::patch('/mailboxes/{mailbox}/sieve-filters/{sieveFilterId}', [SieveFilterController::class, 'update'])
            ->where(['mailbox' => '[^/]+', 'sieveFilterId' => '[1-9][0-9]{0,9}'])
            ->defaults('restv2.audit', ['event' => 'sieveFilterUpdate', 'target' => 'sieveFilter'])
            ->name('mailbox-sieve-filters.update');
        Route::delete('/mailboxes/{mailbox}/sieve-filters/{sieveFilterId}', [SieveFilterController::class, 'destroy'])
            ->where(['mailbox' => '[^/]+', 'sieveFilterId' => '[1-9][0-9]{0,9}'])
            ->defaults('restv2.audit', ['event' => 'sieveFilterDeletion', 'target' => 'sieveFilter'])
            ->name('mailbox-sieve-filters.destroy');
        Route::delete('/mailboxes/{mailbox}/activesync-cache', [GroupwareResetController::class, 'activeSyncCache'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'activeSyncCacheReset', 'target' => 'groupwareData'])
            ->name('activesync-cache.destroy');
        Route::delete('/mailboxes/{mailbox}/groupware-profile', [GroupwareResetController::class, 'profile'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'groupwareProfileReset', 'target' => 'groupwareData'])
            ->name('groupware-profile.destroy');
        Route::delete('/mailboxes/{mailbox}/recent-logins', [RecentLoginController::class, 'destroy'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'recentLoginsClear', 'target' => 'recentLogins'])
            ->name('recent-logins.destroy');
        Route::post('/mailboxes/{mailbox}/push-notifications/verification', [PushNotificationSettingsController::class, 'verify'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'pushNotificationVerification', 'target' => 'pushNotificationSettings'])
            ->name('push-notification-verification.store');
        Route::post('/mailboxes/{mailbox}/app-passwords', [AppPasswordController::class, 'store'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'appPasswordCreation', 'target' => 'appPassword'])
            ->name('app-passwords.store');
        Route::patch('/mailboxes/{mailbox}/app-passwords/{appPasswordId}', [AppPasswordController::class, 'update'])
            ->where(['mailbox' => '[^/]+', 'appPasswordId' => '[1-9][0-9]{0,9}'])
            ->defaults('restv2.audit', ['event' => 'appPasswordUpdate', 'target' => 'appPassword'])
            ->name('app-passwords.update');
        Route::delete('/mailboxes/{mailbox}/app-passwords/{appPasswordId}', [AppPasswordController::class, 'destroy'])
            ->where(['mailbox' => '[^/]+', 'appPasswordId' => '[1-9][0-9]{0,9}'])
            ->defaults('restv2.audit', ['event' => 'appPasswordDeletion', 'target' => 'appPassword'])
            ->name('app-passwords.destroy');
        Route::post('/mailboxes/{mailbox}/temporary-aliases', [TemporaryAliasController::class, 'store'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'temporaryAliasCreation', 'target' => 'temporaryAlias'])
            ->name('temporary-aliases.store');
        Route::patch('/mailboxes/{mailbox}/temporary-aliases/{temporaryAlias}', [TemporaryAliasController::class, 'update'])
            ->where(['mailbox' => '[^/]+', 'temporaryAlias' => '[^/]+'])
            ->defaults('restv2.audit', ['event' => 'temporaryAliasUpdate', 'target' => 'temporaryAlias'])
            ->name('temporary-aliases.update');
        Route::delete('/mailboxes/{mailbox}/temporary-aliases/{temporaryAlias}', [TemporaryAliasController::class, 'destroy'])
            ->where(['mailbox' => '[^/]+', 'temporaryAlias' => '[^/]+'])
            ->defaults('restv2.audit', ['event' => 'temporaryAliasDeletion', 'target' => 'temporaryAlias'])
            ->name('temporary-aliases.destroy');
    });

    // Feature 007: administrators. Reads with a session or an administrator's key; writes only in a
    // signed-in administrator session (keys get `session-required`). The POST-only bulk path comes
    // before {username}, whose routes never accept POST.
    Route::middleware(['restv2.auth:authenticated', 'restv2.pending'])->group(function (): void {
        Route::get('/admins', [AdminController::class, 'index'])->name('admins.index');
        Route::get('/admins/{username}', [AdminController::class, 'show'])
            ->where('username', '[A-Za-z0-9._-]+')
            ->name('admins.show');
    });

    Route::middleware(['restv2.auth:session', 'restv2.csrf', 'restv2.pending'])->group(function (): void {
        Route::post('/admins/bulk-actions', [AccountBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'admin'])
            ->name('admin-bulk-actions.store');
        Route::post('/admins', [AdminController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'adminCreation', 'target' => 'admin'])
            ->name('admins.store');
        Route::patch('/admins/{username}', [AdminController::class, 'update'])
            ->where('username', '[A-Za-z0-9._-]+')
            ->defaults('restv2.audit', ['event' => 'adminUpdate', 'target' => 'admin'])
            ->name('admins.update');
        Route::delete('/admins/{username}', [AdminController::class, 'destroy'])
            ->where('username', '[A-Za-z0-9._-]+')
            ->defaults('restv2.audit', ['event' => 'adminDeletion', 'target' => 'admin'])
            ->name('admins.destroy');
        Route::post('/admins/{username}/second-factor-suspension', [AdminSecondFactorSuspensionController::class, 'store'])
            ->where('username', '[A-Za-z0-9._-]+')
            ->defaults('restv2.audit', ['event' => 'adminSecondFactorSuspension', 'target' => 'admin'])
            ->name('admin-second-factor-suspension.store');
    });

    // Feature 007: domain administrators. Administrators only, with a session or a key (read-write
    // for changes). The POST-only bulk path comes before {username}, whose routes never accept POST,
    // so that an account named "bulk-actions" stays readable.
    Route::middleware(['restv2.auth:authenticated', 'restv2.pending'])->group(function (): void {
        Route::get('/capabilities/accounts', [AccountCapabilitiesController::class, 'show'])->name('account-capabilities.show');
        Route::get('/domain-admins', [DomainAdminController::class, 'index'])->name('domain-admins.index');
        Route::get('/domain-admins/{username}', [DomainAdminController::class, 'show'])
            ->where('username', '[A-Za-z0-9._-]+')
            ->name('domain-admins.show');
    });

    Route::middleware(['restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])->group(function (): void {
        Route::post('/domain-admins/{username}/sso-token', [DomainAdminSsoTokenController::class, 'store'])
            ->where('username', '[A-Za-z0-9._-]+')
            ->defaults('restv2.audit', ['event' => 'ssoTokenIssue', 'target' => 'ssoToken'])
            ->name('domain-admin-sso-token.store');
        Route::post('/domain-admins/bulk-actions', [AccountBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'domainAdmin'])
            ->name('domain-admin-bulk-actions.store');
        Route::post('/domain-admins', [DomainAdminController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'domainAdminCreation', 'target' => 'domainAdmin'])
            ->name('domain-admins.store');
        Route::patch('/domain-admins/{username}', [DomainAdminController::class, 'update'])
            ->where('username', '[A-Za-z0-9._-]+')
            ->defaults('restv2.audit', ['event' => 'domainAdminUpdate', 'target' => 'domainAdmin'])
            ->name('domain-admins.update');
        Route::delete('/domain-admins/{username}', [DomainAdminController::class, 'destroy'])
            ->where('username', '[A-Za-z0-9._-]+')
            ->defaults('restv2.audit', ['event' => 'domainAdminDeletion', 'target' => 'domainAdmin'])
            ->name('domain-admins.destroy');
        Route::post('/domain-admins/{username}/second-factor-suspension', [DomainAdminSecondFactorSuspensionController::class, 'store'])
            ->where('username', '[A-Za-z0-9._-]+')
            ->defaults('restv2.audit', ['event' => 'domainAdminSecondFactorSuspension', 'target' => 'domainAdmin'])
            ->name('domain-admin-second-factor-suspension.store');
    });

    // Feature 007: webmail on a user's behalf. Full browser sessions only, never while impersonating.
    Route::post('/mailboxes/{mailbox}/webmail-sign-in', [MailboxWebmailSignInController::class, 'store'])
        ->middleware(['restv2.auth:session', 'restv2.csrf', 'restv2.not-impersonating', 'restv2.pending'])
        ->where('mailbox', '[^/]+')
        ->defaults('restv2.audit', ['event' => 'webmailSignInOnBehalf', 'target' => 'webmailOnBehalf'])
        ->name('mailbox-webmail-sign-in.store');

    // Feature 006: quarantine, spam policy and rate limits. Literal paths (settings, bulk actions,
    // capabilities) come before the parameterised ones; item IDs are digits only. State-changing
    // routes declare `restv2.audit` (research R19).
    Route::middleware(['restv2.auth:authenticated', 'restv2.pending'])->group(function (): void {
        Route::get('/quarantine', [QuarantineItemController::class, 'index'])->name('quarantine.index');
        Route::get('/capabilities/quarantine', [QuarantineCapabilitiesController::class, 'show'])->name('quarantine-capabilities.show');
        Route::get('/quarantine/{itemId}', [QuarantineItemController::class, 'show'])
            ->where('itemId', '[1-9][0-9]{0,9}')
            ->name('quarantine.show');
        Route::get('/quarantine/{itemId}/message', [QuarantineItemDownloadController::class, 'message'])
            ->where('itemId', '[1-9][0-9]{0,9}')
            ->name('quarantine-message.show');
        Route::get('/quarantine/{itemId}/attachments/{position}', [QuarantineItemDownloadController::class, 'attachment'])
            ->where(['itemId' => '[1-9][0-9]{0,9}', 'position' => '[0-9]{1,4}'])
            ->name('quarantine-attachments.show');
    });

    Route::middleware(['restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])->group(function (): void {
        // POST-only bulk path: object paths never accept POST.
        Route::post('/quarantine/bulk-actions', [QuarantineBulkActionController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'quarantineItem'])
            ->name('quarantine-bulk-actions.store');
        Route::delete('/quarantine/{itemId}', [QuarantineItemController::class, 'destroy'])
            ->where('itemId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'quarantineItemDeletion', 'target' => 'quarantineItem'])
            ->name('quarantine.destroy');
        Route::post('/quarantine/{itemId}/release', [QuarantineItemReleaseController::class, 'store'])
            ->where('itemId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'quarantineItemRelease', 'target' => 'quarantineItem'])
            ->name('quarantine-release.store');
        Route::post('/quarantine/{itemId}/spam-learning', [QuarantineItemSpamLearningController::class, 'store'])
            ->where('itemId', '[1-9][0-9]{0,9}')
            ->defaults('restv2.audit', ['event' => 'quarantineItemSpamLearning', 'target' => 'quarantineItem'])
            ->name('quarantine-spam-learning.store');
    });

    Route::get('/audit-events', [AuditEventController::class, 'index'])
        ->middleware(['restv2.auth:authenticated', 'restv2.pending'])
        ->name('audit-events.index');

    // System (constitution runtime, R24): no authentication, no session; the `system.*` routes
    // ignore X-API-Key (AuthenticateApiKey).
    Route::middleware(['restv2.auth:public', 'restv2.pending:allow'])->group(function (): void {
        Route::get('/health/live', [LivenessController::class, 'show'])->name('system.liveness');
        Route::get('/health/ready', [ReadinessController::class, 'show'])->name('system.readiness');
        Route::get('/openapi.yaml', [OpenApiDocumentController::class, 'show'])->name('system.openapi');
        Route::get('/docs', [ApiDocsController::class, 'show'])->name('system.docs');
        Route::get('/', [ApiDocsController::class, 'root'])->name('system.root');
        Route::get('/problems/{problemType}', [ProblemTypeController::class, 'show'])
            ->where('problemType', '[a-z0-9-]+')
            ->name('system.problem');
    });
});
