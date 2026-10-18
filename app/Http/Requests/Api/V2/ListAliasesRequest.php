<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\ReadsRepeatedQuery;
use App\Models\Mailcow\Alias;
use App\Rules\QueryBoolean;
use App\Services\Aliases\AliasAddress;
use App\Services\Aliases\AliasQuery;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\Gate;

/**
 * Query of `listAliases` (feature 004 FR-014–FR-017): the repeatable filters `address`, `domain`,
 * `primaryDomain` (at most 100 values each), `destination` (20), `kind` and `destinationType` (their
 * enumerations), the booleans `active`, `senderAllowed`, `groupwareVisible` and `internal`, and the
 * shared list parameters (`sort` of `AliasSort`, default `id` descending). Filter values are
 * normalised like stored data. Administrators and domain administrators; the policy runs first.
 */
class ListAliasesRequest extends ApiFormRequest
{
    use ReadsRepeatedQuery;

    private const REPEATED = ['address', 'domain', 'primaryDomain', 'kind', 'destinationType', 'destination'];

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

        foreach (self::REPEATED as $name) {
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
            'address' => ['bail', 'array', 'max:100'],
            'address.*' => ['bail', 'string', 'max:255'],
            'domain' => ['bail', 'array', 'max:100'],
            'domain.*' => ['bail', 'string', 'max:253'],
            'primaryDomain' => ['bail', 'array', 'max:100'],
            'primaryDomain.*' => ['bail', 'string', 'max:253'],
            'kind' => ['bail', 'array', 'max:2'],
            'kind.*' => ['bail', 'string', 'in:regular,catchAll'],
            'destinationType' => ['bail', 'array', 'max:4'],
            'destinationType.*' => ['bail', 'string', 'in:addresses,discard,learnSpam,learnHam'],
            'destination' => ['bail', 'array', 'max:20'],
            'destination.*' => ['bail', 'string', 'max:255'],
            'active' => ['bail', new QueryBoolean],
            'senderAllowed' => ['bail', new QueryBoolean],
            'groupwareVisible' => ['bail', new QueryBoolean],
            'internal' => ['bail', new QueryBoolean],
        ];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, AliasQuery::SORTS, 'id', 'desc');
    }

    /**
     * @return array{address: list<string>, domain: list<string>, primaryDomain: list<string>, kind: list<string>, destinationType: list<string>, destination: list<string>, active: bool|null, senderAllowed: bool|null, groupwareVisible: bool|null, internal: bool|null}
     */
    public function filters(): array
    {
        $address = fn (string $v): string => AliasAddress::parse($v)->address ?? trim($v);
        $domain = fn (string $v): string => DomainName::normalize($v) ?? mb_strtolower(trim($v));
        $bool = fn (string $name): ?bool => match ($this->query($name)) {
            'true' => true,
            'false' => false,
            default => null,
        };

        return [
            'address' => array_map($address, $this->repeatedQuery('address')),
            'domain' => array_map($domain, $this->repeatedQuery('domain')),
            'primaryDomain' => array_map($domain, $this->repeatedQuery('primaryDomain')),
            'kind' => $this->repeatedQuery('kind'),
            'destinationType' => $this->repeatedQuery('destinationType'),
            'destination' => array_map($address, $this->repeatedQuery('destination')),
            'active' => $bool('active'),
            'senderAllowed' => $bool('senderAllowed'),
            'groupwareVisible' => $bool('groupwareVisible'),
            'internal' => $bool('internal'),
        ];
    }
}
