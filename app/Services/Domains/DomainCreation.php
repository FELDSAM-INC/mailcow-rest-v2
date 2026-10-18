<?php

namespace App\Services\Domains;

/**
 * A completed domain creation: the stored name and the report of its follow-up steps.
 */
final readonly class DomainCreation
{
    public function __construct(public string $name, public DomainCreationReport $report) {}
}
