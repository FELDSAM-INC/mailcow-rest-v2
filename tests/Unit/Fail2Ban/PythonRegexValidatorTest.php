<?php

namespace Tests\Unit\Fail2Ban;

use App\Support\Regex\PythonRegexValidator;
use RuntimeException;
use Tests\Support\Redis\MailcowLogs;
use Tests\TestCase;

/**
 * Feature 011 T073 (FR-050, research R20): ban rules compile in Python `re`, the ban service's
 * dialect, with a real python3.
 */
class PythonRegexValidatorTest extends TestCase
{
    public function test_netfilter_default_rules_pass(): void
    {
        $results = (new PythonRegexValidator)->validate(MailcowLogs::defaultRules());

        $this->assertCount(9, $results);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], (string) $result['error']);
            $this->assertGreaterThanOrEqual(1, $result['groups']);
        }
    }

    public function test_python_and_pcre_differences(): void
    {
        [$named, $pcreNamed, $broken, $noGroup] = (new PythonRegexValidator)->validate(['(?P<ip>[0-9.]+)', '(?<ip>x)', '[a-', 'foo bar']);

        $this->assertTrue($named['ok']);
        $this->assertSame(1, $named['groups']);
        $this->assertFalse($pcreNamed['ok'], 'Python 3.12 rejects PCRE named groups without P');
        $this->assertNotSame('', (string) $pcreNamed['error']);
        $this->assertFalse($broken['ok']);
        $this->assertStringContainsString('unterminated character set', (string) $broken['error']);
        $this->assertTrue($noGroup['ok']);
        $this->assertSame(0, $noGroup['groups']);
    }

    public function test_a_missing_interpreter_throws(): void
    {
        config(['restv2.monitoring.python' => '/nonexistent/python3']);

        $this->expectException(RuntimeException::class);
        (new PythonRegexValidator)->validate(['a(b)']);
    }
}
