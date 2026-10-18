<?php

namespace App\Services\Routing;

/**
 * The relayhost an assignment refers to (contract `RelayhostReference`, feature 009 FR-024,
 * FR-030): `active` or `inactive` after the relayhost's flag, or `dangling` when no relayhost has the
 * stored identifier (left behind by legacy or `/api/v1`); a dangling reference has no host.
 */
final readonly class RelayhostReference
{
    public function __construct(
        public int $id,
        public ?string $host,
        public string $state,
        public string $username = '',
    ) {}

    public function isActive(): bool
    {
        return $this->state === 'active';
    }

    /**
     * @return array{id: int, host: string|null, state: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'host' => $this->host, 'state' => $this->state];
    }

    /**
     * The relayhost ID of a stored assignment value (`domain.relayhost`, `attributes.relayhost`),
     * compared numerically as the mail server does; null for none (`'0'`, empty, missing or not a
     * number).
     */
    public static function storedId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^\s*0*([1-9][0-9]{0,9})\s*$/', $value, $m) !== 1) {
            return null;
        }

        return (int) $m[1] <= 2147483647 ? (int) $m[1] : null;
    }
}
