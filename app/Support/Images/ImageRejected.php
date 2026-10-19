<?php

namespace App\Support\Images;

use RuntimeException;

/**
 * A logo that may not be stored, with the contract's rule code and parameters (feature 013
 * FR-014 to FR-016): `required`, `tooLong`, `unsupportedImageType`, `imageDimensionsExceeded`,
 * `invalidImage` or `unsafeSvgContent`. Reported on the field `body`.
 */
class ImageRejected extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(public readonly string $rule, public readonly array $params = [])
    {
        parent::__construct("The image was refused: {$rule}.");
    }
}
