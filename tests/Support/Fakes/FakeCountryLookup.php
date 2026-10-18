<?php

namespace Tests\Support\Fakes;

use App\Clients\Geo\CountryLookup;

/**
 * Stands in for the external country lookup (feature 005 FR-062): answers fixed countries, waits
 * as long as the slowest address of a batch would take (at most the budget, as concurrent lookups
 * do) and leaves out addresses slower than the budget. Records every batch with its budget.
 */
final class FakeCountryLookup implements CountryLookup
{
    /** @var array<string, string> address => country */
    public array $countries = [];

    /** @var array<string, float> address => seconds the lookup takes */
    public array $delays = [];

    /** @var list<array{0: list<string>, 1: int}> */
    public array $calls = [];

    public function lookup(array $addresses, int $budgetMs): array
    {
        $this->calls[] = [$addresses, $budgetMs];
        $budget = $budgetMs / 1000;
        $slowest = max([0.0, ...array_map(fn (string $address): float => $this->delays[$address] ?? 0.0, $addresses)]);
        usleep((int) (min($slowest, $budget) * 1_000_000));

        $countries = [];

        foreach ($addresses as $address) {
            if (isset($this->countries[$address]) && ($this->delays[$address] ?? 0.0) <= $budget) {
                $countries[$address] = $this->countries[$address];
            }
        }

        return $countries;
    }
}
