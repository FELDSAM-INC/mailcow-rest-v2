<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Domain;
use App\Services\Domains\DomainScope;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `replaceDomainFooter` (`DomainFooterReplace`). Administrators and the domain's domain
 * administrators, no permission flag needed; the scope is resolved before the body is validated.
 */
class ReplaceDomainFooterRequest extends ApiFormRequest
{
    private ?string $domain = null;

    public function authorize(): bool
    {
        $principal = RequestContext::requirePrincipal($this);

        if (! Gate::forUser($principal)->allows('updateFooter', Domain::class)) {
            return false;
        }

        $this->domain = app(DomainScope::class)->resolve($principal, (string) $this->route('domain'))
            ?? throw new NotFoundHttpException('The domain does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'html' => ['bail', 'present', 'string'],
            'plain' => ['bail', 'present', 'string'],
            'skipReplies' => ['bail', 'required', 'boolean'],
            'excludedAddresses' => ['bail', 'sometimes', 'array'],
            'excludedAddresses.*' => ['bail', 'string', 'max:255'],
            'excludedAliasDomains' => ['bail', 'sometimes', 'array'],
            'excludedAliasDomains.*' => ['bail', 'string', 'max:255'],
        ];
    }

    public function domain(): string
    {
        return (string) $this->domain;
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
