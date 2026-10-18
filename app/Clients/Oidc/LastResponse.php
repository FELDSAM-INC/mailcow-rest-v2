<?php

namespace App\Clients\Oidc;

/**
 * The status of the last answer of a provider call, recorded by LeagueOidcClient's middleware to
 * classify failures that the OAuth library reports without it.
 */
final class LastResponse
{
    public ?int $status = null;
}
