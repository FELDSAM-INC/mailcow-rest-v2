<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `applyDkimKeyBulkAction` (`DkimKeyBulkActionRequest`, feature 010 FR-059): `action`
 * "required, one of `delete`" (`notInList`); `targets` "required array of 1 to 100 BulkTarget
 * objects" (`required`, `tooMany` with `max: 100`), each `id` "required, string, 1 to 255
 * characters" and `version` "optional string". Administrators only, checked first, so that a
 * caller who may never delete keys is refused as a whole.
 */
class DkimKeyBulkActionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('dkim.delete');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['bail', 'required', 'string', 'in:delete'],
            'targets' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.(int) config('restv2.dkim.bulk_limit', 100)],
            'targets.*' => ['bail', 'array'],
            'targets.*.id' => ['bail', 'required', 'string', 'min:1', 'max:255'],
            'targets.*.version' => ['bail', 'sometimes', 'string'],
        ];
    }

    /**
     * @return list<array{id: string, version: string|null}>
     */
    public function targets(): array
    {
        return array_values(array_map(
            fn (array $t): array => ['id' => (string) $t['id'], 'version' => isset($t['version']) ? (string) $t['version'] : null],
            (array) $this->validated('targets'),
        ));
    }
}
