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

    /**
     * The `details` keys this event may record (data-model "Events and their content"). Every other
     * key is dropped, so that no secret can reach the audit log.
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
        };
    }
}
