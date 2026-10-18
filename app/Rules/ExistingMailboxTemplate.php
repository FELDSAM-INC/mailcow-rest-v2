<?php

namespace App\Rules;

use Illuminate\Support\Facades\DB;

/**
 * The name of an existing mailbox template (feature 012 FR-013): `notFound` with
 * `resource: mailboxTemplate` otherwise. Names compare as legacy looks templates up (`=` with the
 * column's case-insensitive collation).
 */
class ExistingMailboxTemplate extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return $this->violation('invalidType', ['expected' => 'string']);
        }

        return DB::table('templates')->where('type', 'mailbox')->where('template', $value)->exists()
            ? null
            : $this->violation('notFound', ['resource' => 'mailboxTemplate'], 'No mailbox template has this name.');
    }
}
