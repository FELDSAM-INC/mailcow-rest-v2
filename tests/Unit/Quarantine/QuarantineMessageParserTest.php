<?php

namespace Tests\Unit\Quarantine;

use App\Services\Quarantine\QuarantineMessageParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Quarantine\LargeMessage;
use Tests\TestCase;

/**
 * Feature 006 T024 (research R5, FR-013, SC-001): the parsed content of every message fixture equals
 * what legacy inc/ajax/qitem_details.php shows for it (tests/fixtures/quarantine/legacy, generated
 * by scripts/legacy/generate-qitem-goldens.sh with the tag's own libraries), apart from the stated
 * deviations: empty strings instead of legacy's `-`, `textPlain` `null` instead of `-` or "Parser
 * failed, assuming HTML" (then `parserFallback`), the envelope recipient as `envelope`, safe
 * attachment names (`attachment-<position>` for an unnamed one) and the SHA-256 instead of a
 * VirusTotal link. Nothing is written to disk.
 */
class QuarantineMessageParserTest extends TestCase
{
    /** Legacy's escaped attachment names => the API's safe names (spec FR-016). */
    private const SAFE_NAMES = ['_._.._etc_passwd' => '....etcpasswd', 'noname1' => 'attachment-0'];

    /**
     * @return array<string, array{string}>
     */
    public static function fixtures(): array
    {
        $names = [];

        foreach (glob(dirname(__DIR__, 2).'/fixtures/quarantine/messages/*.eml') ?: [] as $path) {
            $name = basename($path, '.eml');
            $names[$name] = [$name];
        }

        return $names;
    }

    private function parser(): QuarantineMessageParser
    {
        return $this->app->make(QuarantineMessageParser::class);
    }

    #[DataProvider('fixtures')]
    public function test_the_parsed_content_equals_legacys(string $name): void
    {
        $golden = json_decode((string) file_get_contents(dirname(__DIR__, 2)."/fixtures/quarantine/legacy/{$name}.json"), true);
        $this->assertIsArray($golden);

        $parsed = $this->parser()->parse(LargeMessage::fixture($name), 'alice@example.org')->toArray(true);

        $fallback = $golden['text_plain'] === 'Parser failed, assuming HTML';
        $expected = [
            'from' => is_string($golden['header_from']) ? $golden['header_from'] : '',
            'subject' => $golden['subject'] === '-' ? '' : $golden['subject'],
            'recipients' => array_map(fn (array $r): array => ['address' => $r['address'], 'source' => $r['type'] === 'smtp' ? 'envelope' : $r['type']], $golden['recipients']),
            'textPlain' => $fallback || $golden['text_plain'] === '-' ? null : $golden['text_plain'],
            'textFromHtml' => $golden['text_html'],
            'parserFallback' => $fallback,
            'attachments' => array_map(fn (int $position, array $a): array => [
                'position' => $position,
                'fileName' => self::SAFE_NAMES[$a['name']] ?? $a['name'],
                'contentType' => $a['type'],
                'size' => $a['size'],
                'sha256' => $a['sha256'],
            ], array_keys($golden['attachments']), $golden['attachments']),
        ];

        $this->assertSame($expected, $parsed);
    }

    public function test_cc_and_bcc_are_listed_without_the_invalid_address_and_the_envelope_last(): void
    {
        $parsed = $this->parser()->parse(LargeMessage::fixture('cc-bcc'), 'alice@example.org')->toArray(false);

        $this->assertSame([
            ['address' => 'alice@example.org', 'source' => 'to'],
            ['address' => 'bob@example.org', 'source' => 'to'],
            ['address' => 'carol@example.org', 'source' => 'cc'],
            ['address' => 'dave@example.net', 'source' => 'bcc'],
            ['address' => 'alice@example.org', 'source' => 'envelope'],
        ], $parsed['recipients']);
        $this->assertNull($parsed['attachments']);
    }

    public function test_the_unparseable_message_falls_back_to_the_raw_body_as_text(): void
    {
        $parsed = $this->parser()->parse(LargeMessage::fixture('unparseable'), 'alice@example.org');

        $this->assertTrue($parsed->parserFallback);
        $this->assertNull($parsed->textPlain);
        $this->assertStringContainsString('Claim your prize', $parsed->textFromHtml);
    }

    public function test_no_html_tag_appears_in_any_text(): void
    {
        foreach (array_keys(self::fixtures()) as $name) {
            $parsed = $this->parser()->parse(LargeMessage::fixture($name), 'alice@example.org');

            $this->assertDoesNotMatchRegularExpression('/<\s*(script|html|body|svg|img|a|p|h1)\b/i', $parsed->textFromHtml, $name);
            $this->assertDoesNotMatchRegularExpression('/<\s*(script|html|body|svg|img)\b/i', (string) $parsed->textPlain, $name);
        }
    }

    public function test_invalid_utf8_is_scrubbed(): void
    {
        $message = "From: a@example.net\r\nSubject: Bad\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nbroken \xC3\x28 text\r\n";

        $parsed = $this->parser()->parse($message, 'alice@example.org')->toArray(true);

        $this->assertNotFalse(json_encode($parsed));
        $this->assertStringContainsString('broken', (string) $parsed['textPlain']);
    }

    public function test_attachments_are_kept_in_memory_and_their_content_is_available(): void
    {
        $before = glob(sys_get_temp_dir().'/*') ?: [];

        $parsed = $this->parser()->parse(LargeMessage::fixture('multipart-two-attachments'), 'alice@example.org');

        $this->assertSame($before, glob(sys_get_temp_dir().'/*') ?: []);
        $this->assertCount(2, $parsed->attachments);
        $this->assertStringStartsWith('%PDF-1.4', $parsed->attachments[0]->content);
        $this->assertSame(hash('sha256', $parsed->attachments[1]->content), $parsed->attachments[1]->sha256);
        $this->assertSame(strlen($parsed->attachments[1]->content), $parsed->attachments[1]->size);
    }

    public function test_a_message_without_from_and_subject_has_empty_strings(): void
    {
        $parsed = $this->parser()->parse("Content-Type: text/plain\r\n\r\nHi\r\n", 'alice@example.org');

        $this->assertSame('', $parsed->from);
        $this->assertSame('', $parsed->subject);
    }
}
