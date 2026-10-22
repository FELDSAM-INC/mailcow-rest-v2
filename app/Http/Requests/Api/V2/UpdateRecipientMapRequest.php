<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\StrictBoolean;
use App\Services\Routing\RecipientMapService;
use App\Support\Http\FieldViolations;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `updateRecipientMap` (`RecipientMapPatch`, feature 009 FR-063): the rules of creation,
 * also normalised on edit, and `immutable` for read-only and unknown members.
 */
class UpdateRecipientMapRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    private int $entryId = 0;

    public function authorize(): bool
    {
        app(RoutingPolicy::class)->authorize(RequestContext::requirePrincipal($this), RoutingPolicy::MANAGE_ROUTING);
        $this->entryId = app(RecipientMapService::class)->resolve((string) $this->route('recipientMapId'))
            ?? throw new NotFoundHttpException('The recipient map does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'originalDestination' => ['bail', 'sometimes', 'string'],
            'newDestination' => ['bail', 'sometimes', 'string'],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $service = app(RecipientMapService::class);
        $id = $service->resolve((string) $this->route('recipientMapId'));
        $row = $id === null ? null : $service->find($id);

        if ($row === null) {
            return [];
        }

        $violations = new FieldViolations;
        RecipientMapService::values($row, RecipientMapService::present($row), $body, $violations);

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
