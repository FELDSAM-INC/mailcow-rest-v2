<?php

namespace App\Rules;

use Closure;

/**
 * A violation found by a request's cross-field check (feature 012: a member of another provider
 * type, a required attribute field, a secret that must be sent again), attached to its field as a
 * rule so that it is reported together with every other field error. Implicit: it applies also to
 * a field that was not sent. Null passes.
 */
class PrecomputedViolation extends CodedRule
{
    public bool $implicit = true;

    /**
     * @param  array{code: string, params: array<string, mixed>, message: string}|null  $found
     */
    public function __construct(private readonly ?array $found) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->found !== null) {
            $this->remember($attribute, $this->found, $fail);
        }
    }

    protected function check(mixed $value): ?array
    {
        return $this->found;
    }
}
