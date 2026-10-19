<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\Role;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\AccountValueRules;
use App\Policies\AdminPolicy;
use App\Services\Accounts\AccountRepresentation;
use Illuminate\Contracts\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `updateAdmin` (`AdminPatch`, feature 007 FR-021, FR-022): a JSON Merge Patch of the
 * username, password, status and forced flags; read-only members accepted unchanged (`immutable`
 * otherwise); unknown members `unknownField`; an empty object `required` on `body`. Only a
 * signed-in administrator (`403`), then the account (`404`).
 */
class UpdateAdminRequest extends ApiFormRequest
{
    use AccountValueRules;

    protected bool $requiresNonEmptyBody = true;

    private const MEMBERS = ['username', 'password', 'active', 'forcePasswordChange', 'forceSecondFactorSetup',
        'secondFactorStatus', 'activePasskeyCount', 'createdAt', 'modifiedAt', 'etag'];

    /** @var array<string, mixed>|null */
    private ?array $current = null;

    public function authorize(): bool
    {
        app(AdminPolicy::class)->authorize(RequestContext::requirePrincipal($this), AdminPolicy::WRITE);
        $this->current = app(AccountRepresentation::class)->find(Role::Admin, (string) $this->route('username'))
            ?? throw new NotFoundHttpException('The administrator does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->accountRules(true);
    }

    protected function memberViolations(array $body): array
    {
        $current = $this->current ?? app(AccountRepresentation::class)->find(Role::Admin, (string) $this->route('username')) ?? [];

        return $this->unknownMembers($body, self::MEMBERS)
            + ReadOnlyMembers::violations($body, $current, ['secondFactorStatus', 'activePasskeyCount', 'createdAt', 'modifiedAt', 'etag']);
    }

    protected function additionalViolations(Validator $validator): array
    {
        return $this->passwordViolations($validator);
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->accountValues();
    }
}
