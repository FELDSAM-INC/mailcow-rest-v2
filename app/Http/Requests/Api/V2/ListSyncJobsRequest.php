<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\SyncJobPolicy;
use App\Rules\QueryBoolean;
use App\Services\SyncJobs\SyncJobQuery;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;

/**
 * Query of `listSyncJobs` (feature 008 FR-011–FR-014): the filters `mailbox` and `domain` (exact,
 * case-insensitive, at most 255 characters), `active` and `running` (`true`/`false`) and
 * `lastResult` (`SyncJobLastResult`), and the shared list parameters (`sort` of `SyncJobSort`,
 * default `id` ascending). The sync-jobs permission is checked first (`403`), then the query.
 */
class ListSyncJobsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        app(SyncJobPolicy::class)->authorize(RequestContext::requirePrincipal($this));

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->query();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'mailbox' => ['bail', 'string', 'max:255'],
            'domain' => ['bail', 'string', 'max:255'],
            'active' => ['bail', new QueryBoolean],
            'running' => ['bail', new QueryBoolean],
            'lastResult' => ['bail', 'string', 'in:succeeded,failed,unknown'],
        ];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, SyncJobQuery::SORTS, 'id');
    }

    /**
     * @return array{mailbox: string|null, domain: string|null, active: bool|null, running: bool|null, lastResult: string|null}
     */
    public function filters(): array
    {
        $string = fn (string $name): ?string => is_string($value = $this->query($name)) && trim($value) !== '' ? trim($value) : null;
        $bool = fn (string $name): ?bool => match ($this->query($name)) {
            'true' => true,
            'false' => false,
            default => null,
        };
        $domain = $string('domain');

        return [
            'mailbox' => $string('mailbox'),
            'domain' => $domain === null ? null : (DomainName::normalize($domain) ?? mb_strtolower($domain)),
            'active' => $bool('active'),
            'running' => $bool('running'),
            'lastResult' => $string('lastResult'),
        ];
    }
}
