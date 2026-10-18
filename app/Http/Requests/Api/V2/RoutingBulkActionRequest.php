<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Policies\RoutingPolicy;
use Illuminate\Routing\Route;
use LogicException;

/**
 * Bodies of the bulk actions of feature 009 (FR-090, FR-091, research R20): the actions of the
 * route's collection (named by its `restv2.audit` target), and 1 to 100 targets shaped like
 * `BulkTarget` (`tooMany` with `max: 100`). Duplicates are kept; the bulk runner processes each
 * object once. The whole request is refused first when the caller may not use the action at all:
 * BCC maps need an administrator or a domain administrator with `bccMaps`, every other collection
 * an administrator.
 */
class RoutingBulkActionRequest extends ApiFormRequest
{
    public const BULK_LIMIT = 100;

    /** collection (audit target type) => actions */
    public const ACTIONS = [
        'relayhost' => ['activate', 'deactivate', 'delete'],
        'transport' => ['activate', 'deactivate', 'delete'],
        'recipientMap' => ['activate', 'deactivate', 'delete'],
        'tlsPolicyMap' => ['activate', 'deactivate', 'delete'],
        'bccMap' => ['activate', 'deactivate', 'switchToSender', 'switchToRecipient', 'delete'],
        'forwardingHost' => ['enableSpamFilter', 'disableSpamFilter', 'delete'],
    ];

    public function authorize(): bool
    {
        app(RoutingPolicy::class)->authorize(
            RequestContext::requirePrincipal($this),
            $this->collection() === 'bccMap' ? RoutingPolicy::CHANGE_BCC_MAPS : RoutingPolicy::MANAGE_ROUTING,
        );

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['bail', 'required', 'string', 'in:'.implode(',', self::ACTIONS[$this->collection()])],
            'targets' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.self::BULK_LIMIT],
            'targets.*' => ['bail', 'array'],
            'targets.*.id' => ['bail', 'required', 'string', 'min:1'],
            'targets.*.version' => ['bail', 'sometimes', 'string'],
        ];
    }

    /** The collection of the route: an audit target type of ACTIONS. */
    public function collection(): string
    {
        $route = $this->route();
        $target = $route instanceof Route ? ($route->defaults['restv2.audit']['target'] ?? null) : null;

        if (! is_string($target) || ! isset(self::ACTIONS[$target])) {
            throw new LogicException('A routing bulk action route must declare its collection in restv2.audit.');
        }

        return $target;
    }

    public function action(): string
    {
        return (string) $this->validated('action');
    }

    /**
     * @return list<array{id: string, version: string|null}>
     */
    public function targets(): array
    {
        return array_values(array_map(
            fn (array $t): array => ['id' => (string) $t['id'], 'version' => isset($t['version']) ? (string) $t['version'] : null],
            (array) $this->validated('targets'),
        ));
    }
}
