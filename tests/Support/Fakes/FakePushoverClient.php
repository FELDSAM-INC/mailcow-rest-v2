<?php

namespace Tests\Support\Fakes;

use App\Clients\Pushover\PushoverClient;
use App\Clients\Pushover\PushoverVerdict;

/**
 * Stands in for Pushover's validation service (feature 005 FR-057): answers a fixed verdict and
 * records the credentials it was asked about.
 */
final class FakePushoverClient implements PushoverClient
{
    /** @var list<array{token: string, user: string}> */
    public array $calls = [];

    public PushoverVerdict $verdict;

    public function __construct()
    {
        $this->verdict = PushoverVerdict::accepted();
    }

    public function validate(string $token, string $user): PushoverVerdict
    {
        $this->calls[] = ['token' => $token, 'user' => $user];

        return $this->verdict;
    }
}
