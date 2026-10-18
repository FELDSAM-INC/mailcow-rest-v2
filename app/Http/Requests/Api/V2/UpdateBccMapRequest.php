<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\StrictBoolean;
use App\Services\Routing\BccMapQuery;
use App\Services\Routing\BccMapService;
use App\Support\Http\FieldViolations;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `updateBccMap` (`BccMapPatch`, JSON Merge Patch, feature 009 FR-053, FR-054): `type`,
 * `bccDestination` and `active`; the read-only and unknown members are `immutable`. The role and
 * the flag are checked first (`403`), then the scope (`404`), then the body.
 */
class UpdateBccMapRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    private int $bccMapId = 0;

    public function authorize(): bool
    {
        $principal = RequestContext::requirePrincipal($this);
        app(RoutingPolicy::class)->authorize($principal, RoutingPolicy::CHANGE_BCC_MAPS);
        $this->bccMapId = app(BccMapQuery::class)->resolve($principal, (string) $this->route('bccMapId'))
            ?? throw new NotFoundHttpException('The BCC map does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['bail', 'sometimes', 'string', 'in:sender,recipient'],
            'bccDestination' => ['bail', 'sometimes', 'string'],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $principal = RequestContext::principal($this);

        if ($principal === null) {
            return [];
        }

        $id = app(BccMapQuery::class)->resolve($principal, (string) $this->route('bccMapId'));
        $row = $id === null ? null : app(BccMapQuery::class)->find($principal, $id);

        if ($row === null) {
            return [];
        }

        $violations = new FieldViolations;
        BccMapService::values($row, BccMapQuery::present($row), $body, $violations);

        return array_map(fn (array $list): array => $list[0], $violations->all());
    }

    public function bccMapId(): int
    {
        return $this->bccMapId;
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
