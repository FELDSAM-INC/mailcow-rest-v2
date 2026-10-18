<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A `validation-failed` problem whose field errors are already mapped to the contract's rule codes
 * (`errors[field] = [{code, params, message}]`).
 */
class ValidationProblemException extends RuntimeException
{
    /**
     * @param  array<string, list<array{code: string, params: array<string, mixed>, message: string}>>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The request contains invalid fields.');
    }
}
