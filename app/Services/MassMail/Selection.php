<?php

namespace App\Services\MassMail;

/**
 * A normalised mass mail selection (feature 013 FR-073, data-model.md section 8): lower-cased
 * domains and mailboxes without duplicates, in their first order.
 */
final readonly class Selection
{
    /**
     * @param  list<string>  $domains
     * @param  list<string>  $include
     * @param  list<string>  $exclude
     */
    public function __construct(
        public array $domains = [],
        public array $include = [],
        public array $exclude = [],
    ) {}

    /**
     * @param  array<string, mixed>  $input  `MassMailSelection` as sent (validated types)
     */
    public static function fromInput(array $input): self
    {
        $list = function (mixed $values): array {
            $seen = [];

            foreach (is_array($values) ? $values : [] as $value) {
                $seen[mb_strtolower(trim((string) $value))] = true;
            }

            return array_map('strval', array_keys($seen));
        };

        return new self($list($input['domains'] ?? []), $list($input['include'] ?? []), $list($input['exclude'] ?? []));
    }

    /**
     * Contract `MassMailSelection`, as stored with the job.
     *
     * @return array{domains: list<string>, include: list<string>, exclude: list<string>}
     */
    public function toArray(): array
    {
        return ['domains' => $this->domains, 'include' => $this->include, 'exclude' => $this->exclude];
    }

    /**
     * The audit summary: the domains (up to 20) and the number of each list.
     *
     * @return array{domains: list<string>, domainCount: int, includeCount: int, excludeCount: int}
     */
    public function summary(): array
    {
        return ['domains' => array_slice($this->domains, 0, 20), 'domainCount' => count($this->domains), 'includeCount' => count($this->include), 'excludeCount' => count($this->exclude)];
    }
}
