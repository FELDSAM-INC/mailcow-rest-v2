<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Rules\DkimSelector;
use App\Rules\OneOf;
use App\Rules\StrictBoolean;
use App\Rules\StrictInteger;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `generateDkimKey` (`DkimKeyGenerateRequest`, feature 010 FR-013, FR-014):
 *
 *  - `name`: required, string, 1 to 255 characters (a domain or alias domain in scope, else `404`);
 *  - `selector`: optional, 1 to 255 characters, letters, digits, `-`, `_` and `.` only
 *    (`invalidFormat` with `format: dkimSelector`, `required` when empty, `tooLong`);
 *  - `keySize`: optional, one of 1024, 2048, 3072, 4096 (`notInList`, `invalidType`);
 *  - `overwrite`: optional boolean (`invalidType`).
 *
 * The role is checked first: mailbox users get `403`.
 */
class GenerateDkimKeyRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('dkim.generate');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'selector' => ['bail', 'sometimes', 'filled', 'string', 'max:255', new DkimSelector],
            'keySize' => ['bail', 'sometimes', new StrictInteger, new OneOf(array_map('intval', (array) config('restv2.dkim.allowed_key_sizes')))],
            'overwrite' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    public function name(): string
    {
        return (string) $this->validated('name');
    }

    public function selector(): ?string
    {
        return $this->has('selector') ? (string) $this->validated('selector') : null;
    }

    public function keySize(): ?int
    {
        return $this->has('keySize') ? (int) $this->validated('keySize') : null;
    }

    public function overwrite(): bool
    {
        return $this->validated('overwrite') === true;
    }
}
