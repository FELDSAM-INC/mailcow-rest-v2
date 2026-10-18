<?php

namespace App\Clients\Dns;

/**
 * DNS lookups of feature 009 (research R15), shared with later features (010 builds on it): an
 * empty list means the name has no such records (NXDOMAIN or NODATA); a temporary failure throws
 * DnsTemporaryFailure. Faked in tests (Tests\Support\Dns\FakeDnsResolver).
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
}
