<?php

namespace App\Support\Http;

use App\Exceptions\ValidationProblemException;
use App\Http\Requests\Api\V2\ApiFormRequest;

/**
 * Field violations found by a service's business rules (feature 002 FR-059, research D11 step 8):
 * every violation is collected, then all are refused together as one `422 validation-failed`
 * (docs/conventions.md: every bad entry is named).
 */
final class FieldViolations
{
    /** @var array<string, list<array{code: string, params: array<string, mixed>, message: string}>> */
    private array $errors = [];

    /**
     * @param  array<string, mixed>  $params
     */
    public function add(string $field, string $code, array $params = [], ?string $message = null): void
    {
        $this->errors[$field][] = ApiFormRequest::make($code, $params, $message);
    }

    public function has(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, list<array{code: string, params: array<string, mixed>, message: string}>>
     */
    public function all(): array
    {
        return $this->errors;
    }

    /**
     * @throws ValidationProblemException when any violation was added
     */
    public function throwIfAny(): void
    {
        if ($this->errors !== []) {
            throw new ValidationProblemException($this->errors);
        }
    }
}
