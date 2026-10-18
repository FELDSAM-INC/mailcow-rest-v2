<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Http\RequestContext;
use App\Rules\StrictBoolean;
use App\Support\Http\ProblemType;

/**
 * Body of `applyMailboxMailSettingsBulkAction` (feature 005 FR-060): `setTlsEnforcement` with at
 * least one TLS value and 1 to the bulk limit targets. Administrators and domain administrators;
 * mailbox users are refused as a whole.
 */
class MailboxMailSettingsBulkActionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        if (RequestContext::requirePrincipal($this)->role === Role::MailboxUser) {
            throw new ProblemException(ProblemType::Forbidden, 'Mailbox users cannot change several mailboxes at once.');
        }

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['bail', 'required', 'string', 'in:setTlsEnforcement'],
            'tlsEnforceInbound' => ['bail', 'required_without:tlsEnforceOutbound', new StrictBoolean],
            'tlsEnforceOutbound' => ['bail', 'required_without:tlsEnforceInbound', new StrictBoolean],
            'targets' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.(int) config('restv2.bulk_limit')],
            'targets.*' => ['bail', 'array'],
            'targets.*.id' => ['bail', 'required', 'string', 'min:1'],
            'targets.*.version' => ['bail', 'sometimes', 'string'],
        ];
    }

    /**
     * @return array{tlsEnforceInbound?: bool, tlsEnforceOutbound?: bool}
     */
    public function patch(): array
    {
        /** @var array{tlsEnforceInbound?: bool, tlsEnforceOutbound?: bool} */
        return $this->only(['tlsEnforceInbound', 'tlsEnforceOutbound']);
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
