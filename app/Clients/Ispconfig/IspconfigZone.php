<?php

namespace App\Clients\Ispconfig;

/** An ISPConfig DNS zone (`dns_soa`): its ID, origin (`example.org.`) and owning system group. */
final readonly class IspconfigZone
{
    public function __construct(public int $id, public string $origin, public int $sysGroupId) {}
}
