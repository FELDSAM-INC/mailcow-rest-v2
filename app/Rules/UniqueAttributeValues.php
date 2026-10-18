<?php

namespace App\Rules;

use Closure;

/**
 * Attribute values of the identity provider mapping are unique, compared exactly (feature 012
 * FR-013): every repetition is refused with `duplicate` and `duplicateOf`, the path of the first
 * occurrence. Validates the items of a list path such as `attributeMapping.*.attributeValue`.
 */
class UniqueAttributeValues extends CodedRule
{
    /**
     * @param  list<mixed>  $values  the attribute values of every entry, in order
     * @param  string  $path  the item path with `*` in place of the index
     */
    public function __construct(private readonly array $values, private readonly string $path) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $index = $this->indexOf($attribute);
        $first = array_search($value, $this->values, true);

        if ($index !== null && is_int($first) && $first < $index) {
            $this->remember($attribute, $this->violation('duplicate', ['duplicateOf' => str_replace('*', (string) $first, $this->path)], 'This value appears earlier in the list.'), $fail);
        }
    }

    protected function check(mixed $value): ?array
    {
        return null;
    }

    private function indexOf(string $attribute): ?int
    {
        $pattern = '/^'.str_replace('\*', '(\d+)', preg_quote($this->path, '/')).'$/';

        return preg_match($pattern, $attribute, $m) === 1 ? (int) $m[1] : null;
    }
}
