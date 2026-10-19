<?php

namespace App\Support\Images;

use Closure;
use DOMDocument;

/**
 * Logo validation and description (feature 013 research R3; FR-014, FR-015, FR-018). The type is
 * detected from the bytes only: PNG, JPEG and GIF by their signatures, otherwise SVG when the
 * content is XML (then checked by SvgSafetyInspector, R4). The checks run in this order: an empty
 * body, the size limit, the type, for raster images the dimensions (read from the header, so that
 * an oversized image is never decoded) and then a full decode with GD (legacy checks with
 * Imagick). SVGs have no dimension limit, as in legacy; their size is the root's declared width and
 * height when it is a number, px or an absolute unit (96 dpi).
 */
class ImageInspector
{
    public const ACCEPTED = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml'];

    /** @var Closure(string): bool */
    private readonly Closure $decoder;

    /**
     * @param  (Closure(string): bool)|null  $decoder  whether GD decodes the image (replaceable in tests)
     */
    public function __construct(private readonly SvgSafetyInspector $svg, ?Closure $decoder = null)
    {
        $this->decoder = $decoder ?? static function (string $bytes): bool {
            return @imagecreatefromstring($bytes) !== false;
        };
    }

    /**
     * Validates an uploaded logo.
     *
     * @param  array{max_size: int, max_width: int, max_height: int}  $limits
     *
     * @throws ImageRejected
     */
    public function inspect(string $bytes, array $limits): ImageInspection
    {
        if ($bytes === '') {
            throw new ImageRejected('required');
        }

        if (strlen($bytes) > $limits['max_size']) {
            throw new ImageRejected('tooLong', ['max' => $limits['max_size'], 'unit' => 'bytes']);
        }

        $type = self::detect($bytes);

        if ($type === null) {
            throw new ImageRejected('unsupportedImageType', ['allowed' => self::ACCEPTED]);
        }

        if ($type === 'image/svg+xml') {
            $violation = $this->svg->inspect($bytes);

            if ($violation !== null) {
                throw new ImageRejected('unsafeSvgContent', $violation);
            }

            [$width, $height] = self::svgDimensions($bytes);

            return new ImageInspection($type, strlen($bytes), $width, $height);
        }

        $size = @getimagesizefromstring($bytes);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new ImageRejected('invalidImage');
        }

        [$width, $height] = [(int) $size[0], (int) $size[1]];

        if ($width > $limits['max_width'] || $height > $limits['max_height']) {
            throw new ImageRejected('imageDimensionsExceeded', ['maxWidth' => $limits['max_width'], 'maxHeight' => $limits['max_height'], 'width' => $width, 'height' => $height]);
        }

        if (! ($this->decoder)($bytes)) {
            throw new ImageRejected('invalidImage');
        }

        return new ImageInspection($type, strlen($bytes), $width, $height);
    }

    /**
     * Describes a stored logo without judging it (a legacy upload stays readable): the given media
     * type, the size, and the dimensions read from the bytes when possible.
     */
    public function describe(string $bytes, ?string $mediaType): ImageInspection
    {
        $detected = self::detect($bytes);
        [$width, $height] = match (true) {
            $detected === 'image/svg+xml' => self::svgDimensions($bytes),
            $detected !== null => self::rasterDimensions($bytes),
            default => [null, null],
        };

        return new ImageInspection($mediaType, strlen($bytes), $width, $height);
    }

    /** The media type of the content, or null when it is none of the accepted types. */
    public static function detect(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            preg_match('/^(\xEF\xBB\xBF)?\s*</', $bytes) === 1 => 'image/svg+xml',
            default => null,
        };
    }

    /**
     * @return array{int|null, int|null}
     */
    private static function rasterDimensions(string $bytes): array
    {
        $size = @getimagesizefromstring($bytes);

        return $size === false ? [null, null] : [(int) $size[0], (int) $size[1]];
    }

    /**
     * The declared width and height of an SVG's root element in pixels.
     *
     * @return array{int|null, int|null}
     */
    public static function svgDimensions(string $bytes): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($bytes, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $loaded ? $document->documentElement : null;

        if ($root === null) {
            return [null, null];
        }

        return [self::length($root->getAttribute('width')), self::length($root->getAttribute('height'))];
    }

    private static function length(string $value): ?int
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)?|\.\d+)\s*(px|pt|pc|mm|cm|in)?\s*$/i', $value, $m) !== 1) {
            return null;
        }

        $factor = match (strtolower($m[2] ?? '')) {
            '', 'px' => 1.0,
            'pt' => 96 / 72,
            'pc' => 16.0,
            'mm' => 96 / 25.4,
            'cm' => 96 / 2.54,
            'in' => 96.0,
            default => 1.0,
        };

        return (int) round((float) $m[1] * $factor);
    }
}
