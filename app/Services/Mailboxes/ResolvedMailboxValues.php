<?php

namespace App\Services\Mailboxes;

/**
 * The values of a new mailbox (feature 003 FR-022): `values` with the request applied, and the
 * `baseline` the mailbox would get from the named template, the Default template and the built-in
 * defaults alone. A `quota` of null means the domain's default quota for a new mailbox applies.
 */
final readonly class ResolvedMailboxValues
{
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $baseline
     */
    public function __construct(
        public array $values,
        public array $baseline,
    ) {}
}
