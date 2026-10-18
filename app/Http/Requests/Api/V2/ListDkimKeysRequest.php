<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Rules\QueryBoolean;
use App\Services\Dkim\DkimEntryQuery;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\Gate;

/**
 * Query of `listDkimKeys` (feature 010 FR-009, FR-010): the repeatable `state` (read from the raw
 * query string, because PHP keeps only the last of repeated names; at most 3 of `valid`, `missing`,
 * `unused`), `kind`, the exact `selector`, `complete`, `domain` (a domain together with its alias
 * domains, normalised) and the shared list parameters (`sort` of `DkimKeySort`, default `name`
 * ascending; `q` over the ASCII and the readable name). Administrators and domain administrators;
 * the role is checked before validation.
 */
class ListDkimKeysRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('dkim.viewAny');
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = $this->query();
        unset($data['state']);
        $states = $this->states();

        return $states === null ? $data : $data + ['state' => $states];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'state' => ['bail', 'array', 'max:3'],
            'state.*' => ['bail', 'string', 'in:valid,missing,unused'],
            'kind' => ['bail', 'string', 'in:domain,aliasDomain,none'],
            'selector' => ['bail', 'string', 'max:255'],
            'complete' => ['bail', new QueryBoolean],
            'domain' => ['bail', 'string', 'max:255'],
        ];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, DkimEntryQuery::SORTS, 'name');
    }

    /**
     * @return array{state: list<string>, kind: string|null, selector: string|null, complete: bool|null, domain: string|null}
     */
    public function filters(): array
    {
        $string = fn (string $name): ?string => is_string($value = $this->query($name)) && $value !== '' ? $value : null;
        $domain = $string('domain');

        return [
            'state' => array_values(array_unique($this->states() ?? [])),
            'kind' => $string('kind'),
            'selector' => $string('selector'),
            'complete' => match ($this->query('complete')) {
                'true' => true,
                'false' => false,
                default => null,
            },
            'domain' => $domain === null ? null : (DomainName::normalize($domain) ?? $domain),
        ];
    }

    /**
     * Every `state` value of the raw query string, or null when none is given.
     *
     * @return list<string>|null
     */
    private function states(): ?array
    {
        $values = null;

        foreach (explode('&', (string) $this->server('QUERY_STRING', '')) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if (urldecode($name) === 'state') {
                $values[] = urldecode($value);
            }
        }

        return $values;
    }
}
