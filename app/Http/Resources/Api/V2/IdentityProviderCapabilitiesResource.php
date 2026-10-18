<?php

namespace App\Http\Resources\Api\V2;

use App\Auth\IdentityProviderType;
use App\Rules\HttpUrl;
use App\Rules\LdapFilter;
use App\Services\IdentityProvider\AppReturnUrls;
use App\Services\IdentityProvider\IdentityProviderConfig;
use App\Services\IdentityProvider\IdentityProviderRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract `IdentityProviderCapabilities` (FR-064, research R20): whether a configuration is stored
 * and its type, the new web app's return addresses (also before the first save), the provider
 * types and what each offers, the FR-007 defaults, the FR-010 to FR-013 limits, the provider
 * time-out and the single sign-on lifetimes. No secret and no connection detail.
 *
 * @property array<string, mixed> $resource
 */
class IdentityProviderCapabilitiesResource extends JsonResource
{
    public static function build(): self
    {
        $stored = app(IdentityProviderRepository::class)->current();
        $defaults = IdentityProviderConfig::DEFAULTS;

        return new self([
            'configured' => $stored !== null,
            'configuredType' => $stored?->type->value,
            'appReturnUrls' => app(AppReturnUrls::class)->all(),
            'types' => array_map(fn (IdentityProviderType $type): array => [
                'type' => $type->value,
                'singleSignOn' => $type->offersSingleSignOn(),
                'passwordSignIn' => $type !== IdentityProviderType::GenericOidc,
                'synchronisation' => $type->offersSynchronisation(),
            ], IdentityProviderType::cases()),
            'defaults' => [
                'createMailboxOnSignIn' => $defaults['createMailboxOnSignIn'],
                'ignoreTlsErrors' => $defaults['ignoreTlsErrors'],
                'scopes' => $defaults['scopes'],
                'port' => $defaults['port'],
                'useSsl' => $defaults['useSsl'],
                'useStartTls' => $defaults['useStartTls'],
                'usernameField' => $defaults['usernameField'],
                'mailpasswordFlow' => $defaults['mailpasswordFlow'],
                'importUsers' => $defaults['importUsers'],
                'periodicSync' => $defaults['periodicSync'],
                'syncInterval' => $defaults['syncInterval'],
            ],
            'limits' => [
                'attributeMappingMaxEntries' => 100,
                'attributeValueMaxLength' => 255,
                'additionalReturnUrlsMaxEntries' => 100,
                'urlMaxLength' => HttpUrl::MAX,
                'ldapHostsMaxEntries' => 10,
                'syncIntervalMinMinutes' => 1,
                'syncIntervalMaxMinutes' => 10080,
                'secretMaxLength' => 1000,
                'scopesMaxEntries' => 50,
                'distinguishedNameMaxLength' => 1000,
                'filterMaxLength' => LdapFilter::MAX,
            ],
            'providerTimeoutSeconds' => (int) config('restv2.identity_provider.timeout'),
            'singleSignOn' => [
                'pendingLifetimeSeconds' => (int) config('restv2.identity_provider.sso_pending_ttl'),
                'renewalIntervalSeconds' => (int) config('restv2.identity_provider.sso_renewal_interval'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
