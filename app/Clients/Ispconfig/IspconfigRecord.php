<?php

namespace App\Clients\Ispconfig;

/** An ISPConfig DNS record (`dns_rr`). */
final readonly class IspconfigRecord
{
    public function __construct(public int $id, public string $name, public string $type, public string $data) {}
}
