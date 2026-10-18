<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\ReadsRepeatedQuery;
use App\Models\Mailcow\Alias;
use App\Rules\QueryBoolean;
use App\Services\AliasDomains\AliasDomainQuery;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\Gate;

/**
 * Query of `listAliasDomains` (feature 004 FR-039): the repeatable `targetDomain` (at most 100
 * values, normalised like stored names), `active`, and the shared list parameters (`sort` of
 * `AliasDomainSort`, default `name` ascending; `q`). Administrators and domain administrators.
 */
class ListAliasDomainsRequest extends ApiFormRequest
{
    use ReadsRepeatedQuery;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('viewAny', Alias::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = $this->query();
        unset($data['targetDomain']);
        $targets = $this->repeatedQuery('targetDomain');

        return $targets === [] ? $data : $data + ['targetDomain' => $targets];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'targetDomain' => ['bail', 'array', 'max:100'],
            'targetDomain.*' => ['bail', 'string', 'max:253'],
            'active' => ['bail', new QueryBoolean],
        ];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, AliasDomainQuery::SORTS, 'name');
    }

    /**
     * @return array{targetDomain: list<string>, active: bool|null}
     */
    public function filters(): array
    {
        return [
            'targetDomain' => array_values(array_map(fn (string $d): string => DomainName::normalize($d) ?? mb_strtolower(trim($d)), $this->repeatedQuery('targetDomain'))),
            'active' => match ($this->query('active')) {
                'true' => true,
                'false' => false,
                default => null,
            },
        ];
    }
}
