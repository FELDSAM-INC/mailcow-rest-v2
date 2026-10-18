<?php

namespace App\Http\Resources\Api\V2;

use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Config\MailcowConfig;
use App\Services\Dkim\IspconfigSettings;
use App\Support\Http\ListQuery;

/**
 * The contract's `DnsCapabilities` (feature 010 FR-012): the server host name and the DKIM, MTA-STS
 * and DNS diagnostics defaults and limits, read from the current configuration. Administrators also
 * learn whether private key export is enabled; no connection detail or secret is ever included.
 */
final class DnsCapabilitiesResource
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Principal $principal, MailcowConfig $config): array
    {
        $admin = $principal->role === Role::Admin;
        [$importMin, $importMax] = (array) config('restv2.dkim.import_bits');

        return [
            'serverHostname' => strtolower((string) config('restv2.mailcow_hostname')),
            'defaultPageSize' => ListQuery::DEFAULT_LIMIT,
            'maxPageSize' => ListQuery::MAX_LIMIT,
            'dkim' => [
                'defaultSelector' => (string) config('restv2.dkim.default_selector'),
                'defaultKeySize' => (int) config('restv2.dkim.default_key_size'),
                'allowedKeySizes' => array_map('intval', (array) config('restv2.dkim.allowed_key_sizes')),
                'selectorMaxLength' => 255,
                'privateKeyMaxBytes' => (int) config('restv2.dkim.private_key_max_bytes'),
                'importKeyLengthMin' => (int) $importMin,
                'importKeyLengthMax' => (int) $importMax,
                'bulkLimit' => (int) config('restv2.dkim.bulk_limit'),
                'dnsPublicationEnabled' => IspconfigSettings::fromConfig()->enabled(),
                'privateKeyExportEnabled' => $admin ? $config->showDkimPrivateKeys() : null,
            ],
            'mtaSts' => [
                'version' => 'STSv1',
                'modes' => ['enforce', 'testing', 'none'],
                'defaultActive' => false,
                'maxAgeMin' => 1,
                'maxAgeMax' => (int) config('restv2.mta_sts.max_age_max'),
                'mxMaxLength' => (int) config('restv2.mta_sts.mx_max_length'),
            ],
            'dnsDiagnostics' => [
                'runsPerMinute' => (int) config('restv2.dns.runs_per_minute'),
                'lookupTimeoutSeconds' => (int) config('restv2.dns.lookup_timeout'),
                'runTimeoutSeconds' => (int) config('restv2.dns.run_timeout'),
                'serverChecks' => $admin,
            ],
        ];
    }
}
