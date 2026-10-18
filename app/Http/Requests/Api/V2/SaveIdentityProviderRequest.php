<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\IdentityProviderType;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\IdentityProviderRules;
use App\Models\Mailcow\IdentityProviderSetting;
use App\Services\IdentityProvider\IdentityProviderConfig;
use App\Services\IdentityProvider\IdentityProviderRepository;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `saveIdentityProvider` (`IdentityProviderInput`): a whole configuration of one type.
 * Administrators only (checked first); mapped templates must exist; the secret must be sent when
 * it may not be kept (FR-009 to FR-014).
 */
class SaveIdentityProviderRequest extends ApiFormRequest
{
    use IdentityProviderRules;

    /** Whether mapped templates must exist (not for a connection test). */
    protected bool $checkTemplates = true;

    protected string $ability = 'update';

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows($this->ability, IdentityProviderSetting::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $type = $this->type();
        $rules = ['type' => ['bail', 'required', 'string', 'in:keycloak,genericOidc,ldap']];

        if ($type === null) {
            return $rules;
        }

        $input = $this->members();

        return $rules + $this->configurationRules(
            $type,
            $input,
            (array) $this->all(),
            $this->checkTemplates,
            app(IdentityProviderRepository::class)->current(),
            IdentityProviderConfig::fromInput($type, $input),
        );
    }

    /**
     * Legacy removes spaces from the LDAP hosts before storing them (FR-012).
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return self::withoutHostSpaces((array) $this->all());
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function withoutHostSpaces(array $input): array
    {
        if (is_array($input['hosts'] ?? null)) {
            $input['hosts'] = array_map(fn (mixed $h): mixed => is_string($h) ? str_replace(' ', '', $h) : $h, $input['hosts']);
        }

        return $input;
    }

    public function type(): ?IdentityProviderType
    {
        $type = $this->input('type');

        return is_string($type) ? IdentityProviderType::tryFrom($type) : null;
    }

    /**
     * The sent members of the configuration, `type` excluded.
     *
     * @return array<string, mixed>
     */
    public function members(): array
    {
        $input = $this->validationData();
        unset($input['type']);

        return $input;
    }

    public function sentSecret(): ?string
    {
        $type = $this->type();
        $secret = $type === null ? null : $this->input($type === IdentityProviderType::Ldap ? 'bindPassword' : 'clientSecret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
