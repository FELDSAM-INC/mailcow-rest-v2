<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\ReadsRepeatedQuery;
use App\Models\Mailcow\Domain;
use App\Rules\QueryBoolean;
use App\Services\Domains\DomainQuery;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\Gate;

/**
 * Query of `listDomains` (FR-011–FR-015): `active` and `backupMx` (`true`/`false`), the repeatable
 * `tag` (at most 100 values of 1 to 255 characters) and the shared list parameters (`sort` of
 * `DomainSort`, default `name` ascending; `q`). Administrators and domain administrators; the policy
 * runs before validation.
 */
class ListDomainsRequest extends ApiFormRequest
{
    use ReadsRepeatedQuery;

    private ?ListQuery $listQuery = null;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('viewAny', Domain::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = $this->query();
        unset($data['tag']);
        $tags = $this->repeatedQuery('tag');

        return $tags === [] ? $data : $data + ['tag' => $tags];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'active' => ['bail', new QueryBoolean],
            'backupMx' => ['bail', new QueryBoolean],
            'tag' => ['bail', 'array', 'max:100'],
            'tag.*' => ['bail', 'string', 'min:1', 'max:255'],
        ];
    }

    protected function passedValidation(): void
    {
        $this->listQuery = ListQuery::fromRequest($this, DomainQuery::SORTS, 'name');
    }

    public function listQuery(): ListQuery
    {
        return $this->listQuery ??= ListQuery::fromRequest($this, DomainQuery::SORTS, 'name');
    }

    /**
     * @return array{active: bool|null, backupMx: bool|null, tags: list<string>}
     */
    public function filters(): array
    {
        $bool = fn (string $name): ?bool => match ($this->query($name)) {
            'true' => true,
            'false' => false,
            default => null,
        };

        return [
            'active' => $bool('active'),
            'backupMx' => $bool('backupMx'),
            'tags' => array_values(array_filter(array_map('trim', $this->repeatedQuery('tag')), fn (string $t): bool => $t !== '')),
        ];
    }
}
