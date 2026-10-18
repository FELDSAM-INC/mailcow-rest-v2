<?php

namespace App\Services\Aliases;

use App\Support\Domain\DomainName;

/**
 * One entry of a copy over alias domains (feature 004 FR-037, contract `AliasCopyOutcome`):
 * `created` with the copy's identifier, `skipped` with the reason, or `failed` with the problem.
 */
final readonly class AliasCopyOutcome
{
    /**
     * @param  array<string, mixed>|null  $problem
     */
    private function __construct(
        public string $aliasDomain,
        public string $address,
        public string $outcome,
        public ?int $aliasId = null,
        public ?string $reason = null,
        public ?array $problem = null,
    ) {}

    public static function created(string $aliasDomain, string $address, int $aliasId): self
    {
        return new self($aliasDomain, $address, 'created', $aliasId);
    }

    public static function skipped(string $aliasDomain, string $address, string $reason): self
    {
        return new self($aliasDomain, $address, 'skipped', null, $reason);
    }

    /**
     * @param  array<string, mixed>  $problem
     */
    public static function failed(string $aliasDomain, string $address, array $problem): self
    {
        return new self($aliasDomain, $address, 'failed', null, null, $problem);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $entry = [
            'aliasDomain' => $this->aliasDomain,
            'aliasDomainUnicode' => DomainName::toUnicode($this->aliasDomain),
            'address' => $this->address,
            'addressUnicode' => AliasAddress::unicodeOf($this->address),
            'outcome' => $this->outcome,
            'aliasId' => $this->aliasId,
            'reason' => $this->reason,
        ];

        if ($this->problem !== null) {
            $entry['problem'] = $this->problem;
        }

        return $entry;
    }
}
