<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\SyncJobValueRules;
use App\Policies\SyncJobPolicy;
use App\Services\SyncJobs\CustomParametersValidator;
use App\Services\SyncJobs\SyncJobTargetResolver;
use Illuminate\Contracts\Validation\Validator;

/**
 * Body of `createSyncJob` (`SyncJobCreateRequest`, feature 008 research R5, R6, R8), checked in
 * this order: the sync-jobs permission (`403`), the target mailbox (`404` when unknown or outside
 * the caller's scope), then every member at once (`422`, `notEligible` for a target that is not a
 * mailbox and every bad custom option included). The duplicate check (`409`) is the service's.
 * Unknown members are ignored.
 */
class StoreSyncJobRequest extends ApiFormRequest
{
    use SyncJobValueRules;

    /** @var array{mailbox: string|null, violation: array{code: string, params: array<string, mixed>, message: string}|null} */
    private array $target = ['mailbox' => null, 'violation' => null];

    public function authorize(): bool
    {
        $principal = RequestContext::requirePrincipal($this);
        app(SyncJobPolicy::class)->authorize($principal);
        $this->target = app(SyncJobTargetResolver::class)->resolve($this->jsonBody()['mailbox'] ?? null, $principal);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->valueRules();

        foreach (['remoteHost', 'remotePort', 'remoteUser', 'remotePassword'] as $member) {
            $rules[$member] = ['bail', 'required', ...array_filter($rules[$member], fn (mixed $rule): bool => ! in_array($rule, ['bail', 'sometimes', 'filled'], true))];
        }

        return $rules;
    }

    protected function additionalViolations(Validator $validator): array
    {
        $violations = [];

        if ($this->target['violation'] !== null) {
            $violations['mailbox'] = [$this->target['violation']];
        }

        $custom = $this->jsonBody()['customParameters'] ?? null;

        if (is_string($custom) && ! $validator->errors()->has('customParameters')) {
            $violations['customParameters'] = app(CustomParametersValidator::class)->violations($custom, null, RequestContext::requirePrincipal($this), true);
        }

        return $violations;
    }

    /** The resolved target mailbox; valid once validation passed. */
    public function target(): string
    {
        return (string) $this->target['mailbox'];
    }

    /**
     * The validated members.
     *
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return array_intersect_key($this->jsonBody(), $this->rules());
    }
}
