<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\ReadsRepeatedQuery;
use App\Policies\RoutingPolicy;
use App\Services\Routing\BccDestinationChoiceQuery;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;

/**
 * Query of `listBccDestinationChoices` (feature 009 FR-057): the repeatable `kind` and `domain`, and
 * the shared list parameters (`sort` of `BccDestinationChoiceSort`, default `kind`).
 * Administrators and domain administrators.
 */
class ListBccDestinationChoicesRequest extends ApiFormRequest
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

        foreach (['kind', 'domain'] as $name) {
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
            'kind' => ['bail', 'array', 'max:5'],
            'kind.*' => ['bail', 'string', 'in:'.implode(',', BccDestinationChoiceQuery::KINDS)],
            'domain' => ['bail', 'array', 'max:100'],
            'domain.*' => ['bail', 'string', 'max:255'],
        ];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, BccDestinationChoiceQuery::SORTS, 'kind');
    }

    /**
     * @return array{kind: list<string>, domain: list<string>}
     */
    public function filters(): array
    {
        return [
            'kind' => $this->repeatedQuery('kind'),
            'domain' => array_map(fn (string $d): string => DomainName::normalize($d) ?? mb_strtolower(trim($d)), $this->repeatedQuery('domain')),
        ];
    }
}
