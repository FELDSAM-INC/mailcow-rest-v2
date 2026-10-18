<?php

namespace Tests\Unit\Clients;

use App\Clients\Dns\DnsTemporaryFailure;
use App\Clients\Dns\NativeDnsResolver;
use PHPUnit\Framework\TestCase;

/**
 * Feature 009 T014 (research R15): the dns_get_record() wrapper tells "no records" (an empty list)
 * from a temporary failure (false plus a resolver warning), concatenates the character strings of
 * TXT records, keeps MX preferences, and never lets a PHP warning through.
 */
class NativeDnsResolverTest extends TestCase
{
    /** @var list<array{string, int}> */
    private array $calls = [];

    /**
     * @param  array<string, array<int, mixed>|false>  $answers  "name|type" => records, or false
     * @param  array<string, string>  $warnings  "name|type" => warning text raised before returning
     */
    private function resolver(array $answers, array $warnings = []): NativeDnsResolver
    {
        return new NativeDnsResolver(function (string $name, int $type) use ($answers, $warnings): array|false {
            $this->calls[] = [$name, $type];
            $key = $name.'|'.$type;

            if (isset($warnings[$key])) {
                trigger_error('dns_get_record(): '.$warnings[$key], E_USER_WARNING);
            }

            return $answers[$key] ?? [];
        });
    }

    public function test_no_records_is_an_empty_list(): void
    {
        $resolver = $this->resolver([]);

        $this->assertSame([], $resolver->a('nothing.example'));
        $this->assertSame([], $resolver->aaaa('nothing.example'));
        $this->assertSame([], $resolver->mx('nothing.example'));
        $this->assertSame([], $resolver->txt('nothing.example'));
        $this->assertSame([['nothing.example', DNS_A], ['nothing.example', DNS_AAAA], ['nothing.example', DNS_MX], ['nothing.example', DNS_TXT]], $this->calls);
    }

    public function test_a_temporary_server_error_is_a_temporary_failure(): void
    {
        $resolver = $this->resolver(['broken.example|'.DNS_MX => false], ['broken.example|'.DNS_MX => 'A temporary server error occurred.']);
        $handlerCalls = 0;
        set_error_handler(function () use (&$handlerCalls): bool {
            $handlerCalls++;

            return true;
        });

        try {
            $resolver->mx('broken.example');
            $this->fail('No failure was raised.');
        } catch (DnsTemporaryFailure $e) {
            $this->assertStringContainsString('broken.example', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        $this->assertSame(0, $handlerCalls, 'The PHP warning reached the outer error handler.');
    }

    public function test_false_with_another_warning_is_a_temporary_failure(): void
    {
        $resolver = $this->resolver(['broken.example|'.DNS_A => false], ['broken.example|'.DNS_A => 'Dns Query failed']);

        $this->expectException(DnsTemporaryFailure::class);
        $resolver->a('broken.example');
    }

    public function test_false_without_a_warning_is_a_temporary_failure(): void
    {
        $resolver = $this->resolver(['broken.example|'.DNS_TXT => false]);

        $this->expectException(DnsTemporaryFailure::class);
        $resolver->txt('broken.example');
    }

    public function test_txt_records_are_concatenated(): void
    {
        $resolver = $this->resolver(['forwarder.example|'.DNS_TXT => [
            ['host' => 'forwarder.example', 'type' => 'TXT', 'txt' => 'v=spf1 ip4:198.51.100.0/24 include:other.example -all', 'entries' => ['v=spf1 ip4:198.51.100.0/24 ', 'include:other.example -all']],
            ['host' => 'forwarder.example', 'type' => 'TXT', 'txt' => 'google-site-verification=x'],
        ]]);

        $this->assertSame(['v=spf1 ip4:198.51.100.0/24 include:other.example -all', 'google-site-verification=x'], $resolver->txt('forwarder.example'));
    }

    public function test_mx_records_keep_their_preference_and_order(): void
    {
        $resolver = $this->resolver(['partner.net|'.DNS_MX => [
            ['host' => 'partner.net', 'type' => 'MX', 'pri' => 20, 'target' => 'mx2.partner.net'],
            ['host' => 'partner.net', 'type' => 'MX', 'pri' => 10, 'target' => 'MX1.partner.net.'],
        ]]);

        $this->assertSame([['host' => 'mx2.partner.net', 'preference' => 20], ['host' => 'mx1.partner.net', 'preference' => 10]], $resolver->mx('partner.net'));
    }

    public function test_address_records_are_returned_as_text(): void
    {
        $resolver = $this->resolver([
            'host.example|'.DNS_A => [['host' => 'host.example', 'type' => 'A', 'ip' => '192.0.2.7'], ['type' => 'CNAME', 'target' => 'x']],
            'host.example|'.DNS_AAAA => [['host' => 'host.example', 'type' => 'AAAA', 'ipv6' => '2001:db8::7']],
        ]);

        $this->assertSame(['192.0.2.7'], $resolver->a('host.example'));
        $this->assertSame(['2001:db8::7'], $resolver->aaaa('host.example'));
    }
}
