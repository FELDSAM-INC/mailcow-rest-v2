<?php

namespace Tests\Unit\Fail2Ban;

use App\Rules\Fail2BanAllowlistEntry;
use App\Rules\Fail2BanDenylistEntry;
use App\Support\Network\IpNetwork;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature 011 T072 (FR-047, research R19): allowlist and denylist entries, ported from legacy
 * valid_network() and valid_hostname(), with the denylist's protected networks.
 */
class BanListEntryRulesTest extends TestCase
{
    /**
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    private static function violation(object $rule, string $value): ?array
    {
        $failed = false;
        $rule->validate('entry', $value, function () use (&$failed): void {
            $failed = true;
        });

        return $failed ? $rule->violationFor('entry') : null;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validAllowlistEntries(): array
    {
        return ['address' => ['192.0.2.1'], 'ipv6 network' => ['2001:db8::/32'], 'host bits set' => ['10.0.0.5/24'], 'host name' => ['mail.example.org'], 'trimmed' => ['  192.0.2.1 ']];
    }

    #[DataProvider('validAllowlistEntries')]
    public function test_valid_allowlist_entries(string $entry): void
    {
        $this->assertNull(self::violation(new Fail2BanAllowlistEntry, $entry));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAllowlistEntries(): array
    {
        return ['text' => ['not a host!'], 'prefix too long' => ['10.0.0.0/33'], 'negative prefix' => ['10.0.0.0/-1'], 'text prefix' => ['10.0.0.0/x'], 'ipv6 prefix too long' => ['2001:db8::/129']];
    }

    #[DataProvider('invalidAllowlistEntries')]
    public function test_invalid_allowlist_entries(string $entry): void
    {
        $violation = self::violation(new Fail2BanAllowlistEntry, $entry);

        $this->assertSame('invalidFormat', $violation['code'] ?? null);
        $this->assertSame('ipNetworkOrHostname', $violation['params']['format'] ?? null);
    }

    public function test_the_denylist_refuses_host_names(): void
    {
        $violation = self::violation(new Fail2BanDenylistEntry, 'mail.example.org');

        $this->assertSame('invalidFormat', $violation['code'] ?? null);
        $this->assertSame('ipNetwork', $violation['params']['format'] ?? null);
        $this->assertNull(self::violation(new Fail2BanDenylistEntry, ' 203.0.113.0/24 '));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reservedNetworks(): array
    {
        return [
            'any address' => ['0.0.0.0'],
            'everything v4' => ['0.0.0.0/0'],
            'everything v4 padded prefix' => ['0.0.0.0/00'],
            'everything v6' => ['::/0'],
            'internal network' => ['172.22.1.0/24'],
            'internal network address' => ['172.22.1.0'],
            'internal v6 network' => ['fd4d:6169:6c63:6f77::/64'],
            'internal v6 network uncompressed' => ['FD4D:6169:6C63:6F77:0:0:0:0/64'],
        ];
    }

    #[DataProvider('reservedNetworks')]
    public function test_the_denylist_refuses_reserved_networks(string $entry): void
    {
        $violation = self::violation(new Fail2BanDenylistEntry, $entry);

        $this->assertSame('reserved', $violation['code'] ?? null);
        $this->assertContains('0.0.0.0/0', $violation['params']['reserved'] ?? []);
    }

    public function test_canonical_forms_and_sorting(): void
    {
        $this->assertSame('2001:db8::/32', IpNetwork::canonical('2001:DB8:0::/032'));
        $this->assertSame('mail.example.org', IpNetwork::canonical('Mail.Example.org'));

        $sorted = IpNetwork::sort(['2001:db8::/64', '192.0.2.0/24', '10.0.0.1', '192.0.2.0/25', '10.0.0.0/8']);
        $this->assertSame(['10.0.0.0/8', '10.0.0.1', '192.0.2.0/24', '192.0.2.0/25', '2001:db8::/64'], $sorted);
    }
}
