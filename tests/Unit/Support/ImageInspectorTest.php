<?php

namespace Tests\Unit\Support;

use App\Support\Images\ImageInspector;
use App\Support\Images\ImageRejected;
use App\Support\Images\SvgSafetyInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature 013 T023 (research R3; FR-014, FR-015, FR-018): logos are detected by their content, not
 * by the declared type; size and raster dimensions are limited, the dimensions checked before the
 * image is decoded; SVG dimensions come from the root's width and height.
 */
class ImageInspectorTest extends TestCase
{
    private const LIMITS = ['max_size' => 15728640, 'max_width' => 1920, 'max_height' => 1920];

    private static function fixture(string $path): string
    {
        return (string) file_get_contents(__DIR__.'/../../fixtures/logos/'.$path);
    }

    private function inspector(): ImageInspector
    {
        return $this->app->make(ImageInspector::class);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{max_size: int, max_width: int, max_height: int}  $limits
     */
    private function assertRejected(string $bytes, string $rule, array $params, array $limits = self::LIMITS): void
    {
        try {
            $this->inspector()->inspect($bytes, $limits);
            $this->fail("Expected the image to be refused with {$rule}.");
        } catch (ImageRejected $e) {
            $this->assertSame([$rule, $params], [$e->rule, $e->params]);
        }
    }

    /**
     * @return array<string, array{string, string, int|null, int|null}>
     */
    public static function valid(): array
    {
        return [
            'png' => ['valid/logo-300x80.png', 'image/png', 300, 80],
            'jpeg' => ['valid/logo-200x60.jpg', 'image/jpeg', 200, 60],
            'animated gif' => ['valid/animated-120x40.gif', 'image/gif', 120, 40],
            'svg with dimensions' => ['valid/logo-300x80.svg', 'image/svg+xml', 300, 80],
            'svg without dimensions' => ['valid/logo-no-dimensions.svg', 'image/svg+xml', null, null],
            'svg in inches and percent' => ['valid/inkscape-metadata-2in.svg', 'image/svg+xml', 192, null],
            'svg in px' => ['valid/embedded-png-and-references.svg', 'image/svg+xml', 300, 80],
            'png at the limit' => ['valid/logo-1920x1920.png', 'image/png', 1920, 1920],
        ];
    }

    #[DataProvider('valid')]
    public function test_valid_logos_are_detected_with_their_type_and_dimensions(string $file, string $type, ?int $width, ?int $height): void
    {
        $bytes = self::fixture($file);

        $inspection = $this->inspector()->inspect($bytes, self::LIMITS);

        $this->assertSame([$type, strlen($bytes), $width, $height], [$inspection->mediaType, $inspection->sizeBytes, $inspection->width, $inspection->height]);
    }

    public function test_the_declared_type_never_matters(): void
    {
        // A PNG is a PNG whatever the client declares: inspect() sees only bytes.
        $this->assertSame('image/png', $this->inspector()->inspect(self::fixture('valid/logo-300x80.png'), self::LIMITS)->mediaType);
    }

    public function test_a_pdf_named_like_a_png_is_an_unsupported_image_type(): void
    {
        $this->assertRejected(self::fixture('hostile/unsupportedImageType--pdf-named-logo.png'), 'unsupportedImageType', ['allowed' => ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml']]);
    }

    public function test_plain_text_is_an_unsupported_image_type(): void
    {
        $this->assertRejected('just some text', 'unsupportedImageType', ['allowed' => ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml']]);
    }

    public function test_an_empty_body_is_required(): void
    {
        $this->assertRejected('', 'required', []);
    }

    public function test_a_file_above_the_size_limit_is_too_long(): void
    {
        $this->assertRejected(str_repeat('x', 1001), 'tooLong', ['max' => 1000, 'unit' => 'bytes'], ['max_size' => 1000] + self::LIMITS);
        $this->assertRejected(str_repeat("\x89PNG\r\n\x1a\n", 2 * 1048576 + 1), 'tooLong', ['max' => 16 * 1048576, 'unit' => 'bytes'], ['max_size' => 16 * 1048576] + self::LIMITS);
    }

    public function test_the_dimension_limit_is_checked_before_decoding(): void
    {
        $this->assertRejected(self::fixture('hostile/imageDimensionsExceeded--1921x10.png'), 'imageDimensionsExceeded', ['maxWidth' => 1920, 'maxHeight' => 1920, 'width' => 1921, 'height' => 10]);

        $decoded = false;
        $inspector = new ImageInspector($this->app->make(SvgSafetyInspector::class), function (string $bytes) use (&$decoded): bool {
            $decoded = true;

            return true;
        });

        try {
            $inspector->inspect(self::fixture('hostile/imageDimensionsExceeded--1921x10.png'), self::LIMITS);
        } catch (ImageRejected) {
        }

        $this->assertFalse($decoded, 'An oversized raster image must never be decoded.');
    }

    public function test_a_lowered_limit_applies(): void
    {
        $this->assertRejected(self::fixture('valid/logo-300x80.png'), 'imageDimensionsExceeded', ['maxWidth' => 200, 'maxHeight' => 1920, 'width' => 300, 'height' => 80], ['max_width' => 200] + self::LIMITS);
    }

    public function test_a_truncated_png_is_an_invalid_image(): void
    {
        $this->assertRejected(self::fixture('hostile/invalidImage--truncated.png'), 'invalidImage', []);
    }

    public function test_an_svg_has_no_dimension_limit(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="5000" height="5000"/>';

        $inspection = $this->inspector()->inspect($svg, self::LIMITS);

        $this->assertSame([5000, 5000], [$inspection->width, $inspection->height]);
    }

    public function test_an_unsafe_svg_is_refused_with_its_construct(): void
    {
        $this->assertRejected(self::fixture('hostile/scriptElement--script.svg'), 'unsafeSvgContent', ['construct' => 'scriptElement', 'line' => 3]);
    }

    /**
     * @return array<string, array{string, int|null}>
     */
    public static function svgLengths(): array
    {
        return [
            'number' => ['300', 300],
            'px' => ['300px', 300],
            'fraction' => ['99.6', 100],
            'pt' => ['72pt', 96],
            'pc' => ['6pc', 96],
            'mm' => ['25.4mm', 96],
            'cm' => ['2.54cm', 96],
            'in' => ['2in', 192],
            'percent' => ['50%', null],
            'em' => ['10em', null],
            'ex' => ['10ex', null],
            'garbage' => ['wide', null],
            'negative' => ['-5', null],
        ];
    }

    #[DataProvider('svgLengths')]
    public function test_svg_lengths_are_converted_at_96_dpi(string $length, ?int $pixels): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$length.'"/>';

        $this->assertSame($pixels, $this->inspector()->inspect($svg, self::LIMITS)->width);
    }

    public function test_describe_reads_a_stored_logo_without_judging_it(): void
    {
        $hostile = self::fixture('hostile/eventHandlerAttribute--onload.svg');

        $inspection = $this->inspector()->describe($hostile, 'image/svg+xml');

        $this->assertSame(['image/svg+xml', strlen($hostile), 100, 100], [$inspection->mediaType, $inspection->sizeBytes, $inspection->width, $inspection->height]);
        $this->assertSame([null, null], [$this->inspector()->describe('garbage', null)->width, $this->inspector()->describe('garbage', null)->mediaType]);
    }
}
