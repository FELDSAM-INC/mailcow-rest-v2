<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use App\Rules\NextHopRule;
use App\Rules\SmtpPassword;
use App\Rules\SmtpUsername;
use App\Rules\StrictBoolean;
use App\Services\Routing\RelayhostQuery;
use App\Services\Routing\RelayhostService;
use App\Support\Http\FieldViolations;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `updateRelayhost` (`RelayhostPatch`, JSON Merge Patch, feature 009 FR-009, FR-013,
 * FR-014, FR-020): at least one member (else `required` on `body`), the types and bounds of the
 * changeable members, and, against the stored relayhost, the read-only and unknown members
 * (`immutable`) and the credential rule, all reported together. The role is checked first (`403`),
 * then the relayhost (`404`), then the body (`422`). The service checks again under its row lock.
 */
class UpdateRelayhostRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    private int $relayhostId = 0;

    public function authorize(): bool
    {
        app(RoutingPolicy::class)->authorize(RequestContext::requirePrincipal($this), RoutingPolicy::MANAGE_ROUTING);
        $this->relayhostId = app(RelayhostQuery::class)->resolve((string) $this->route('relayhostId'))
            ?? throw new NotFoundHttpException('The relayhost does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'host' => ['bail', 'sometimes', new NextHopRule],
            'username' => ['bail', 'sometimes', new SmtpUsername],
            'password' => ['bail', 'sometimes', new SmtpPassword],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $id = app(RelayhostQuery::class)->resolve((string) $this->route('relayhostId'));
        $row = $id === null ? null : app(RelayhostQuery::class)->find($id);

        if ($row === null) {
            return [];
        }

        $violations = new FieldViolations;
        RelayhostService::changes($row, RelayhostQuery::present($row), $body, $violations);

        return array_map(fn (array $list): array => $list[0], $violations->all());
    }

    public function relayhostId(): int
    {
        return $this->relayhostId;
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return $this->jsonBody();
    }
}
