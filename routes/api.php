<?php

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
use App\Http\Controllers\Api\V2\AuditEventController;
use App\Http\Controllers\Api\V2\BccMapController;
use App\Http\Controllers\Api\V2\ContainerController;
use App\Http\Controllers\Api\V2\ContainerResourceUsageController;
use App\Http\Controllers\Api\V2\ContainerRestartController;
use App\Http\Controllers\Api\V2\ContainerStartController;
use App\Http\Controllers\Api\V2\ContainerStopController;
use App\Http\Controllers\Api\V2\CsrfTokenController;
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
use App\Http\Controllers\Api\V2\HostOverviewController;
use App\Http\Controllers\Api\V2\IdentityController;
use App\Http\Controllers\Api\V2\IdentityProviderCapabilitiesController;
use App\Http\Controllers\Api\V2\IdentityProviderConnectionTestController;
use App\Http\Controllers\Api\V2\IdentityProviderController;
use App\Http\Controllers\Api\V2\InstallationController;
use App\Http\Controllers\Api\V2\LivenessController;
use App\Http\Controllers\Api\V2\LogEntryController;
use App\Http\Controllers\Api\V2\MailboxBulkActionController;
use App\Http\Controllers\Api\V2\MailboxCapabilitiesController;
use App\Http\Controllers\Api\V2\MailboxController;
use App\Http\Controllers\Api\V2\MailboxDeletionPreviewController;
use App\Http\Controllers\Api\V2\MailboxRenameController;
use App\Http\Controllers\Api\V2\MailboxSenderSettingsController;
use App\Http\Controllers\Api\V2\MailboxTagController;
use App\Http\Controllers\Api\V2\MailboxTemplateBulkActionController;
use App\Http\Controllers\Api\V2\MailboxTemplateController;
use App\Http\Controllers\Api\V2\MailQueueBulkActionController;
use App\Http\Controllers\Api\V2\MailQueueFlushController;
use App\Http\Controllers\Api\V2\MailQueueMessageContentController;
use App\Http\Controllers\Api\V2\MailQueueMessageController;
use App\Http\Controllers\Api\V2\MailQueuePurgeController;
use App\Http\Controllers\Api\V2\MailStorageUsageController;
use App\Http\Controllers\Api\V2\MonitoringCapabilitiesController;
use App\Http\Controllers\Api\V2\Oauth2ClientBulkActionController;
use App\Http\Controllers\Api\V2\Oauth2ClientCapabilitiesController;
use App\Http\Controllers\Api\V2\Oauth2ClientController;
use App\Http\Controllers\Api\V2\Oauth2ClientSecretRegenerationController;
use App\Http\Controllers\Api\V2\Oauth2ClientTokenRevocationController;
use App\Http\Controllers\Api\V2\OpenApiDocumentController;
use App\Http\Controllers\Api\V2\PasskeyAssertionController;
use App\Http\Controllers\Api\V2\PasskeyChallengeController;
use App\Http\Controllers\Api\V2\PasskeyController;
use App\Http\Controllers\Api\V2\PasskeyRegistrationOptionsController;
use App\Http\Controllers\Api\V2\PasswordPolicyController;
use App\Http\Controllers\Api\V2\PendingSignInController;
use App\Http\Controllers\Api\V2\PreferencesController;
use App\Http\Controllers\Api\V2\ProblemTypeController;
use App\Http\Controllers\Api\V2\PublicAddressesController;
use App\Http\Controllers\Api\V2\PublicConfigController;
use App\Http\Controllers\Api\V2\PublicConfigLogoController;
use App\Http\Controllers\Api\V2\ReadinessController;
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
use App\Http\Controllers\Api\V2\SenderPermissionCandidateController;
use App\Http\Controllers\Api\V2\SessionController;
use App\Http\Controllers\Api\V2\SingleSignOnCompletionController;
use App\Http\Controllers\Api\V2\SingleSignOnController;
use App\Http\Controllers\Api\V2\SingleSignOnProviderErrorController;
use App\Http\Controllers\Api\V2\SyncJobBulkActionController;
use App\Http\Controllers\Api\V2\SyncJobCapabilitiesController;
use App\Http\Controllers\Api\V2\SyncJobController;
use App\Http\Controllers\Api\V2\SyncJobLogController;
use App\Http\Controllers\Api\V2\SyncJobScheduleResetController;
use App\Http\Controllers\Api\V2\TlsPolicyMapController;
use App\Http\Controllers\Api\V2\TransportController;
use App\Http\Controllers\Api\V2\UpdateStatusController;
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
    });

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
            ->middleware(['restv2.csrf', 'restv2.pending:secondFactorSetup'])
            ->name('passkey-registration-options.store');
        Route::get('/me/passkeys', [PasskeyController::class, 'index'])
            ->middleware('restv2.pending')
            ->name('passkeys.index');
        Route::post('/me/passkeys', [PasskeyController::class, 'store'])
            ->middleware(['restv2.csrf', 'restv2.pending:secondFactorSetup'])
            ->name('passkeys.store');
        Route::get('/me/passkeys/{passkeyId}', [PasskeyController::class, 'show'])
            ->middleware('restv2.pending')
            ->where('passkeyId', '[0-9a-f]{64}')
            ->name('passkeys.show');
        Route::patch('/me/passkeys/{passkeyId}', [PasskeyController::class, 'update'])
            ->middleware(['restv2.csrf', 'restv2.pending'])
            ->where('passkeyId', '[0-9a-f]{64}')
            ->name('passkeys.update');
        Route::delete('/me/passkeys/{passkeyId}', [PasskeyController::class, 'destroy'])
            ->middleware(['restv2.csrf', 'restv2.pending'])
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
