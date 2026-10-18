<?php

namespace App\Clients\Dns;

/**
 * DNS lookups of feature 009 (research R15), shared with later features: an empty list means the
 * name has no such records (NXDOMAIN or NODATA); a temporary failure throws DnsTemporaryFailure.
 * Feature 010 (research R16) adds CNAME, SRV, PTR and TLSA for DNS diagnostics. Faked in tests
 * (Tests\Support\Dns\FakeDnsResolver).
 */
interface DnsResolver
{
    /**
     * TXT records, each with its character strings concatenated (RFC 7208 §3.3).
     *
     * @return list<string>
     *
     * @throws DnsTemporaryFailure
     */
    public function txt(string $name): array;

    /**
     * MX records in the order the resolver returned them, host names lower-cased without the
     * trailing dot.
     *
     * @return list<array{host: string, preference: int}>
     *
     * @throws DnsTemporaryFailure
     */
    public function mx(string $name): array;

    /**
     * @return list<string> IPv4 addresses
     *
     * @throws DnsTemporaryFailure
     */
    public function a(string $name): array;

    /**
     * @return list<string> IPv6 addresses
     *
     * @throws DnsTemporaryFailure
     */
    public function aaaa(string $name): array;

    /**
     * CNAME targets, lower-cased without the trailing dot (feature 010).
     *
     * @return list<string>
     *
     * @throws DnsTemporaryFailure
     */
    public function cname(string $name): array;

    /**
     * SRV records, targets lower-cased without the trailing dot; a null record (the root) has the
     * target `.` (feature 010).
     *
     * @return list<array{target: string, port: int, priority: int, weight: int}>
     *
     * @throws DnsTemporaryFailure
     */
    public function srv(string $name): array;

    /**
     * PTR targets, lower-cased without the trailing dot (feature 010).
     *
     * @return list<string>
     *
     * @throws DnsTemporaryFailure
     */
    public function ptr(string $name): array;

    /**
     * TLSA records as `<usage> <selector> <matching type> <hex data>` (feature 010, legacy
     * dns_diagnostics.php's decoding of the raw record).
     *
     * @return list<string>
     *
     * @throws DnsTemporaryFailure
     */
    public function tlsa(string $name): array;
}
