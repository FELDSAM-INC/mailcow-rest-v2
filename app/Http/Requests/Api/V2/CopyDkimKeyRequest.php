<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Rules\StrictBoolean;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `copyDkimKey` (`DkimKeyCopyRequest`, feature 010 FR-022): `target` "required, string, 1 to
 * 255 characters" (a domain or alias domain other than the source, checked by the service:
 * `notFound`, `sameAsSource`) and the optional boolean `overwrite`. Administrators only.
 */
class CopyDkimKeyRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('dkim.copy');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'target' => ['bail', 'required', 'string', 'max:255'],
            'overwrite' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    public function target(): string
    {
        return (string) $this->validated('target');
    }

    public function overwrite(): bool
    {
        return $this->validated('overwrite') === true;
    }
}
