<?php

namespace App\Support\Images;

/**
 * What feature 013 knows about a logo (data-model.md section 8): its media type (null for a stored
 * type this API does not know), its size in bytes and its pixel dimensions (null when unknown, for
 * example an SVG without an absolute width).
 */
final readonly class ImageInspection
{
    public function __construct(
        public ?string $mediaType,
        public int $sizeBytes,
        public ?int $width,
        public ?int $height,
    ) {}

    /**
     * The audit form (`mediaType`, `sizeBytes`, `width`, `height`).
     *
     * @return array{mediaType: string|null, sizeBytes: int, width: int|null, height: int|null}
     */
    public function toArray(): array
    {
        return ['mediaType' => $this->mediaType, 'sizeBytes' => $this->sizeBytes, 'width' => $this->width, 'height' => $this->height];
    }
}
