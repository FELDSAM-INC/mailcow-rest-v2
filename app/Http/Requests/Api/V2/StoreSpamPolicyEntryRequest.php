<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Rules\SenderPattern;
use App\Services\SpamPolicy\SpamPolicyEntryService;
use Illuminate\Routing\Route;

/**
 * Body of the allow and block list additions (`SpamPolicyEntryCreateRequest`, feature 006
 * FR-046): `list` (`allow`, `block`) and `pattern`, checked after trimming and lower-casing.
 */
class StoreSpamPolicyEntryRequest extends ApiFormRequest
{
    /** @var array{kind: string, name: string}|null */
    private ?array $owner = null;

    /**
     * The role, the flag and the owner's scope are checked before the body (research R15).
     */
    public function authorize(): bool
    {
        $route = $this->route();
        $kind = $route instanceof Route ? (string) ($route->defaults['restv2.ownerKind'] ?? 'mailbox') : 'mailbox';
        $this->owner = app(SpamPolicyEntryService::class)->owner(RequestContext::requirePrincipal($this), $kind, (string) $this->route($kind), true);

        return true;
    }

    /**
     * @return array{kind: string, name: string}
     */
    public function owner(): array
    {
        return $this->owner ?? throw new \LogicException('The owner is resolved by authorize().');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'list' => ['bail', 'required', 'string', 'in:allow,block'],
            'pattern' => ['bail', 'required', 'string', 'max:255', new SenderPattern],
        ];
    }

    protected function memberViolations(array $body): array
    {
        $violations = [];

        foreach (array_keys($body) as $member) {
            if (! in_array($member, ['list', 'pattern'], true)) {
                $violations[(string) $member] = self::make('unknownField', [], 'This member is not accepted here.');
            }
        }

        return $violations;
    }

    public function list(): string
    {
        return (string) $this->validated('list');
    }

    public function pattern(): string
    {
        return SenderPattern::normalize((string) $this->validated('pattern'));
    }
}
