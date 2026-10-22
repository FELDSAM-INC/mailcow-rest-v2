<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\StrictBoolean;
use App\Services\Routing\TlsPolicyMapService;
use App\Support\Http\FieldViolations;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `updateTlsPolicyMap` (`TlsPolicyMapPatch`, feature 009 FR-071): the rules of creation,
 * and `immutable` for read-only and unknown members. The role is checked first, then the entry.
 */
class UpdateTlsPolicyMapRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    private int $entryId = 0;

    public function authorize(): bool
    {
        app(RoutingPolicy::class)->authorize(RequestContext::requirePrincipal($this), RoutingPolicy::MANAGE_ROUTING);
        $this->entryId = app(TlsPolicyMapService::class)->resolve((string) $this->route('tlsPolicyMapId'))
            ?? throw new NotFoundHttpException('The TLS policy map does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'destination' => ['bail', 'sometimes', 'string'],
            'policy' => ['bail', 'sometimes', 'string'],
            'parameters' => ['bail', 'sometimes', 'string'],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $service = app(TlsPolicyMapService::class);
        $id = $service->resolve((string) $this->route('tlsPolicyMapId'));
        $row = $id === null ? null : $service->find($id);

        if ($row === null) {
            return [];
        }

        $violations = new FieldViolations;
        TlsPolicyMapService::values($row, TlsPolicyMapService::present($row), $body, $violations);

        return array_map(fn (array $list): array => $list[0], $violations->all());
    }

    public function entryId(): int
    {
        return $this->entryId;
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return $this->jsonBody();
    }
}
