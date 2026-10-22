<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\NextHopRule;
use App\Rules\SmtpPassword;
use App\Rules\SmtpUsername;
use App\Rules\StrictBoolean;
use App\Services\Routing\TransportQuery;
use App\Services\Routing\TransportService;
use App\Support\Http\FieldViolations;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `updateTransport` (`TransportPatch`, JSON Merge Patch, feature 009 FR-039): at least one
 * member, the types and bounds of the changeable members, and, against the stored transport, the
 * destination for the resulting kind and the read-only and unknown members (`immutable`), all
 * reported together. The role is checked first (`403`), then the transport (`404`).
 */
class UpdateTransportRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    private int $transportId = 0;

    public function authorize(): bool
    {
        app(RoutingPolicy::class)->authorize(RequestContext::requirePrincipal($this), RoutingPolicy::MANAGE_ROUTING);
        $this->transportId = app(TransportQuery::class)->resolve((string) $this->route('transportId'))
            ?? throw new NotFoundHttpException('The transport does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'destination' => ['bail', 'sometimes', 'string'],
            'nextHop' => ['bail', 'sometimes', new NextHopRule],
            'mxBased' => ['bail', 'sometimes', new StrictBoolean],
            'active' => ['bail', 'sometimes', new StrictBoolean],
            'username' => ['bail', 'sometimes', new SmtpUsername],
            'password' => ['bail', 'sometimes', new SmtpPassword],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $id = app(TransportQuery::class)->resolve((string) $this->route('transportId'));
        $row = $id === null ? null : app(TransportQuery::class)->find($id);

        if ($row === null) {
            return [];
        }

        $violations = new FieldViolations;
        TransportService::plan($row, TransportQuery::present($row), $body, $violations);

        return array_map(fn (array $list): array => $list[0], $violations->all());
    }

    public function transportId(): int
    {
        return $this->transportId;
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return $this->jsonBody();
    }
}
