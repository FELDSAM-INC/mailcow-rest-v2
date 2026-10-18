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

    /** @var list<bool> raw mode of each call (feature 010: TLSA) */
    private array $raw = [];

    /**
     * @param  array<string, array<int, mixed>|false>  $answers  "name|type" => records, or false
     * @param  array<string, string>  $warnings  "name|type" => warning text raised before returning
     */
    private function resolver(array $answers, array $warnings = []): NativeDnsResolver
    {
        return new NativeDnsResolver(function (string $name, int $type, bool $raw = false) use ($answers, $warnings): array|false {
            $this->calls[] = [$name, $type];
            $this->raw[] = $raw;
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

    public function test_cname_records_are_host_names(): void
    {
        $resolver = $this->resolver(['autodiscover.example.org|'.DNS_CNAME => [['host' => 'autodiscover.example.org', 'type' => 'CNAME', 'target' => 'MAIL.example.org.']]]);

        $this->assertSame(['mail.example.org'], $resolver->cname('autodiscover.example.org'));
        $this->assertSame([], $resolver->cname('none.example.org'));
    }

    public function test_srv_records_keep_target_port_priority_and_weight_and_a_null_record_is_a_dot(): void
    {
        $resolver = $this->resolver([
            '_imaps._tcp.example.org|'.DNS_SRV => [['host' => '_imaps._tcp.example.org', 'type' => 'SRV', 'pri' => 5, 'weight' => 2, 'port' => 993, 'target' => 'Mail.example.org']],
            '_pop3._tcp.example.org|'.DNS_SRV => [['host' => '_pop3._tcp.example.org', 'type' => 'SRV', 'pri' => 0, 'weight' => 0, 'port' => 0, 'target' => '']],
        ]);

        $this->assertSame([['target' => 'mail.example.org', 'port' => 993, 'priority' => 5, 'weight' => 2]], $resolver->srv('_imaps._tcp.example.org'));
        $this->assertSame([['target' => '.', 'port' => 0, 'priority' => 0, 'weight' => 0]], $resolver->srv('_pop3._tcp.example.org'));
    }

    public function test_ptr_records_are_host_names(): void
    {
        $resolver = $this->resolver(['10.2.0.192.in-addr.arpa|'.DNS_PTR => [['host' => '10.2.0.192.in-addr.arpa', 'type' => 'PTR', 'target' => 'mail.example.org.']]]);

        $this->assertSame(['mail.example.org'], $resolver->ptr('10.2.0.192.in-addr.arpa'));
    }

    public function test_tlsa_records_are_decoded_from_raw_mode_as_legacy_does(): void
    {
        $data = chr(3).chr(1).chr(1).hex2bin('ABCDEF0123456789');
        $resolver = $this->resolver(['_25._tcp.mail.example.org|52' => [['host' => '_25._tcp.mail.example.org', 'type' => 52, 'data' => $data], ['host' => 'x', 'type' => 52, 'data' => 'ab']]]);

        $this->assertSame(['3 1 1 abcdef0123456789'], $resolver->tlsa('_25._tcp.mail.example.org'));
        $this->assertSame([['_25._tcp.mail.example.org', 52]], $this->calls);
        $this->assertSame([true], $this->raw);
    }

    public function test_the_new_lookups_report_temporary_failures_without_a_php_warning(): void
    {
        foreach (['cname' => DNS_CNAME, 'srv' => DNS_SRV, 'ptr' => DNS_PTR, 'tlsa' => 52] as $method => $type) {
            $resolver = $this->resolver(['broken.example|'.$type => false], ['broken.example|'.$type => 'A temporary server error occurred.']);

            try {
                $resolver->{$method}('broken.example');
                $this->fail("No failure was raised by {$method}().");
            } catch (DnsTemporaryFailure $e) {
                $this->assertStringContainsString('broken.example', $e->getMessage());
            }
        }
    }
}
