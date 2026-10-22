<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Alias;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `createAlias` (`AliasCreateRequest`): the contract's types, bounds and enumerations. The
 * business rules (address syntax and scope, destinations, comment bytes, external destinations) are
 * App\Services\Aliases\AliasRules', so that every invalid entry is reported together. Administrators
 * and domain administrators; the policy runs before validation.
 */
class StoreAliasRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('create', Alias::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $flag = ['bail', 'sometimes', 'boolean'];

        return [
            'address' => ['bail', 'required', 'string', 'min:1', 'max:255'],
            'destinationType' => ['bail', 'sometimes', 'string', 'in:addresses,discard,learnSpam,learnHam'],
            'destinations' => ['bail', 'sometimes', 'array', 'list', 'min:1'],
            'destinations.*' => ['bail', 'string', 'min:1', 'max:255'],
            'active' => $flag,
            'senderAllowed' => $flag,
            'groupwareVisible' => $flag,
            'internal' => $flag,
            'publicComment' => ['bail', 'sometimes', 'string'],
            'privateComment' => ['bail', 'sometimes', 'string'],
        ];
    }

    /**
     * The members that were sent.
     *
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return $this->jsonBody();
    }
}
