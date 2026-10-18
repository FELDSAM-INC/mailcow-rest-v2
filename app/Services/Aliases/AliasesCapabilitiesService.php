<?php

namespace App\Services\Aliases;

use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Config\MailcowConfig;
use App\Services\Dkim\IspconfigSettings;
use App\Services\Domains\DomainCapabilitiesService;
use App\Services\Identity\IdentityService;
use App\Services\RateLimits\RateLimitStore;
use App\Support\Http\ListQuery;

/**
 * The capabilities of feature 004 (FR-068, research R21), computed from the current configuration
 * on every request: the groupware switch (`SKIP_SOGO`), the external-destination setting, the
 * API-wide bulk limit and page sizes, the field limits, the rate limit frames and DKIM key sizes,
 * the DKIM defaults of the alias domain form (administrators, and domain administrators with
 * `aliasDomains`) and whether the DKIM DNS publication is enabled (administrators only). Never a
 * secret or connection detail.
 */
class AliasesCapabilitiesService
{
    public const RESOURCE_DESCRIPTION_MAX_LENGTH = 255;

    public const BOOKING_LIMIT_MAX = 2147483647;

    public function __construct(
        private readonly MailcowConfig $config,
        private readonly IdentityService $identity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Principal $principal): array
    {
        $admin = $principal->role === Role::Admin;
        $dkimDefaults = $admin || ($this->identity->permissions($principal)[PermissionFlag::AliasDomains->value] ?? false);

        return [
            'groupwareEnabled' => ! config('restv2.skip_sogo'),
            'externalDestinationsDisabled' => $this->config->aliasDisableExternalDomains(),
            'bulkLimit' => DomainCapabilitiesService::BULK_LIMIT,
            'defaultPageSize' => ListQuery::DEFAULT_LIMIT,
            'maxPageSize' => ListQuery::MAX_LIMIT,
            'commentMaxBytes' => AliasRules::COMMENT_MAX_BYTES,
            'destinationsMaxLength' => AliasDestination::MAX_LENGTH,
            'resourceDescriptionMaxLength' => self::RESOURCE_DESCRIPTION_MAX_LENGTH,
            'resourceBookingLimit' => ['min' => 1, 'max' => self::BOOKING_LIMIT_MAX],
            'rateLimitFrames' => array_keys(RateLimitStore::FRAMES),
            'dkimKeySizes' => array_values(array_map('intval', (array) config('restv2.dkim.allowed_key_sizes'))),
            'dkimDefaults' => $dkimDefaults ? [
                'selector' => (string) config('restv2.dkim.default_selector'),
                'keySize' => (int) config('restv2.dkim.default_key_size', 2048),
            ] : null,
            'dkimDnsPublicationEnabled' => $admin ? IspconfigSettings::fromConfig()->enabled() : null,
        ];
    }
}
