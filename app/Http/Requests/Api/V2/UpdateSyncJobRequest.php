<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\SyncJobValueRules;
use App\Http\Resources\Api\V2\SyncJobResource;
use App\Models\Mailcow\SyncJob;
use App\Policies\SyncJobPolicy;
use App\Rules\StrictBoolean;
use App\Rules\StrictInteger;
use App\Services\SyncJobs\CustomParametersValidator;
use App\Services\SyncJobs\SyncJobLimits;
use App\Services\SyncJobs\SyncJobScope;
use App\Services\SyncJobs\SyncJobVersion;
use App\Support\Http\ETag;
use Illuminate\Contracts\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Body of `updateSyncJob` (`SyncJobPatch`, JSON Merge Patch, feature 008 research R5, R6), checked
 * in this order: the sync-jobs permission (`403`), the scope (`404`), `If-Match` (`412`), then every
 * member at once (`422`). A body without any patch member is refused (`required` on `body`), `null`
 * is refused for every member, and read-only or unknown members are ignored, so a client may send
 * back what it read. A member sent with its stored value is accepted as stored (only its type is
 * checked), even if it predates today's rules; `mailbox` may only be sent unchanged (`immutable`).
 */
class UpdateSyncJobRequest extends ApiFormRequest
{
    use SyncJobValueRules;

    protected bool $requiresNonEmptyBody = true;

    private ?SyncJob $job = null;

    public function authorize(): bool
    {
        $principal = RequestContext::requirePrincipal($this);
        app(SyncJobPolicy::class)->authorize($principal);

        $this->job = app(SyncJobScope::class)->findInScope((int) $this->route('syncJobId'), $principal)
            ?? throw new NotFoundHttpException('The sync job does not exist.');

        if (ETag::ifMatchFails($this, (new SyncJobVersion)->of($this->job))) {
            throw new PreconditionFailedHttpException('The sync job was changed since it was read.');
        }

        return true;
    }

    /**
     * A body that holds none of the patch members is refused like an empty one.
     *
     * @param  array<string, mixed>  $body
     */
    protected function memberViolations(array $body): array
    {
        $members = [...array_keys($this->valueRules()), 'mailbox'];

        return $body !== [] && array_intersect(array_keys($body), $members) === []
            ? ['body' => self::make('required', [], 'The request body must contain at least one member of SyncJobPatch.')]
            : [];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->valueRules();
        $stored = $this->job === null ? [] : SyncJobResource::present($this->job, false);
        $body = $this->jsonBody();

        foreach ($rules as $member => $memberRules) {
            if (array_key_exists($member, $body) && array_key_exists($member, $stored) && $body[$member] === $stored[$member]) {
                $rules[$member] = self::typeOnly($member);
            }
        }

        return $rules;
    }

    protected function additionalViolations(Validator $validator): array
    {
        $body = $this->jsonBody();
        $violations = [];

        if (array_key_exists('mailbox', $body)) {
            if (! is_string($body['mailbox'])) {
                $violations['mailbox'] = [self::make('invalidType', ['expected' => 'string'])];
            } elseif ($this->job !== null && mb_strtolower(trim($body['mailbox'])) !== mb_strtolower((string) $this->job->user2)) {
                $violations['mailbox'] = [self::make('immutable', [], 'The target mailbox of a sync job cannot be changed.')];
            }
        }

        $custom = $body['customParameters'] ?? null;

        if (is_string($custom) && $this->job !== null && ! $validator->errors()->has('customParameters')) {
            $violations['customParameters'] = app(CustomParametersValidator::class)->violations($custom, (string) $this->job->custom_params, RequestContext::requirePrincipal($this), false);
        }

        return $violations;
    }

    public function syncJobId(): int
    {
        return (int) $this->route('syncJobId');
    }

    /**
     * The validated patch members (read-only and unknown members left out).
     *
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return array_intersect_key($this->jsonBody(), $this->valueRules());
    }

    /**
     * The type rule of a member sent with its stored value.
     *
     * @return list<mixed>
     */
    private static function typeOnly(string $member): array
    {
        return match (true) {
            array_key_exists($member, SyncJobLimits::RANGES) => ['bail', new StrictInteger],
            in_array($member, SyncJobLimits::FLAGS, true) => ['bail', new StrictBoolean],
            default => ['bail', 'string'],
        };
    }
}
