<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Alias;
use App\Services\Aliases\AliasesCapabilitiesService;
use App\Services\Resources\ResourceQuery;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `updateResource` (`ResourcePatch`, JSON Merge Patch): at least one member, and the types
 * of the changeable members. The role is checked first (`403`), then the resource in the caller's
 * scope (`404`; a mailbox address too), then the body (`422`).
 */
class UpdateResourceRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    private ?string $resource = null;

    public function authorize(): bool
    {
        $principal = RequestContext::requirePrincipal($this);

        if (! Gate::forUser($principal)->allows('update', Alias::class)) {
            return false;
        }

        $this->resource = app(ResourceQuery::class)->resolve($principal, (string) $this->route('resourceAddress'))
            ?? throw new NotFoundHttpException('The resource does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['bail', 'sometimes', 'string', 'max:255'],
            'kind' => ['bail', 'sometimes', 'string', 'in:location,thing,group'],
            'bookingRule' => ['bail', 'sometimes', 'string', 'in:alwaysFree,unlimited,limited'],
            'bookingLimit' => ['bail', 'sometimes', 'nullable', 'integer', 'between:1,'.AliasesCapabilitiesService::BOOKING_LIMIT_MAX],
            'active' => ['bail', 'sometimes', 'boolean'],
        ];
    }

    public function resource(): string
    {
        return (string) $this->resource;
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
