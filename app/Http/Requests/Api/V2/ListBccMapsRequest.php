<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\ReadsRepeatedQuery;
use App\Policies\RoutingPolicy;
use App\Rules\QueryBoolean;
use App\Services\Routing\BccMapQuery;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;

/**
 * Query of `listBccMaps` (feature 009 FR-046, FR-047, FR-056): `type`, `active`, the repeatable
 * `domain` (ASCII or Unicode, at most 100), `orphaned`, and the shared list parameters (`sort` of
 * `BccMapSort`, default `id` ascending). Administrators and domain administrators.
 */
class ListBccMapsRequest extends ApiFormRequest
{
    use ReadsRepeatedQuery;

    public function authorize(): bool
    {
        app(RoutingPolicy::class)->authorize(RequestContext::requirePrincipal($this), RoutingPolicy::VIEW_BCC_MAPS);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = $this->query();
        unset($data['domain']);
        $domains = $this->repeatedQuery('domain');

        return $domains === [] ? $data : $data + ['domain' => $domains];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['bail', 'string', 'in:sender,recipient'],
            'active' => ['bail', new QueryBoolean],
            'orphaned' => ['bail', new QueryBoolean],
            'domain' => ['bail', 'array', 'max:100'],
            'domain.*' => ['bail', 'string', 'max:255'],
        ];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, BccMapQuery::SORTS, 'id');
    }

    /**
     * @return array{type: string|null, active: bool|null, domain: list<string>, orphaned: bool|null}
     */
    public function filters(): array
    {
        $bool = fn (string $name): ?bool => match ($this->query($name)) {
            'true' => true,
            'false' => false,
            default => null,
        };
        $type = $this->query('type');

        return [
            'type' => is_string($type) ? $type : null,
            'active' => $bool('active'),
            'domain' => array_map(fn (string $d): string => DomainName::normalize($d) ?? mb_strtolower(trim($d)), $this->repeatedQuery('domain')),
            'orphaned' => $bool('orphaned'),
        ];
    }
}
