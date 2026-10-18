<?php

namespace App\Rules;

/**
 * A member the operation does not accept (`unknownField`), for example a version tag on a target
 * whose objects carry none (feature 011: queued messages).
 */
class UnknownField extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        return $this->violation('unknownField', [], 'This member is not accepted here.');
    }
}
