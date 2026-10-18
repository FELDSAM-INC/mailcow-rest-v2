<?php

namespace App\Services\Routing;

use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Requests\Api\V2\RoutingBulkActionRequest;
use App\Support\Http\ListQuery;

/**
 * The routing capabilities (feature 009 FR-093, research R21, G36): the limits and server settings
 * a client needs before it submits, read from the current configuration on every request. Domain
 * administrators get the bulk limit, the page sizes and the BCC field limits only.
 */
class RoutingCapabilitiesService
{
    public const MAX_LENGTH = 255;

    public const TLS_POLICIES = ['none', 'may', 'encrypt', 'dane', 'dane-only', 'fingerprint', 'verify', 'secure'];

    public function __construct(private readonly RoutingSettings $settings) {}

    /**
     * @return array<string, mixed> contract `RoutingCapabilities`
     */
    public function for(Principal $principal): array
    {
        return [
            'bulkLimit' => RoutingBulkActionRequest::BULK_LIMIT,
            'pageSize' => ['default' => ListQuery::DEFAULT_LIMIT, 'maximum' => ListQuery::MAX_LIMIT],
            'bccMaps' => ['localDestinationMaxLength' => self::MAX_LENGTH, 'bccDestinationMaxLength' => self::MAX_LENGTH],
            'administration' => $principal->role !== Role::Admin ? null : [
                'connectionTest' => [
                    'defaultSender' => $this->settings->testSender(),
                    'defaultRecipient' => $this->settings->testRecipient(),
                    'stepTimeoutSeconds' => $this->settings->stepTimeout(),
                    'totalTimeoutSeconds' => $this->settings->totalTimeout(),
                ],
                'maxLengths' => array_fill_keys([
                    'host', 'nextHop', 'username', 'password', 'transportDestination', 'recipientMapDestination',
                    'tlsPolicyDestination', 'tlsPolicyParameters', 'forwardingHost', 'testSender', 'testRecipient',
                ], self::MAX_LENGTH),
                'tlsPolicies' => self::TLS_POLICIES,
                'forwardingHostDnsLookupLimit' => $this->settings->spfLookupLimit(),
            ],
        ];
    }
}
