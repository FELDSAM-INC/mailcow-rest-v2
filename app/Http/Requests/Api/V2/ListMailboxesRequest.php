<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\ReadsRepeatedQuery;
use App\Models\Mailcow\Mailbox;
use App\Services\Mailboxes\MailboxListQuery;
use App\Services\Mailboxes\MailboxValues;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\Gate;

/**
 * Query of `listMailboxes` (feature 003 FR-012–FR-015): the repeatable filters `domain` (at most 100
 * values of up to 255 characters), `status`, `tag` (at most 100 values of 1 to 255 characters) and
 * `authSource`, and the shared list parameters (`sort` of `MailboxSort`, default `address`
 * ascending; `q`). Administrators and domain administrators; the policy runs before validation.
 */
class ListMailboxesRequest extends ApiFormRequest
{
    use ReadsRepeatedQuery;

    private const REPEATED = ['domain', 'status', 'tag', 'authSource'];

    private ?ListQuery $listQuery = null;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('viewAny', Mailbox::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = array_diff_key($this->query(), array_flip(self::REPEATED));

        foreach (self::REPEATED as $name) {
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
            'domain.*' => ['bail', 'string', 'max:255'],
            'status' => ['bail', 'array', 'max:3'],
            'status.*' => ['bail', 'string', 'in:'.implode(',', array_keys(MailboxValues::STATUSES))],
            'tag' => ['bail', 'array', 'max:100'],
            'tag.*' => ['bail', 'string', 'min:1', 'max:255'],
            'authSource' => ['bail', 'array', 'max:4'],
            'authSource.*' => ['bail', 'string', 'in:'.implode(',', array_keys(MailboxValues::AUTH_SOURCES))],
        ];
    }

    protected function passedValidation(): void
    {
        $this->listQuery = ListQuery::fromRequest($this, MailboxListQuery::SORTS, 'address');
    }

    public function listQuery(): ListQuery
    {
        return $this->listQuery ??= ListQuery::fromRequest($this, MailboxListQuery::SORTS, 'address');
    }

    /**
     * @return array{domains: list<string>, statuses: list<string>, tags: list<string>, authSources: list<string>}
     */
    public function filters(): array
    {
        $values = fn (string $name): array => array_values(array_filter(array_map('trim', $this->repeatedQuery($name)), fn (string $v): bool => $v !== ''));

        return [
            'domains' => $values('domain'),
            'statuses' => $values('status'),
            'tags' => $values('tag'),
            'authSources' => $values('authSource'),
        ];
    }
}
