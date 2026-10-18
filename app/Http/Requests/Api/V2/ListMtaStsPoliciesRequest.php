<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Rules\QueryBoolean;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\Gate;

/**
 * Query of `listMtaStsPolicies` (feature 010 FR-038): `active`, `mode` (`MtaStsMode`) and the shared
 * list parameters (`sort` of `MtaStsPolicySort`, default `domain` ascending, ties by domain; `q` over
 * the ASCII and the readable domain). Administrators and domain administrators.
 */
class ListMtaStsPoliciesRequest extends ApiFormRequest
{
    public const SORTS = ['domain' => 'm.domain', 'mode' => 'm.mode', 'maxAge' => 'max_age_number', 'policyId' => 'm.id'];

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('mtaSts.use');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'active' => ['bail', new QueryBoolean],
            'mode' => ['bail', 'string', 'in:enforce,testing,none'],
        ];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, self::SORTS, 'domain');
    }

    /**
     * @return array{active: bool|null, mode: string|null}
     */
    public function filters(): array
    {
        return [
            'active' => match ($this->query('active')) {
                'true' => true,
                'false' => false,
                default => null,
            },
            'mode' => is_string($mode = $this->query('mode')) && $mode !== '' ? $mode : null,
        ];
    }
}
