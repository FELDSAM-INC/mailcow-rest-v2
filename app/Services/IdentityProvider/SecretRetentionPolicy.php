<?php

namespace App\Services\IdentityProvider;

use App\Exceptions\ValidationProblemException;
use App\Http\Requests\Api\V2\ApiFormRequest;

/**
 * Write-only secrets (FR-014, FR-019, research R9): an omitted client secret or bind password is
 * replaced by the stored one only when the stored type is the candidate's and the address that
 * receives the secret (Keycloak server URL, generic OIDC token endpoint, LDAP hosts) is unchanged.
 * Otherwise the secret must be sent (`secretRequired` with `reason` `noStoredSecret`,
 * `typeChanged` or `addressChanged` and the changed `field`). The same rule serves saves, changes
 * and connection tests, so that nobody holding an administrator session or key can send the stored
 * secret to a host of their choice.
 */
class SecretRetentionPolicy
{
    /**
     * The secret to use: the one sent, or the stored one when it may be kept.
     *
     * @throws ValidationProblemException with `secretRequired` on the secret member
     */
    public function resolve(?IdentityProviderConfig $stored, IdentityProviderConfig $candidate, #[\SensitiveParameter] ?string $sent): string
    {
        $violation = $this->violation($stored, $candidate, $sent);

        if ($violation !== null) {
            throw new ValidationProblemException([$candidate->secretMember() => [$violation]]);
        }

        return $sent !== null && $sent !== '' ? $sent : (string) $stored?->secret();
    }

    /**
     * The `secretRequired` violation, or null when the secret was sent or may be kept.
     *
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    public function violation(?IdentityProviderConfig $stored, IdentityProviderConfig $candidate, #[\SensitiveParameter] ?string $sent): ?array
    {
        if ($sent !== null && $sent !== '') {
            return null;
        }

        $params = match (true) {
            $stored === null => ['reason' => 'noStoredSecret'],
            $stored->type !== $candidate->type => ['reason' => 'typeChanged'],
            $stored->secret() === null => ['reason' => 'noStoredSecret'],
            $stored->secretAddress() !== $candidate->secretAddress() => ['reason' => 'addressChanged', 'field' => $candidate->secretAddressMember()],
            default => null,
        };

        return $params === null ? null : ApiFormRequest::make('secretRequired', $params, match ($params['reason']) {
            'typeChanged' => 'The secret of the new provider type must be sent.',
            'addressChanged' => 'The secret must be sent again because '.$params['field'].' changed.',
            default => 'The secret must be sent: none is stored.',
        });
    }
}
