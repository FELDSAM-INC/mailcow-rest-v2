<?php

use App\Http\Controllers\Api\V2\AliasController;
use App\Http\Controllers\Api\V2\AliasesCapabilitiesController;
use App\Http\Controllers\Api\V2\ApiDocsController;
use App\Http\Controllers\Api\V2\ApiKeyController;
use App\Http\Controllers\Api\V2\ApiKeyOwnerCandidateController;
use App\Http\Controllers\Api\V2\ApiKeyRevocationController;
use App\Http\Controllers\Api\V2\AuditEventController;
use App\Http\Controllers\Api\V2\CsrfTokenController;
use App\Http\Controllers\Api\V2\DomainBulkActionController;
use App\Http\Controllers\Api\V2\DomainCapabilitiesController;
use App\Http\Controllers\Api\V2\DomainController;
use App\Http\Controllers\Api\V2\DomainDeletionPreviewController;
use App\Http\Controllers\Api\V2\DomainFooterController;
use App\Http\Controllers\Api\V2\DomainTagController;
use App\Http\Controllers\Api\V2\DomainTemplateController;
use App\Http\Controllers\Api\V2\IdentityController;
use App\Http\Controllers\Api\V2\LivenessController;
use App\Http\Controllers\Api\V2\MailboxCapabilitiesController;
use App\Http\Controllers\Api\V2\MailboxController;
use App\Http\Controllers\Api\V2\MailboxTagController;
use App\Http\Controllers\Api\V2\OpenApiDocumentController;
use App\Http\Controllers\Api\V2\PasskeyAssertionController;
use App\Http\Controllers\Api\V2\PasskeyChallengeController;
use App\Http\Controllers\Api\V2\PasskeyController;
use App\Http\Controllers\Api\V2\PasskeyRegistrationOptionsController;
use App\Http\Controllers\Api\V2\PasswordPolicyController;
use App\Http\Controllers\Api\V2\PendingSignInController;
use App\Http\Controllers\Api\V2\PreferencesController;
use App\Http\Controllers\Api\V2\ProblemTypeController;
use App\Http\Controllers\Api\V2\PublicConfigController;
use App\Http\Controllers\Api\V2\PublicConfigLogoController;
use App\Http\Controllers\Api\V2\ReadinessController;
use App\Http\Controllers\Api\V2\SecondFactorChallengeController;
use App\Http\Controllers\Api\V2\SecondFactorController;
use App\Http\Controllers\Api\V2\SessionController;
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
        Route::get('/password-policy', [PasswordPolicyController::class, 'show'])->name('password-policy.show');
        Route::get('/mailboxes/{mailbox}', [MailboxController::class, 'show'])
            ->where('mailbox', '[^/]+')
            ->name('mailboxes.show');
    });

    // State-changing mailbox routes: refusals of authenticated callers are audited (`restv2.audit`).
    Route::middleware(['restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])->group(function (): void {
        Route::post('/mailboxes', [MailboxController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'mailboxCreation', 'target' => 'mailbox'])
            ->name('mailboxes.store');
        Route::patch('/mailboxes/{mailbox}', [MailboxController::class, 'update'])
            ->where('mailbox', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'mailboxUpdate', 'target' => 'mailbox'])
            ->name('mailboxes.update');
    });

    // Feature 004: aliases, alias domains, resources and sender settings. Literal paths
    // (bulk-actions, capabilities) come before the parameterised ones; `{aliasId}` is digits only.
    Route::middleware(['restv2.auth:authenticated', 'restv2.pending'])->group(function (): void {
        Route::get('/aliases', [AliasController::class, 'index'])->name('aliases.index');
        Route::get('/capabilities/aliases', [AliasesCapabilitiesController::class, 'show'])->name('aliases-capabilities.show');
        Route::get('/aliases/{aliasId}', [AliasController::class, 'show'])
            ->where('aliasId', '[1-9][0-9]{0,9}')
            ->name('aliases.show');
    });

    // State-changing routes of feature 004: refusals of authenticated callers are audited.
    Route::middleware(['restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])->group(function (): void {
        Route::post('/aliases', [AliasController::class, 'store'])
            ->defaults('restv2.audit', ['event' => 'aliasCreation', 'target' => 'alias'])
            ->name('aliases.store');
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
