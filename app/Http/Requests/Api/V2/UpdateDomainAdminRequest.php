<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\Role;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\AccountValueRules;
use App\Policies\DomainAdminPolicy;
use App\Rules\AssignableDomain;
use App\Services\Accounts\AccountRepresentation;
use App\Support\Domain\DomainName;
use Illuminate\Contracts\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `updateDomainAdmin` (`DomainAdminPatch`, feature 007 FR-021, FR-023, FR-038): a JSON Merge
 * Patch with the rules of creation, every member optional; an empty object is `required` on
 * `body`; the domain set, when sent, needs at least one domain, and its items may be assignments
 * as read; read-only members are accepted unchanged and refused with `immutable` otherwise;
 * unknown members are `unknownField`. The role is checked first (`403`), then the account (`404`).
 */
class UpdateDomainAdminRequest extends ApiFormRequest
{
    use AccountValueRules;

    protected bool $requiresNonEmptyBody = true;

    private const MEMBERS = ['username', 'password', 'active', 'forcePasswordChange', 'forceSecondFactorSetup', 'domains', 'permissions',
        'secondFactorStatus', 'activePasskeyCount', 'createdAt', 'modifiedAt', 'etag'];

    private const ASSIGNMENT_MEMBERS = ['domain', 'domainUnicode', 'active', 'createdAt', 'domainExists'];

    private ?AssignableDomain $domains = null;

    /** @var array<string, mixed>|null */
    private ?array $current = null;

    public function authorize(): bool
    {
        app(DomainAdminPolicy::class)->authorize(RequestContext::requirePrincipal($this));
        $this->current = app(AccountRepresentation::class)->find(Role::DomainAdmin, (string) $this->route('username'))
            ?? throw new NotFoundHttpException('The domain administrator does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->accountRules(true) + $this->permissionRules() + [
            'domains' => ['bail', 'sometimes', 'required', 'array', 'list'],
            'domains.*' => ['bail', $this->assignable()],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $current = $this->current ?? app(AccountRepresentation::class)->find(Role::DomainAdmin, (string) $this->route('username')) ?? [];

        return $this->unknownMembers($body, self::MEMBERS)
            + ReadOnlyMembers::violations($body, $current, ['secondFactorStatus', 'activePasskeyCount', 'createdAt', 'modifiedAt', 'etag'])
            + $this->assignmentViolations($body['domains'] ?? null, $current['domains'] ?? []);
    }

    protected function additionalViolations(Validator $validator): array
    {
        return $this->passwordViolations($validator);
    }

    /**
     * The members to change, normalised.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $values = $this->accountValues();
        $body = $this->jsonBody();

        if (array_key_exists('domains', $body)) {
            $values['domains'] = $this->assignable()->normalised();
        }

        return $values;
    }

    /**
     * Read-only members of assignment items: equal to the stored assignment of that domain, or
     * absent for a domain that is not assigned yet.
     *
     * @param  list<array<string, mixed>>  $stored
     * @return array<string, array{code: string, params: array<string, mixed>, message: string}>
     */
    private function assignmentViolations(mixed $items, array $stored): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            return [];
        }

        $byDomain = [];

        foreach ($stored as $assignment) {
            $byDomain[mb_strtolower((string) $assignment['domain'])] ??= $assignment;
        }

        $violations = [];

        foreach ($items as $index => $item) {
            if (! is_array($item) || array_is_list($item)) {
                continue;
            }

            if (! is_string($item['domain'] ?? null)) {
                $violations["domains.{$index}.domain"] = ApiFormRequest::make(array_key_exists('domain', $item) ? 'invalidType' : 'required', array_key_exists('domain', $item) ? ['expected' => 'string'] : []);

                continue;
            }

            foreach (array_keys($item) as $member) {
                if (! in_array($member, self::ASSIGNMENT_MEMBERS, true)) {
                    $violations["domains.{$index}.{$member}"] = ApiFormRequest::make('unknownField', [], 'This member is not accepted here.');
                }
            }

            $name = DomainName::normalize($item['domain']) ?? '';
            $assignment = $byDomain[$name] ?? null;
            $readOnly = array_diff(self::ASSIGNMENT_MEMBERS, ['domain']);

            foreach (ReadOnlyMembers::violations($item, $assignment ?? [], $readOnly, requirePresence: $assignment !== null) as $member => $violation) {
                $violations["domains.{$index}.{$member}"] = $violation;
            }
        }

        return $violations;
    }

    private function assignable(): AssignableDomain
    {
        return $this->domains ??= new AssignableDomain;
    }
}
