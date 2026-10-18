<?php

namespace App\Http\Resources\Api\V2;

use App\Services\MtaSts\MtaStsPolicyService;
use App\Services\MtaSts\MtaStsPolicyText;
use App\Support\Domain\DomainName;
use Illuminate\Http\JsonResponse;
use stdClass;

/**
 * The contract's `MtaStsPolicy` (feature 010 FR-036) for a stored `mta_sts` row: the domain in
 * ASCII and readable form, version `STSv1`, mode, MX hosts, maximum age, active state, policy
 * identifier, the address and text that mailcow's `mta-sts.php` serves, the DNS records to publish,
 * and the version tag.
 */
final class MtaStsPolicyResource
{
    /**
     * @return array<string, mixed>
     */
    public static function present(stdClass $row): array
    {
        $domain = (string) $row->domain;
        $values = MtaStsPolicyService::values($row);

        return [
            'domain' => $domain,
            'displayDomain' => DomainName::toUnicode($domain),
            'version' => 'STSv1',
            'mode' => $values['mode'],
            'mxHosts' => $values['mxHosts'],
            'maxAge' => $values['maxAge'],
            'active' => $values['active'],
            'policyId' => $values['policyId'],
            'policyUrl' => "https://mta-sts.{$domain}/.well-known/mta-sts.txt",
            'policyText' => MtaStsPolicyText::fromRow((string) $row->version, (string) $row->mode, (string) $row->max_age, (string) $row->mx),
            'dnsRecords' => [
                ['name' => "_mta-sts.{$domain}", 'type' => 'TXT', 'value' => "v=STSv1;id={$values['policyId']};"],
                ['name' => "mta-sts.{$domain}", 'type' => 'CNAME', 'value' => strtolower((string) config('restv2.mailcow_hostname'))],
            ],
            'etag' => MtaStsPolicyService::versionTag($row),
        ];
    }

    public static function response(stdClass $row, int $status = 200): JsonResponse
    {
        $response = response()->json(['data' => self::present($row)], $status, ['ETag' => MtaStsPolicyService::versionTag($row)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $status === 201 ? $response->header('Location', '/api/v2/mta-sts-policies/'.rawurlencode((string) $row->domain)) : $response;
    }
}
