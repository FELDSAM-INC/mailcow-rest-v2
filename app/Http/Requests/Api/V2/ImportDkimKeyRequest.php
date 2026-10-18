<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Rules\DkimPrivateKey;
use App\Rules\DkimSelector;
use App\Rules\StrictBoolean;
use App\Services\Dkim\DkimNameResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `importDkimKey` (`DkimKeyImportRequest`, feature 010 FR-017 to FR-019):
 *
 *  - `privateKey`: required, string, at most 16,384 bytes, one unencrypted RSA private key in PEM
 *    form (PKCS #1 or PKCS #8) of 1,024 to 8,192 bits (App\Rules\DkimPrivateKey);
 *  - `selector`: as for generation (`invalidFormat` with `format: dkimSelector`, `tooLong`);
 *  - `overwrite`: optional boolean;
 *  - the name in the path must be a valid domain name (`invalidFormat`, `format: domainName`, on
 *    the key `dkimName`), reported together with the body's violations.
 *
 * Administrators only; the role is checked first. Violations carry codes and parameters only:
 * the key text is never echoed, flashed or logged.
 */
class ImportDkimKeyRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('dkim.import');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'privateKey' => ['bail', 'required', 'string', new DkimPrivateKey],
            'selector' => ['bail', 'sometimes', 'filled', 'string', 'max:255', new DkimSelector],
            'overwrite' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    protected function additionalViolations(Validator $validator): array
    {
        $name = $this->route('dkimName');

        return is_string($name) && DkimNameResolver::normalize($name) !== null
            ? []
            : ['dkimName' => [self::make('invalidFormat', ['format' => 'domainName'])]];
    }

    public function privateKey(): string
    {
        return (string) $this->validated('privateKey');
    }

    public function selector(): ?string
    {
        return $this->has('selector') ? (string) $this->validated('selector') : null;
    }

    public function overwrite(): bool
    {
        return $this->validated('overwrite') === true;
    }
}
