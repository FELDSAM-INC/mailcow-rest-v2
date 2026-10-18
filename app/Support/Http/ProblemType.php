<?php

namespace App\Support\Http;

/**
 * The documented problem types (docs/problems.md, contract `KnownProblemType`). Each case is one
 * `## <name>` section; its URI is `/api/v2/problems/<name>`. Later features append their cases.
 */
enum ProblemType: string
{
    case ValidationFailed = 'validation-failed';
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case CsrfTokenMismatch = 'csrf-token-mismatch';
    case SessionRequired = 'session-required';
    case PendingActionRequired = 'pending-action-required';
    case TooManyRequests = 'too-many-requests';
    case SignInFailed = 'sign-in-failed';
    case SignInExpired = 'sign-in-expired';
    case SecondFactorRequired = 'second-factor-required';
    case SecondFactorFailed = 'second-factor-failed';
    case SecondFactorUnavailable = 'second-factor-unavailable';
    case TemporaryVerificationFailure = 'temporary-verification-failure';
    case PasskeyRegistrationFailed = 'passkey-registration-failed';
    case ApiKeyInvalid = 'api-key-invalid';
    case ApiKeyExpired = 'api-key-expired';
    case ApiKeyRevoked = 'api-key-revoked';
    case ApiKeySourceNotAllowed = 'api-key-source-not-allowed';
    case InsufficientKeyScope = 'insufficient-key-scope';
    case ServiceUnavailable = 'service-unavailable';

    public function uri(): string
    {
        return '/api/v2/problems/'.$this->value;
    }

    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed => 422,
            self::Unauthenticated, self::SignInFailed, self::SignInExpired, self::SecondFactorRequired,
            self::SecondFactorFailed, self::ApiKeyInvalid, self::ApiKeyExpired, self::ApiKeyRevoked => 401,
            self::Forbidden, self::CsrfTokenMismatch, self::SessionRequired, self::PendingActionRequired,
            self::SecondFactorUnavailable, self::ApiKeySourceNotAllowed, self::InsufficientKeyScope => 403,
            self::TooManyRequests => 429,
            self::PasskeyRegistrationFailed => 400,
            self::TemporaryVerificationFailure, self::ServiceUnavailable => 503,
        };
    }

    /**
     * Short English summary of the type (never localised, FR-055).
     */
    public function title(): string
    {
        return match ($this) {
            self::ValidationFailed => 'Validation failed',
            self::Unauthenticated => 'Authentication required',
            self::Forbidden => 'Forbidden',
            self::CsrfTokenMismatch => 'CSRF token mismatch',
            self::SessionRequired => 'Session required',
            self::PendingActionRequired => 'Pending action required',
            self::TooManyRequests => 'Too many requests',
            self::SignInFailed => 'Sign-in failed',
            self::SignInExpired => 'Sign-in expired',
            self::SecondFactorRequired => 'Second factor required',
            self::SecondFactorFailed => 'Second factor failed',
            self::SecondFactorUnavailable => 'Second factor unavailable',
            self::TemporaryVerificationFailure => 'Temporary verification failure',
            self::PasskeyRegistrationFailed => 'Passkey registration failed',
            self::ApiKeyInvalid => 'API key invalid',
            self::ApiKeyExpired => 'API key expired',
            self::ApiKeyRevoked => 'API key revoked',
            self::ApiKeySourceNotAllowed => 'API key source not allowed',
            self::InsufficientKeyScope => 'Insufficient key scope',
            self::ServiceUnavailable => 'Service unavailable',
        };
    }

    /**
     * Default English detail of an occurrence.
     */
    public function detail(): string
    {
        return match ($this) {
            self::ValidationFailed => 'The request contains invalid fields.',
            self::Unauthenticated => 'Sign in or send a valid API key.',
            self::Forbidden => 'The caller may not perform this operation.',
            self::CsrfTokenMismatch => 'The X-CSRF-Token header is missing or does not match the session.',
            self::SessionRequired => 'This operation is only available to a signed-in browser session.',
            self::PendingActionRequired => 'Complete the pending actions before using this operation.',
            self::TooManyRequests => 'Too many failed attempts. Try again later.',
            self::SignInFailed => 'The sign-in failed.',
            self::SignInExpired => 'The pending sign-in or challenge is missing, used or expired. Start again.',
            self::SecondFactorRequired => 'The sign-in awaits a second factor.',
            self::SecondFactorFailed => 'The second factor could not be verified.',
            self::SecondFactorUnavailable => 'The account has no second factor this API can verify.',
            self::TemporaryVerificationFailure => 'The verification service is temporarily unavailable. Try again later.',
            self::PasskeyRegistrationFailed => 'The authenticator response was rejected.',
            self::ApiKeyInvalid => 'The API key is not valid.',
            self::ApiKeyExpired => 'The API key has expired.',
            self::ApiKeyRevoked => 'The API key was revoked and can no longer be used.',
            self::ApiKeySourceNotAllowed => 'The API key may not be used from this address.',
            self::InsufficientKeyScope => 'The read-only API key may not change state.',
            self::ServiceUnavailable => 'The service is not ready.',
        };
    }

    public static function fromUri(string $uri): ?self
    {
        return str_starts_with($uri, '/api/v2/problems/') ? self::tryFrom(substr($uri, 17)) : null;
    }
}
