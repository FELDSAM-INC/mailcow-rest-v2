<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\IdentityProviderType;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\IdentityProviderRules;
use App\Http\Requests\Api\V2\Concerns\IdentitySyncRules;
use App\Models\Mailcow\IdentityProviderSetting;
use App\Models\Mailcow\SchemaVersion;
use App\Rules\PrecomputedViolation;
use App\Services\IdentityProvider\IdentityProviderConfig;
use App\Services\IdentityProvider\IdentityProviderRepository;
use App\Services\IdentityProvider\IdentitySyncSettings;
use Illuminate\Support\Facades\Gate;

/**
 * Body of `saveIdentityProvider` (`IdentityProviderInput`): a whole configuration of one type.
 * Administrators only (checked first); mapped templates must exist; the secret must be sent when
 * it may not be kept (FR-009 to FR-014).
 */
class SaveIdentityProviderRequest extends ApiFormRequest
{
    use IdentityProviderRules;
    use IdentitySyncRules;

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
        $sent = $this->jsonBody();
        if (array_key_exists('syncSettings', $sent) && $type->offersSynchronisation()) {
            unset($sent['syncSettings']);
            $rules['syncSettings'] = ['bail', 'required', 'array', 'min:1'];
            if (! SchemaVersion::hasNovember2026Features()) {
                $rules['syncSettings'] = [new PrecomputedViolation(self::make('conflictsWith', ['field' => 'mailcowVersion', 'value' => '2026-11'], 'Synchronization settings require mailcow 2026-11.'))];
            } else {
                $settings = ($this->jsonBody()['syncSettings'] ?? null);
                $rules += $this->synchronizationRules(is_array($settings) ? $settings : [], $type, 'syncSettings.');
            }
        }

        return $rules + $this->configurationRules(
            $type,
            $input,
            $sent,
            $this->checkTemplates,
            app(IdentityProviderRepository::class)->current(),
            IdentityProviderConfig::fromInput($type, $input),
        );
    }

    /** @param array<string, mixed> $body
     * @return array<string, array{code: string, params: array<string, mixed>, message: string}>
     */
    protected function memberViolations(array $body): array
    {
        if (! array_key_exists('syncSettings', $body)) {
            return [];
        }
        $settings = $body['syncSettings'];
        if (! is_array($settings) || array_is_list($settings)) {
            return ['syncSettings' => self::make('invalidType', ['expected' => 'object'])];
        }
        $errors = [];
        foreach ($settings as $member => $value) {
            if (! array_key_exists($member, IdentitySyncSettings::KEYS)) {
                $errors['syncSettings.'.$member] = self::make('immutable', []);
            } elseif ($value === null) {
                $errors['syncSettings.'.$member] = self::make('invalidType', []);
            }
        }

        return $errors;
    }

    /**
     * Legacy removes spaces from the LDAP hosts before storing them (FR-012).
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return self::withoutHostSpaces($this->jsonBody());
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
        $type = ($this->jsonBody()['type'] ?? null);

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
        unset($input['type'], $input['syncSettings']);

        return $input;
    }

    /** @return array<string, mixed>|null */
    public function syncSettings(): ?array
    {
        $settings = ($this->jsonBody()['syncSettings'] ?? null);

        return is_array($settings) ? $settings : null;
    }

    public function sentSecret(): ?string
    {
        $type = $this->type();
        $secret = $type === null ? null : $this->input($type === IdentityProviderType::Ldap ? 'bindPassword' : 'clientSecret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
