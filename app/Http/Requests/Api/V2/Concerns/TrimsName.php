<?php

namespace App\Http\Requests\Api\V2\Concerns;

/**
 * A display name is validated and stored without surrounding whitespace ("1 to 255 characters
 * after trimming"); a name of only whitespace is missing (`required`).
 */
trait TrimsName
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }
    }
}
