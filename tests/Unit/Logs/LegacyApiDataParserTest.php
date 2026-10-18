<?php

namespace Tests\Unit\Logs;

use App\Services\Logs\LegacyApiDataParser;
use PHPUnit\Framework\TestCase;
use Tests\Support\Redis\MailcowLogs;

/**
 * Feature 011 T044 (FR-028, research R15): legacy `api_log()` data (`name='<json>', …`) back into a
 * structure.
 */
class LegacyApiDataParserTest extends TestCase
{
    public function test_fields_are_parsed_into_a_structure(): void
    {
        $data = (new LegacyApiDataParser)->parse('attr=\'{"password":"x","dkim":{"private_key_file":"-----BEGIN"}}\', items=\'["a"]\'');

        $this->assertSame(['attr' => ['password' => 'x', 'dkim' => ['private_key_file' => '-----BEGIN']], 'items' => ['a']], $data);
    }

    public function test_a_separator_inside_a_json_string_is_not_a_field_boundary(): void
    {
        $data = (new LegacyApiDataParser)->parse('attr=\'{"description":"a\', x=\'b"}\', items=\'["c"]\'');

        $this->assertSame(['attr' => ['description' => "a', x='b"], 'items' => ['c']], $data);
    }

    public function test_the_legacy_producer_format(): void
    {
        $line = MailcowLogs::apiLogData(['attr' => ['domain' => 'example.org', 'password' => 'Secret1!', 'description' => 'O\'Reilly / Co'], 'items' => ['example.org']]);

        $data = (new LegacyApiDataParser)->parse($line);

        $this->assertSame(['attr' => ['domain' => 'example.org', 'password' => '*', 'description' => 'O\'Reilly / Co'], 'items' => ['example.org']], $data);
    }

    public function test_an_empty_value_is_null(): void
    {
        $this->assertSame(['attr' => null, 'items' => [1]], (new LegacyApiDataParser)->parse("attr='', items='[1]'"));
    }

    public function test_an_empty_string_is_an_empty_structure(): void
    {
        $this->assertSame([], (new LegacyApiDataParser)->parse(''));
    }

    public function test_an_unsplittable_string_is_null(): void
    {
        $this->assertNull((new LegacyApiDataParser)->parse('attr={"password":"x"}'));
        $this->assertNull((new LegacyApiDataParser)->parse("attr='{not json', items='[1]'"));
    }
}
