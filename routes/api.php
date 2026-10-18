<?php

use App\Http\Controllers\Api\V2\CsrfTokenController;
use App\Http\Controllers\Api\V2\IdentityController;
use App\Http\Controllers\Api\V2\PasskeyAssertionController;
use App\Http\Controllers\Api\V2\PasskeyChallengeController;
use App\Http\Controllers\Api\V2\PasskeyController;
use App\Http\Controllers\Api\V2\PasskeyRegistrationOptionsController;
use App\Http\Controllers\Api\V2\PendingSignInController;
use App\Http\Controllers\Api\V2\PreferencesController;
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
});
