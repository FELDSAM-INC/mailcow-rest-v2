<?php

namespace Tests\Unit\Rules;

use App\Rules\AppLinkUrl;
use App\Rules\CharacterLength;
use App\Rules\CodedRule;
use App\Rules\EmailAddress;
use App\Rules\HexColor;
use App\Rules\LegacyOrigin;
use App\Rules\SingleLineText;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Feature 013 T012 (data-model.md sections 1 and 5): the rule objects of the system settings report
 * the contract's rule codes and parameters. The e-mail and byte-length rules are the existing
 * EmailAddress (with control characters refused) and CharacterLength (bytes) of earlier features.
 */
class SettingsRulesTest extends TestCase
{
    /**
     * @param  array<string, mixed>|null  $expected  code and params, or null when valid
     */
    private function assertRule(CodedRule $rule, mixed $value, ?array $expected, string $attribute = 'field'): void
    {
        $failed = false;
        $rule->validate($attribute, $value, function (string $message) use (&$failed): PotentiallyTranslatedString {
            $failed = true;

            return new PotentiallyTranslatedString($message, new Translator(new ArrayLoader, 'en'));
        });

        if ($expected === null) {
            $this->assertFalse($failed, 'expected '.json_encode($value).' to be valid');

            return;
        }

        $this->assertTrue($failed, 'expected '.json_encode($value).' to be refused');
        $violation = $rule->violationFor($attribute);
        $this->assertNotNull($violation);
        $this->assertSame($expected, ['code' => $violation['code'], 'params' => $violation['params']]);
        $this->assertNotSame('', $violation['message']);
    }

    /**
     * @return iterable<string, array{mixed, array<string, mixed>|null}>
     */
    public static function singleLineTexts(): iterable
    {
        $invalid = ['code' => 'invalidFormat', 'params' => ['format' => 'singleLine']];

        yield 'plain' => ['Acme & Co Mail', null];
        yield 'unicode' => ['Pošta Žluťoučký kůň', null];
        yield 'empty' => ['', null];
        yield 'line feed' => ["Acme\nMail", $invalid];
        yield 'carriage return' => ["Acme\rMail", $invalid];
        yield 'tab' => ["Acme\tMail", $invalid];
        yield 'nul' => ["Acme\0", $invalid];
        yield 'delete' => ["Acme\x7F", $invalid];
    }

    /**
     * @param  array<string, mixed>|null  $expected
     */
    #[DataProvider('singleLineTexts')]
    public function test_single_line_text(mixed $value, ?array $expected): void
    {
        $this->assertRule(new SingleLineText, $value, $expected);
    }

    /**
     * @return iterable<string, array{mixed, array<string, mixed>|null}>
     */
    public static function emails(): iterable
    {
        $invalid = ['code' => 'invalidFormat', 'params' => ['format' => 'email']];

        yield 'address' => ['quota@example.org', null];
        yield 'empty allowed' => ['', null];
        yield 'missing domain' => ['no-reply@', $invalid];
        yield 'no at' => ['not-an-address', $invalid];
        yield 'quoted control character' => ["\"a\x01b\"@example.org", $invalid];
        yield 'line break' => ["a@example.org\n", $invalid];
    }

    /**
     * @param  array<string, mixed>|null  $expected
     */
    #[DataProvider('emails')]
    public function test_email_without_control_characters(mixed $value, ?array $expected): void
    {
        $this->assertRule(new EmailAddress(allowEmpty: true, rejectControlCharacters: true), $value, $expected);
    }

    public function test_byte_length_counts_utf8_bytes(): void
    {
        $rule = new CharacterLength(0, 4, bytes: true);

        $this->assertRule($rule, 'abcd', null);
        $this->assertRule($rule, 'ab€', ['code' => 'tooLong', 'params' => ['max' => 4, 'unit' => 'bytes']]);
    }

    /**
     * @return iterable<string, array{mixed, array<string, mixed>|null}>
     */
    public static function appLinkUrls(): iterable
    {
        $invalid = ['code' => 'invalidFormat', 'params' => ['format' => 'appLinkUrl']];

        yield 'https with path' => ['https://help.example.org/x', null];
        yield 'http host' => ['http://help.example.org', null];
        yield 'path' => ['/path', null];
        yield 'path with placeholder' => ['/path?u=%u', null];
        yield 'url with placeholder' => ['https://help.example.org/?u=%u', null];
        yield 'scheme relative' => ['//evil.example.net', $invalid];
        yield 'backslash path' => ['/\\evil.example.net', $invalid];
        yield 'javascript' => ['javascript:alert(1)', $invalid];
        yield 'ftp' => ['ftp://files.example.org', $invalid];
        yield 'data' => ['data:text/html,<b>x</b>', $invalid];
        yield 'mailto' => ['mailto:it@example.org', $invalid];
        yield 'whitespace' => ['https://help.example.org/a b', $invalid];
        yield 'no host' => ['https:///x', $invalid];
        yield 'relative' => ['help', $invalid];
        yield 'too long' => ['https://help.example.org/'.str_repeat('a', 2048), ['code' => 'tooLong', 'params' => ['max' => 2048]]];
    }

    /**
     * @param  array<string, mixed>|null  $expected
     */
    #[DataProvider('appLinkUrls')]
    public function test_app_link_url(mixed $value, ?array $expected): void
    {
        $this->assertRule(new AppLinkUrl, $value, $expected);
    }

    /**
     * @return iterable<string, array{mixed, array<string, mixed>|null}>
     */
    public static function hexColors(): iterable
    {
        $invalid = ['code' => 'invalidFormat', 'params' => ['format' => 'hexColor']];

        yield 'lower case' => ['#1d4ed8', null];
        yield 'upper case' => ['#1D4ED8', null];
        yield 'short form' => ['#fff', $invalid];
        yield 'no hash' => ['1d4ed8', $invalid];
        yield 'not hexadecimal' => ['#gggggg', $invalid];
        yield 'alpha' => ['#1d4ed8ff', $invalid];
    }

    /**
     * @param  array<string, mixed>|null  $expected
     */
    #[DataProvider('hexColors')]
    public function test_hex_color(mixed $value, ?array $expected): void
    {
        $this->assertRule(new HexColor, $value, $expected);
    }

    /**
     * @return iterable<string, array{mixed, array<string, mixed>|null}>
     */
    public static function origins(): iterable
    {
        $invalid = ['code' => 'invalidFormat', 'params' => ['format' => 'origin']];

        yield 'https host' => ['https://portal.example.org', null];
        yield 'upper case' => ['https://Portal.Example.org', null];
        yield 'ipv6 with port' => ['http://[2001:db8::1]:8080', null];
        yield 'ipv4' => ['http://192.0.2.10', null];
        yield 'wildcard' => ['*', null];
        yield 'path' => ['https://portal.example.org/app/', $invalid];
        yield 'trailing slash' => ['https://portal.example.org/', $invalid];
        yield 'credentials' => ['https://u:p@portal.example.org', $invalid];
        yield 'ftp' => ['ftp://portal.example.org', $invalid];
        yield 'bare host' => ['portal.example.org', $invalid];
        yield 'query' => ['https://portal.example.org/?q', $invalid];
        yield 'fragment' => ['https://portal.example.org#f', $invalid];
        yield 'invalid host' => ['https://por_tal..example', $invalid];
    }

    /**
     * @param  array<string, mixed>|null  $expected
     */
    #[DataProvider('origins')]
    public function test_legacy_origin(mixed $value, ?array $expected): void
    {
        $this->assertRule(new LegacyOrigin, $value, $expected);
    }
}
