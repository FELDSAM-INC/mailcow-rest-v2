<?php

namespace Tests\Unit\SyncJobs;

use App\Rules\SyncJobHostName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../fixtures/legacy/syncjob_validation.php';

/**
 * Feature 008 T032 (research R6, clarification 4): the remote host follows legacy
 * is_valid_domain_name() exactly (002's port, App\Support\Domain\DomainName): IPv4 literals pass,
 * IPv6 literals do not. A refusal is `invalidFormat` with `format: hostName`.
 */
class HostNameRuleTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function corpus(): array
    {
        $label63 = str_repeat('a', 63);
        $values = [
            'imap.example.com', 'localhost', '192.0.2.10', 'mäil.example.de', '-bad.example', 'a..b', '2001:db8::1',
            '[2001:db8::1]', $label63.'.'.$label63.'.'.$label63.'.'.str_repeat('b', 61), $label63.'.'.$label63.'.'.$label63.'.'.str_repeat('b', 62),
            $label63.'.example', str_repeat('a', 64).'.example', 'trailing.dot.', 'under_score.example', 'IMAP.Example.COM',
            'a', '0', '1', 'a-b.example', 'a--b.example', 'bad-.example', 'exa mple.com', 'example.com:993',
            '256.256.256.256', 'xn--mil-moa.example.de', 'imap.example.com/', '*.example.com', '', '.example.com',
        ];

        return array_combine(array_map(fn (string $v): string => json_encode($v) ?: $v, $values), array_map(fn (string $v): array => [$v], $values));
    }

    #[DataProvider('corpus')]
    public function test_the_rule_agrees_with_legacy(string $value): void
    {
        $failures = [];
        (new SyncJobHostName)->validate('remoteHost', $value, function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        $this->assertSame((bool) legacy_syncjob_is_valid_domain_name($value), $failures === []);
    }

    public function test_a_refusal_is_invalid_format_host_name(): void
    {
        $rule = new SyncJobHostName;
        $rule->validate('remoteHost', '2001:db8::1', fn (string $message) => null);

        $this->assertSame('invalidFormat', $rule->violationFor('remoteHost')['code'] ?? null);
        $this->assertSame(['format' => 'hostName'], $rule->violationFor('remoteHost')['params'] ?? null);
    }
}
