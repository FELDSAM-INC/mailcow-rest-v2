<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Alias;
use App\Services\AliasDomains\AliasDomainQuery;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `updateAliasDomain` (`AliasDomainPatch`, JSON Merge Patch): at least one member, and the
 * types of `targetDomain` and `active`. Read-only members are compared by the service (`immutable`).
 * The role is checked first (`403`), then the scope (`404`), then the body (`422`). No permission
 * flag is needed (legacy behaviour).
 */
class UpdateAliasDomainRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    private ?string $aliasDomain = null;

    public function authorize(): bool
    {
        $principal = RequestContext::requirePrincipal($this);

        if (! Gate::forUser($principal)->allows('update', Alias::class)) {
            return false;
        }

        $this->aliasDomain = app(AliasDomainQuery::class)->resolve($principal, (string) $this->route('aliasDomain'))
            ?? throw new NotFoundHttpException('The alias domain does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'targetDomain' => ['bail', 'sometimes', 'string', 'min:1', 'max:253'],
            'active' => ['bail', 'sometimes', 'boolean'],
        ];
    }

    public function aliasDomain(): string
    {
        return (string) $this->aliasDomain;
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        $body = json_decode((string) $this->getContent(), true);

        return is_array($body) ? $body : [];
    }
}
