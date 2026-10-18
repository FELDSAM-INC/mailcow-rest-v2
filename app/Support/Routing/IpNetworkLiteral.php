<?php

namespace App\Support\Routing;

/**
 * An IPv4 or IPv6 address or prefix as a forwarding host (feature 009 FR-077, research R16). The
 * canonical form is inet_ntop(inet_pton()) (lower-case, compressed IPv6), and for a prefix the
 * network address with the host bits cleared plus `/prefix`; a single address has no prefix, as
 * legacy stores it. Rspamd's keep-spam check matches only prefixes written with their network
 * address, so new entries are stored canonically.
 */
final readonly class IpNetworkLiteral
{
    private function __construct(
        private string $packed,
        private ?int $prefix,
    ) {}

    /**
     * Whether the input is meant as an address literal rather than a host name: it contains `/`
     * or `:`, or consists of digits and dots only. `cafe` and `example.org` are names.
     */
    public static function isLiteralLike(string $value): bool
    {
        return str_contains($value, '/') || str_contains($value, ':') || preg_match('/^[0-9.]+$/', $value) === 1;
    }

    /** The literal, or null when the value is not a valid address or prefix. */
    public static function parse(string $value): ?self
    {
        [$address, $prefix] = array_pad(explode('/', trim($value), 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton((string) $address);

        if ($packed === false) {
            return null;
        }

        $bits = strlen($packed) * 8;

        if ($prefix === null) {
            return new self($packed, null);
        }

        if (preg_match('/^(0|[1-9][0-9]{0,2})$/', $prefix) !== 1 || (int) $prefix > $bits) {
            return null;
        }

        return new self(self::network($packed, (int) $prefix), (int) $prefix);
    }

    public function canonical(): string
    {
        $address = (string) inet_ntop($this->packed);

        return $this->prefix === null ? $address : $address.'/'.$this->prefix;
    }

    public function isIpv6(): bool
    {
        return strlen($this->packed) === 16;
    }

    public function equals(self $other): bool
    {
        return $this->canonical() === $other->canonical();
    }

    /**
     * The natural order: IPv4 before IPv6, then numerically by address, then by prefix length (a
     * single address counts as the full length).
     */
    public function sortKey(): string
    {
        $bits = strlen($this->packed) * 8;

        return ($this->isIpv6() ? '1' : '0').bin2hex($this->packed).sprintf('%03d', $this->prefix ?? $bits);
    }

    /**
     * The sort key of a stored value: its literal's, or after every address (sorted by the text)
     * when a legacy entry does not parse.
     */
    public static function sortKeyOf(string $value): string
    {
        return self::parse($value)?->sortKey() ?? '2'.$value;
    }

    private static function network(string $packed, int $prefix): string
    {
        $result = '';

        foreach (str_split($packed) as $i => $byte) {
            $keep = max(0, min(8, $prefix - $i * 8));
            $mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
            $result .= chr(ord($byte) & $mask);
        }

        return $result;
    }
}
