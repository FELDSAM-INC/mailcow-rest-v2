<?php

namespace App\Services\Resources;

use App\Models\Mailcow\Resource;
use App\Services\Aliases\AliasAddress;
use App\Services\Domains\DomainPresenter;
use App\Support\Domain\DomainName;

/**
 * The contract `Resource` of a row of ResourceQuery (feature 004 FR-046): readable forms, the
 * booking rule of `multiple_bookings` (0 always free, below 0 unlimited, N limited), UTC times and
 * the version tag.
 */
class ResourcePresenter
{
    public function __construct(private readonly ResourceVersion $version) {}

    /**
     * @return array<string, mixed>
     */
    public function present(object $row): array
    {
        $address = (string) $row->username;
        $domain = (string) $row->domain;
        $bookings = (int) $row->multiple_bookings;

        return [
            'address' => $address,
            'addressUnicode' => AliasAddress::unicodeOf($address),
            'description' => (string) $row->name,
            'kind' => (string) $row->kind,
            'domain' => $domain,
            'domainUnicode' => DomainName::toUnicode($domain),
            'bookingRule' => Resource::bookingRule($bookings),
            'bookingLimit' => Resource::bookingLimit($bookings),
            'active' => (int) $row->active !== 0,
            'createdAt' => DomainPresenter::time($row->created),
            'modifiedAt' => DomainPresenter::time($row->modified),
            'etag' => $this->version->forRow($row),
        ];
    }
}
