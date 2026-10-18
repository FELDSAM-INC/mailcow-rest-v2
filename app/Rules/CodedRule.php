<?php

namespace App\Rules;

use App\Http\Requests\Api\V2\ApiFormRequest;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A validation rule that reports the contract's rule code and parameters (FR-055). The violation
 * is kept per attribute, so that one instance can validate every item of a list.
 */
abstract class CodedRule implements ValidationRule
{
    /** @var array<string, array{code: string, params: array<string, mixed>, message: string}> */
    private array $violations = [];

    /**
     * The violation of the value, or null when it is valid.
     *
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    abstract protected function check(mixed $value): ?array;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $violation = $this->check($value);

        if ($violation !== null) {
            $this->remember($attribute, $violation, $fail);
        }
    }

    /**
     * Records a violation of the attribute and fails it, for rules that override validate()
     * because they need the attribute's path (for example its list index).
     *
     * @param  array{code: string, params: array<string, mixed>, message: string}  $violation
     */
    protected function remember(string $attribute, array $violation, Closure $fail): void
    {
        $this->violations[$attribute] = $violation;
        $fail($violation['message']);
    }

    /**
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    public function violationFor(string $attribute): ?array
    {
        return $this->violations[$attribute] ?? null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{code: string, params: array<string, mixed>, message: string}
     */
    protected function violation(string $code, array $params = [], ?string $message = null): array
    {
        return ApiFormRequest::make($code, $params, $message);
    }
}
