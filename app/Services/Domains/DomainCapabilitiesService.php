<?php

namespace App\Services\Domains;

use App\Auth\Principal;
use App\Auth\Role;
use App\Rules\WholeMebibytes;
use App\Services\Config\MailcowConfig;
use App\Services\Dkim\IspconfigSettings;
use App\Support\Http\ListQuery;

/**
 * The domain capabilities (FR-064, research D23), computed from the current configuration on
 * every request. The DKIM values are for administrators only. No secret or connection detail is
 * ever included.
 */
class DomainCapabilitiesService
{
    public const MAX_DESCRIPTION_LENGTH = 255;

    public const FOOTER_TEXT_MAX_BYTES = 1048576;

    public const BULK_LIMIT = 100;

    public function __construct(private readonly MailcowConfig $config) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Principal $principal): array
    {
        $capabilities = [
            'groupwareEnabled' => ! config('restv2.skip_sogo'),
            'taggingLimit' => $this->config->taggingLimit(),
            'maxTagLength' => DomainTagSet::MAX_LENGTH,
            'maxDescriptionLength' => self::MAX_DESCRIPTION_LENGTH,
            'footerTextMaxBytes' => self::FOOTER_TEXT_MAX_BYTES,
            'bulkLimit' => self::BULK_LIMIT,
            'defaultPageSize' => ListQuery::DEFAULT_LIMIT,
            'maxPageSize' => ListQuery::MAX_LIMIT,
            'quotaUnit' => WholeMebibytes::MIB,
        ];

        if ($principal->role === Role::Admin) {
            $capabilities['dkim'] = [
                'defaultSelector' => (string) config('restv2.dkim.default_selector'),
                'defaultKeySize' => (int) config('restv2.dkim.default_key_size', 2048),
                'allowedKeySizes' => array_values(array_map('intval', (array) config('restv2.dkim.allowed_key_sizes'))),
                'dnsPublicationEnabled' => IspconfigSettings::fromConfig()->enabled(),
            ];
        }

        return $capabilities;
    }
}
