<?php

namespace Tests\Unit\Support;

use App\Support\Images\SvgSafetyInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Feature 013 T024 (research R4, FR-016, SC-003): an SVG logo with active content or external
 * references is refused with the construct and its line, and never altered; logos from design
 * tools with editor metadata, embedded raster images and in-document references are accepted.
 */
class SvgSafetyInspectorTest extends TestCase
{
    private const DIR = __DIR__.'/../../fixtures/logos/';

    /**
     * @return array<string, array{string}>
     */
    public static function hostile(): array
    {
        $cases = [];

        foreach (glob(self::DIR.'hostile/*.svg') ?: [] as $path) {
            $cases[basename($path)] = [basename($path)];
        }

        return $cases;
    }

    #[DataProvider('hostile')]
    public function test_every_hostile_svg_is_refused_with_the_construct_its_name_states(string $file): void
    {
        $expected = explode('--', $file)[0];
        $bytes = (string) file_get_contents(self::DIR.'hostile/'.$file);

        $violation = (new SvgSafetyInspector)->inspect($bytes);

        $this->assertNotNull($violation, "{$file} must be refused.");
        $this->assertSame($expected, $violation['construct']);
        $this->assertGreaterThanOrEqual(1, $violation['line']);
        $this->assertSame($bytes, (string) file_get_contents(self::DIR.'hostile/'.$file), 'The inspector never modifies its input.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function valid(): array
    {
        $cases = [];

        foreach (glob(self::DIR.'valid/*.svg') ?: [] as $path) {
            $cases[basename($path)] = [basename($path)];
        }

        return $cases;
    }

    #[DataProvider('valid')]
    public function test_every_valid_svg_is_accepted(string $file): void
    {
        $this->assertNull((new SvgSafetyInspector)->inspect((string) file_get_contents(self::DIR.'valid/'.$file)));
    }

    public function test_the_hostile_set_covers_every_construct(): void
    {
        $constructs = array_unique(array_map(fn (array $case): string => explode('--', $case[0])[0], self::hostile()));
        sort($constructs);

        $this->assertSame(['doctype', 'eventHandlerAttribute', 'externalReference', 'foreignContent', 'notWellFormed', 'rootNotSvg', 'scriptElement', 'scriptUrl', 'styleImport'], $constructs);
    }

    public function test_an_external_entity_is_refused_without_reading_the_file(): void
    {
        $marker = sys_get_temp_dir().'/restv2-xxe-'.bin2hex(random_bytes(4));
        file_put_contents($marker, 'SECRET-'.bin2hex(random_bytes(8)));
        $svg = "<?xml version=\"1.0\"?>\n<!DOCTYPE svg [<!ENTITY xxe SYSTEM \"file://{$marker}\">]>\n<svg xmlns=\"http://www.w3.org/2000/svg\"><text>&xxe;</text></svg>";
        $atime = fileatime($marker);

        $violation = (new SvgSafetyInspector)->inspect($svg);

        $this->assertSame('doctype', $violation['construct'] ?? null);
        $this->assertStringNotContainsString('SECRET', (string) json_encode($violation));
        clearstatcache();
        $this->assertSame($atime, fileatime($marker));
        unlink($marker);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function smallCases(): array
    {
        $svg = fn (string $inner, string $attributes = ''): string => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"'.$attributes.'>'.$inner.'</svg>';

        return [
            'script in another namespace' => [$svg('<x:script xmlns:x="urn:x">1</x:script>'), 'scriptElement'],
            'upper-case event handler' => [$svg('<rect ONCLICK="x()"/>'), 'eventHandlerAttribute'],
            'vbscript href' => [$svg('<a href="vbscript:x"/>'), 'scriptUrl'],
            'html data url' => [$svg('<image href="data:text/html;base64,PGI+"/>'), 'scriptUrl'],
            'expression in style' => [$svg('<rect style="width: expression(alert(1))"/>'), 'scriptUrl'],
            'animate on handler' => [$svg('<animate attributeName="onclick" to="x()"/>'), 'scriptUrl'],
            'animateTransform href' => [$svg('<animateTransform attributeName="xlink:href" to="https://x"/>'), 'scriptUrl'],
            'src attribute' => [$svg('<image src="https://x.example/a.png"/>'), 'externalReference'],
            'fill url' => [$svg('<rect fill="url(https://x.example/#p)"/>'), 'externalReference'],
            'filter url' => [$svg('<rect filter="url(\'//x.example/f.svg#a\')"/>'), 'externalReference'],
            'style element url' => [$svg('<style>rect { fill: url("https://x.example/a") }</style>'), 'externalReference'],
            'relative href' => [$svg('<use href="other.svg#a"/>'), 'externalReference'],
            'element without namespace' => ['<svg xmlns="http://www.w3.org/2000/svg"><g xmlns=""><div/></g></svg>', 'foreignContent'],
            'other processing instruction' => ["<?php echo 1; ?>\n".$svg(''), 'styleImport'],
            'svg without namespace' => ['<svg width="10"/>', 'rootNotSvg'],
            'not xml' => ['<svg', 'notWellFormed'],
        ];
    }

    #[DataProvider('smallCases')]
    public function test_small_cases(string $svg, string $construct): void
    {
        $this->assertSame($construct, (new SvgSafetyInspector)->inspect($svg)['construct'] ?? null);
    }

    public function test_accepted_references(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            .'<image href="data:image/webp;base64,UklGRg=="/><image xlink:href="data:image/jpeg;base64,/9j/"/>'
            .'<rect fill="url(#g)" style="clip-path: url(&quot;#c&quot;)"/><use xlink:href="#a"/></svg>';

        $this->assertNull((new SvgSafetyInspector)->inspect($svg));
    }

    public function test_the_first_violation_in_document_order_is_reported_with_its_line(): void
    {
        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\">\n<rect/>\n<rect onload=\"x()\"/>\n<script/>\n</svg>";

        $this->assertSame(['construct' => 'eventHandlerAttribute', 'line' => 3], (new SvgSafetyInspector)->inspect($svg));
    }
}
