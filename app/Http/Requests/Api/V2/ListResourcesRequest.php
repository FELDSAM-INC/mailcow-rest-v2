<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\ReadsRepeatedQuery;
use App\Models\Mailcow\Alias;
use App\Rules\QueryBoolean;
use App\Services\Resources\ResourceQuery;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\Gate;

/**
 * Query of `listResources` (feature 004 FR-047): the repeatable `domain` (at most 100) and `kind`,
 * `active`, and the shared list parameters (`sort` of `ResourceSort`, default `address` ascending;
 * `q`). Administrators and domain administrators.
 */
class ListResourcesRequest extends ApiFormRequest
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

        foreach (['domain', 'kind'] as $name) {
            unset($data[$name]);
            $values = $this->repeatedQuery($name);

            if ($values !== []) {
                $data[$name] = $values;
            }
        }

        return $data;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'domain' => ['bail', 'array', 'max:100'],
            'domain.*' => ['bail', 'string', 'max:253'],
            'kind' => ['bail', 'array', 'max:3'],
            'kind.*' => ['bail', 'string', 'in:location,thing,group'],
            'active' => ['bail', new QueryBoolean],
        ];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, ResourceQuery::SORTS, 'address');
    }

    /**
     * @return array{domain: list<string>, kind: list<string>, active: bool|null}
     */
    public function filters(): array
    {
        return [
            'domain' => array_map(fn (string $d): string => DomainName::normalize($d) ?? mb_strtolower(trim($d)), $this->repeatedQuery('domain')),
            'kind' => $this->repeatedQuery('kind'),
            'active' => match ($this->query('active')) {
                'true' => true,
                'false' => false,
                default => null,
            },
        ];
    }
}
