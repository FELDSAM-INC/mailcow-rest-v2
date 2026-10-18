<?php

namespace App\Clients\Geo;

/**
 * Looks up the countries of public IP addresses (feature 005 FR-062, research R16), with the
 * external service legacy last_login('get') uses.
 */
interface CountryLookup
{
    /**
     * Looks the addresses up concurrently within the time budget. Addresses whose lookup fails,
     * gives no country or does not finish in time are left out.
     *
     * @param  list<string>  $addresses
     * @return array<string, string> address => ISO 3166-1 alpha-2 code (upper case)
     */
    public function lookup(array $addresses, int $budgetMs): array;
}
