<?php

namespace App\Http\Resources\Api\V2;

use App\Auth\IdentityProviderType;
use App\Services\IdentityProvider\AppReturnUrls;
use App\Services\IdentityProvider\IdentityProviderConfig;
use App\Services\IdentityProvider\IdentityProviderRepository;
use App\Services\IdentityProvider\IdentityProviderUsage;
use App\Services\IdentityProvider\SynchronisationStatusReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract `IdentityProvider` (FR-006): the stored configuration of one type with the legacy
 * defaults, whether each secret is set (never the secret), whether each mapped template exists,
 * completeness, the new web app's return addresses (Keycloak and generic OIDC), the mailbox counts
 * per external source, the synchronisation status (Keycloak and LDAP), the last change and the
 * version tag. Built from mailcow's current data on every call.
 *
 * @property array<string, mixed> $resource
 */
class IdentityProviderResource extends JsonResource
{
    public static function fromStored(IdentityProviderConfig $config): self
    {
        $repository = app(IdentityProviderRepository::class);
        $rows = $repository->rows();
        $s = $config->settings;

        $mapping = (array) ($s['attributeMapping'] ?? []);
        $names = array_column($mapping, 'template');
        $default = $s['defaultTemplate'] ?? null;

        if (is_string($default)) {
            $names[] = $default;
        }

        $exists = $repository->templatesExist(array_map('strval', $names));
        $modified = $repository->modifiedAt();

        $data = [
            'type' => $config->type->value,
            'attributeMapping' => array_map(fn (array $entry): array => $entry + ['templateExists' => $exists[$entry['template']] ?? false], $mapping),
            'defaultTemplate' => $default,
            'defaultTemplateExists' => is_string($default) ? ($exists[$default] ?? false) : null,
            'createMailboxOnSignIn' => (bool) $s['createMailboxOnSignIn'],
            'ignoreTlsErrors' => (bool) $s['ignoreTlsErrors'],
            'complete' => $config->isComplete(),
            'missingSettings' => $config->missingSettings(),
            'mailboxCounts' => app(IdentityProviderUsage::class)->counts(),
            'modifiedAt' => $modified?->format('Y-m-d\TH:i:s\Z'),
            'etag' => $repository->etag($rows),
        ];

        if ($config->type !== IdentityProviderType::Ldap) {
            $data += [
                'legacyReturnUrl' => $s['legacyReturnUrl'] ?? null,
                'additionalReturnUrls' => array_values((array) ($s['additionalReturnUrls'] ?? [])),
                'appReturnUrls' => app(AppReturnUrls::class)->all(),
                'clientId' => $s['clientId'] ?? null,
                'clientSecretSet' => $config->secret() !== null,
            ];
        }

        $data += match ($config->type) {
            IdentityProviderType::Keycloak => [
                'serverUrl' => $s['serverUrl'] ?? null,
                'realm' => $s['realm'] ?? null,
                'version' => $s['version'] ?? null,
                'mailpasswordFlow' => (bool) $s['mailpasswordFlow'],
            ],
            IdentityProviderType::GenericOidc => [
                'authorizationEndpoint' => $s['authorizationEndpoint'] ?? null,
                'tokenEndpoint' => $s['tokenEndpoint'] ?? null,
                'userInfoEndpoint' => $s['userInfoEndpoint'] ?? null,
                'scopes' => (string) $s['scopes'],
            ],
            IdentityProviderType::Ldap => [
                'hosts' => array_values((array) $s['hosts']),
                'port' => (int) $s['port'],
                'effectivePort' => $config->effectivePort(),
                'useSsl' => (bool) $s['useSsl'],
                'useStartTls' => (bool) $s['useStartTls'],
                'baseDn' => $s['baseDn'] ?? null,
                'usernameField' => (string) $s['usernameField'],
                'filter' => $s['filter'] ?? null,
                'attributeField' => $s['attributeField'] ?? null,
                'bindDn' => $s['bindDn'] ?? null,
                'bindPasswordSet' => $config->secret() !== null,
            ],
        };

        if ($config->type->offersSynchronisation()) {
            $data += [
                'importUsers' => (bool) $s['importUsers'],
                'periodicSync' => (bool) $s['periodicSync'],
                'syncInterval' => (int) $s['syncInterval'],
                'synchronisation' => app(SynchronisationStatusReader::class)->read($config, CarbonImmutable::now('UTC'))?->toArray(),
            ];
        }

        return new self($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
