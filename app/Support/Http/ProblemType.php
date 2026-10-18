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

    // Feature 002 (attribute-not-permitted, temporary-failure and permission-flag-required are
    // shared by every later feature).
    case DomainExists = 'domain-exists';
    case DomainNotEmpty = 'domain-not-empty';
    case DomainTemplateNameExists = 'domain-template-name-exists';
    case DomainTemplateProtected = 'domain-template-protected';
    case AttributeNotPermitted = 'attribute-not-permitted';
    case TemporaryFailure = 'temporary-failure';
    case PermissionFlagRequired = 'permission-flag-required';

    // Feature 010 (DKIM core delivered with feature 002).
    case DkimKeyExists = 'dkim-key-exists';
    case DkimPrivateKeyExportDisabled = 'dkim-private-key-export-disabled';
    case DkimDnsPublicationDisabled = 'dkim-dns-publication-disabled';
    case MtaStsPolicyExists = 'mta-sts-policy-exists';

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
            self::TemporaryVerificationFailure, self::ServiceUnavailable, self::TemporaryFailure => 503,
            self::DomainExists, self::DomainNotEmpty, self::DomainTemplateNameExists, self::DomainTemplateProtected,
            self::DkimKeyExists, self::MtaStsPolicyExists => 409,
            self::AttributeNotPermitted, self::PermissionFlagRequired, self::DkimPrivateKeyExportDisabled,
            self::DkimDnsPublicationDisabled => 403,
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
            self::DomainExists => 'Domain exists',
            self::DomainNotEmpty => 'Domain not empty',
            self::DomainTemplateNameExists => 'Domain template name exists',
            self::DomainTemplateProtected => 'Domain template protected',
            self::AttributeNotPermitted => 'Attribute not permitted',
            self::TemporaryFailure => 'Temporary failure',
            self::PermissionFlagRequired => 'Permission flag required',
            self::DkimKeyExists => 'DKIM key exists',
            self::DkimPrivateKeyExportDisabled => 'DKIM private key export disabled',
            self::DkimDnsPublicationDisabled => 'DKIM DNS publication disabled',
            self::MtaStsPolicyExists => 'MTA-STS policy exists',
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
            self::DomainExists => 'The name already exists as a domain or alias domain.',
            self::DomainNotEmpty => 'The domain still has mailboxes or resources.',
            self::DomainTemplateNameExists => 'Another domain template already has this name.',
            self::DomainTemplateProtected => 'The Default domain template cannot be renamed or deleted.',
            self::AttributeNotPermitted => 'Your role or permission flags do not allow changing these attributes.',
            self::TemporaryFailure => 'A service that the change needs is temporarily unavailable. Nothing was changed; retry later.',
            self::PermissionFlagRequired => 'The operation needs a permission flag that this account does not hold.',
            self::DkimKeyExists => 'The name already has a DKIM key.',
            self::DkimPrivateKeyExportDisabled => 'The export of DKIM private keys is disabled on this server.',
            self::DkimDnsPublicationDisabled => 'The DKIM DNS publication is disabled on this server.',
            self::MtaStsPolicyExists => 'The domain already has an MTA-STS policy.',
        };
    }

    public static function fromUri(string $uri): ?self
    {
        return str_starts_with($uri, '/api/v2/problems/') ? self::tryFrom(substr($uri, 17)) : null;
    }
}
