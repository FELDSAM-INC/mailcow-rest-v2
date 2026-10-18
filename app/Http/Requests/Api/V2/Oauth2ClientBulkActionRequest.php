<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Oauth2Client;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `bulkOauth2ClientAction` (`Oauth2ClientBulkActionRequest`): `delete` or `revokeTokens`
 * and 1 to 100 targets with numeric identifiers (FR-060). Administrators only; any other caller is
 * refused as a whole.
 */
class Oauth2ClientBulkActionRequest extends ApiFormRequest
{
    public const LIMIT = 100;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('bulk', Oauth2Client::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['bail', 'required', 'string', 'in:delete,revokeTokens'],
            'targets' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.self::LIMIT],
            'targets.*' => ['bail', 'array'],
            'targets.*.id' => ['bail', 'required', 'string', 'regex:/^[1-9][0-9]{0,9}$/'],
            'targets.*.version' => ['bail', 'sometimes', 'string'],
        ];
    }

    /**
     * @return list<array{id: string, version?: string|null}>
     */
    public function targets(): array
    {
        return array_values(array_map(
            fn (array $t): array => ['id' => (string) $t['id'], 'version' => isset($t['version']) ? (string) $t['version'] : null],
            (array) $this->validated('targets'),
        ));
    }
}
