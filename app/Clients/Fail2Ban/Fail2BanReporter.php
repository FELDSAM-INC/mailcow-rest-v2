<?php

namespace App\Clients\Fail2Ban;

/**
 * Reports invalid credentials to mailcow's fail2ban (netfilter-mailcow), FR-006, FR-040.
 */
interface Fail2BanReporter
{
    public function invalidPassword(string $username, string $ip): void;

    public function invalidApiKey(string $ip): void;
}
