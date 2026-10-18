<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\DataAwareRule;

/**
 * A member that may not be sent together with another one (feature 005): `mutuallyExclusive` with
 * `with` naming the other member. With $whenValue, the other member excludes only with that value
 * (for example `generatePassword: true`).
 */
class ExcludesMember extends CodedRule implements DataAwareRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $other, private readonly mixed $whenValue = null) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    protected function check(mixed $value): ?array
    {
        if (! array_key_exists($this->other, $this->data) || ($this->whenValue !== null && $this->data[$this->other] !== $this->whenValue)) {
            return null;
        }

        return $this->violation('mutuallyExclusive', ['with' => $this->other], "Not allowed together with {$this->other}.");
    }
}
