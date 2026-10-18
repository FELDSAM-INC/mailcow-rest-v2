<?php

namespace App\Auth;

/**
 * How the caller authenticated (contract `AuthMethod`).
 */
enum AuthMethod: string
{
    case Password = 'password';
    case PasswordAndSecondFactor = 'passwordAndSecondFactor';
    case Passkey = 'passkey';
    case ApiKey = 'apiKey';

    // Feature 012: sign-ins through the external identity provider.
    case SingleSignOn = 'singleSignOn';
    case DirectoryPassword = 'directoryPassword';
    case DirectoryPasswordAndSecondFactor = 'directoryPasswordAndSecondFactor';
    case ProviderPassword = 'providerPassword';
    case ProviderPasswordAndSecondFactor = 'providerPasswordAndSecondFactor';

    /**
     * The method with a second factor added: a password sign-in of the given method that completes
     * its second-factor step (feature 012 for directory and provider passwords).
     */
    public function withSecondFactor(): self
    {
        return match ($this) {
            self::Password => self::PasswordAndSecondFactor,
            self::DirectoryPassword => self::DirectoryPasswordAndSecondFactor,
            self::ProviderPassword => self::ProviderPasswordAndSecondFactor,
            default => $this,
        };
    }
}
