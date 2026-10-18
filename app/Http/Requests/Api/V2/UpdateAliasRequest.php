<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\Mailcow\Alias;
use App\Services\Aliases\AliasQuery;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Body of `updateAlias` (`AliasPatch`, JSON Merge Patch): at least one member (else `required` on
 * `body`), and the types and bounds of the changeable members. Read-only members are accepted with
 * any value here and compared by the service (`immutable`). The role is checked first (`403`), then
 * the scope (`404`), then the body (`422`).
 */
class UpdateAliasRequest extends ApiFormRequest
{
    protected bool $requiresNonEmptyBody = true;

    private ?int $aliasId = null;

    public function authorize(): bool
    {
        $principal = RequestContext::requirePrincipal($this);

        if (! Gate::forUser($principal)->allows('update', Alias::class)) {
            return false;
        }

        $this->aliasId = app(AliasQuery::class)->resolve($principal, (string) $this->route('aliasId'))
            ?? throw new NotFoundHttpException('The alias does not exist.');

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $flag = ['bail', 'sometimes', 'boolean'];

        return [
            'address' => ['bail', 'sometimes', 'string', 'min:1', 'max:255'],
            'destinationType' => ['bail', 'sometimes', 'string', 'in:addresses,discard,learnSpam,learnHam'],
            'destinations' => ['bail', 'sometimes', 'array', 'list', 'min:1'],
            'destinations.*' => ['bail', 'string', 'min:1', 'max:255'],
            'active' => $flag,
            'senderAllowed' => $flag,
            'groupwareVisible' => $flag,
            'internal' => $flag,
            'publicComment' => ['bail', 'sometimes', 'string'],
            'privateComment' => ['bail', 'sometimes', 'string'],
        ];
    }

    public function aliasId(): int
    {
        return (int) $this->aliasId;
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
