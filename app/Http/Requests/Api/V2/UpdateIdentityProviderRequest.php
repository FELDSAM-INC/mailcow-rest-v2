<?php

namespace App\Http\Requests\Api\V2;

use App\Exceptions\ProblemException;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\IdentityProviderRules;
use App\Models\Mailcow\IdentityProviderSetting;
use App\Rules\PrecomputedViolation;
use App\Services\IdentityProvider\IdentityProviderConfig;
use App\Services\IdentityProvider\IdentityProviderRepository;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `updateIdentityProvider` (`IdentityProviderPatch`, JSON Merge Patch). The role is checked
 * first (`403`), then that a configuration is stored (`404 identity-provider-not-configured`), then
 * the patch: `type` only with the stored type (`immutable`), members of another type refused, and
 * the rules of a whole save applied to the merged result (FR-009).
 */
class UpdateIdentityProviderRequest extends ApiFormRequest
{
    use IdentityProviderRules;

    protected bool $requiresNonEmptyBody = true;

    private ?IdentityProviderConfig $stored = null;

    public function authorize(): bool
    {
        if (! Gate::forUser(RequestContext::requirePrincipal($this))->allows('update', IdentityProviderSetting::class)) {
            return false;
        }

        $this->stored = app(IdentityProviderRepository::class)->current()
            ?? throw new ProblemException(ProblemType::IdentityProviderNotConfigured);

        return true;
    }

    /**
     * The merged result is validated as a whole save (the members sent keep their raw values, so
     * that invalid input is reported as sent).
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $stored = $this->stored ??= app(IdentityProviderRepository::class)->current();

        if ($stored === null) {
            return (array) $this->all();
        }

        $patch = $this->patch();
        $merged = $stored->withPatch($patch)->settings;
        unset($merged['clientSecret'], $merged['bindPassword']);

        return array_merge($merged, $patch, $this->has('type') ? ['type' => $this->input('type')] : []);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $stored = $this->stored ??= app(IdentityProviderRepository::class)->current();

        if ($stored === null) {
            return [];
        }

        $merged = $this->validationData();
        unset($merged['type']);
        $rules = $this->configurationRules($stored->type, $merged, (array) $this->all(), true, $stored, $stored->withPatch($this->patch()));

        if ($this->has('type') && $this->input('type') !== $stored->type->value) {
            $rules['type'] = [new PrecomputedViolation(ApiFormRequest::make('immutable', [], 'Change the type with a whole save (PUT).'))];
        }

        return $rules;
    }

    /**
     * The sent members, `type` excluded.
     *
     * @return array<string, mixed>
     */
    public function patch(): array
    {
        $patch = SaveIdentityProviderRequest::withoutHostSpaces((array) $this->all());
        unset($patch['type']);

        return $patch;
    }

    public function sentSecret(): ?string
    {
        $stored = $this->stored;
        $secret = $stored === null ? null : $this->input($stored->secretMember());

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
