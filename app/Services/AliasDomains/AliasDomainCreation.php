<?php

namespace App\Services\AliasDomains;

/**
 * The result of an alias domain creation: its name and the report of the follow-up steps.
 */
final readonly class AliasDomainCreation
{
    public function __construct(public string $name, public AliasDomainCreationReport $report) {}
}
