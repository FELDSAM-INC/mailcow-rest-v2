<?php

namespace App\Services\Aliases;

use App\Services\Domains\DomainPresenter;
use App\Support\Domain\DomainName;

/**
 * The contract `Alias` of a row of AliasQuery (feature 004 FR-011, FR-012, research R2): readable
 * forms with 002's DomainName, booleans from mailcow's flags (any non-zero `active` is active, as
 * Postfix accepts 1 and 2), comments `""` for NULL, UTC times, and the version tag.
 */
class AliasPresenter
{
    public function __construct(private readonly AliasVersion $version) {}

    /**
     * @return array<string, mixed>
     */
    public function present(object $row): array
    {
        $address = (string) $row->address;
        $domain = (string) $row->domain;
        $target = isset($row->ad_target) ? (string) $row->ad_target : null;
        $primary = $target ?? $domain;
        $destination = AliasDestination::fromGoto((string) $row->goto);

        return [
            'id' => (int) $row->id,
            'address' => $address,
            'addressUnicode' => AliasAddress::unicodeOf($address),
            'kind' => str_starts_with($address, '@') ? 'catchAll' : 'regular',
            'domain' => $domain,
            'domainUnicode' => DomainName::toUnicode($domain),
            'inAliasDomain' => $target !== null,
            'primaryDomain' => $primary,
            'primaryDomainUnicode' => DomainName::toUnicode($primary),
            'destinationType' => $destination->type,
            'destinations' => $destination->addresses,
            'destinationsUnicode' => array_map(AliasAddress::unicodeOf(...), $destination->addresses),
            'active' => (int) $row->active !== 0,
            'senderAllowed' => (int) $row->sender_allowed !== 0,
            'groupwareVisible' => (int) $row->sogo_visible !== 0,
            'internal' => (int) $row->internal !== 0,
            'publicComment' => (string) ($row->public_comment ?? ''),
            'privateComment' => (string) ($row->private_comment ?? ''),
            'createdAt' => DomainPresenter::time($row->created),
            'modifiedAt' => DomainPresenter::time($row->modified),
            'orphaned' => $target === null && ($row->d_domain ?? null) === null,
            'etag' => $this->version->forRow($row),
        ];
    }
}
