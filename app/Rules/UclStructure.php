<?php

namespace App\Rules;

use App\Support\Rspamd\UclStructureChecker;

/**
 * Feature 013 (FR-051): an Rspamd settings rule whose braces and brackets are balanced and whose
 * strings are closed, `unbalancedStructure` with `line` and `construct` otherwise.
 */
class UclStructure extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $violation = (new UclStructureChecker)->check($value);

        return $violation === null ? null : $this->violation('unbalancedStructure', ['line' => $violation['line'], 'construct' => $violation['construct']]);
    }
}
