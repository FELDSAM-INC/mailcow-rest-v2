<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Concerns\RedirectUriRules;
use App\Models\Mailcow\Oauth2Client;
use App\Rules\PrecomputedViolation;
use App\Services\Oauth2\Oauth2ClientService;
use Illuminate\Support\Facades\Gate;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `updateOauth2Client` (`Oauth2ClientPatch`, JSON Merge Patch): the redirect URIs; the
 * client ID and the scope only with their current values (`immutable`, FR-045). The role is checked
 * first (`403`), then the client (`404`), then the body (`422`).
 */
class UpdateOauth2ClientRequest extends ApiFormRequest
{
    use RedirectUriRules;

    protected bool $requiresNonEmptyBody = true;

    private ?stdClass $client = null;

    public function authorize(): bool
    {
        if (! Gate::forUser(RequestContext::requirePrincipal($this))->allows('update', Oauth2Client::class)) {
            return false;
        }

        $this->client = app(Oauth2ClientService::class)->find((int) $this->route('oauth2ClientId'))
            ?? throw new NotFoundHttpException('The OAuth2 client does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->redirectUriRules(false);

        foreach (['clientId' => 'client_id', 'scope' => 'scope'] as $member => $column) {
            if ($this->has($member)) {
                $current = $this->client?->{$column};
                $rules[$member] = ['bail', 'string', new PrecomputedViolation($this->input($member) === $current ? null : ApiFormRequest::make('immutable', [], 'This value cannot be changed.'))];
            }
        }

        return $rules;
    }

    public function hasRedirectUris(): bool
    {
        return $this->has('redirectUris');
    }
}
