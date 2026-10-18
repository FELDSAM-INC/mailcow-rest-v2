<?php

namespace App\Support\Routing;

use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Support\Domain\DomainName;
use InvalidArgumentException;

/**
 * A next hop of the mail server (feature 009 FR-019, research R5): a relayhost host, a transport
 * next hop, or the bracketed form of a TLS policy destination. Accepted forms are `host`,
 * `host:port`, `[host]` and `[host]:port`:
 *
 *  - outside brackets, `host` is a domain name (002's DomainName: trimmed, lower-cased, UTS #46 to
 *    ASCII, legacy's is_valid_domain_name(), single labels allowed);
 *  - inside brackets, a domain name, an IPv4 address, or an IPv6 address optionally prefixed with
 *    `ipv6:`;
 *  - a bare IPv4 address (with or without a port) or a bare IPv6 address (never with a port) is
 *    stored in brackets, as legacy does for transport next hops;
 *  - the port is decimal, 1 to 65535, without sign or leading zero.
 *
 * IP literals keep their spelling, lower-cased: legacy stores them as typed and its interference
 * check compares strings.
 */
final readonly class NextHop
{
    public const MAX_LENGTH = 255;

    private function __construct(
        private string $stored,
        private bool $bracketed,
        private string $inner,
        private string $host,
        private ?int $port,
    ) {}

    /**
     * @throws InvalidArgumentException when the value is not a valid next hop
     */
    public static function parse(string $raw): self
    {
        return self::tryParse($raw) ?? throw new InvalidArgumentException('Not a valid next hop.');
    }

    public static function tryParse(string $raw): ?self
    {
        $value = trim($raw);

        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        $hop = str_starts_with($value, '[') ? self::bracketedForm($value) : self::bareForm($value);

        return $hop !== null && strlen($hop->stored) <= self::MAX_LENGTH ? $hop : null;
    }

    /**
     * The violation of a submitted value (`invalidType`, `required`, `tooLong` with `max: 255`,
     * `invalidFormat` with `format: nextHop`), or null when it is valid.
     *
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    public static function violation(mixed $raw): ?array
    {
        if (! is_string($raw)) {
            return ApiFormRequest::make('invalidType', ['expected' => 'string']);
        }

        $value = trim($raw);

        if ($value === '') {
            return ApiFormRequest::make('required', []);
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            return ApiFormRequest::make('tooLong', ['max' => self::MAX_LENGTH]);
        }

        $hop = self::tryParse($value);

        if ($hop === null) {
            return ApiFormRequest::make('invalidFormat', ['format' => 'nextHop']);
        }

        return null;
    }

    /** The string to store. */
    public function stored(): string
    {
        return $this->stored;
    }

    /** Whether the next hop is bracketed, which turns off the mail exchanger lookup. */
    public function bracketed(): bool
    {
        return $this->bracketed;
    }

    /** The host: the part inside the brackets without an `ipv6:` prefix, or the host part. */
    public function host(): string
    {
        return $this->host;
    }

    public function port(): ?int
    {
        return $this->port;
    }

    /**
     * Legacy's `next_hop_clean`: the text inside the brackets of a bracketed next hop, otherwise
     * the whole next hop (with its port). Used by the interference check (FR-037).
     */
    public function cleanHost(): string
    {
        return $this->bracketed ? $this->inner : $this->stored;
    }

    private static function bracketedForm(string $value): ?self
    {
        if (preg_match('/^\[([^\[\]]+)\](?::([^:]*))?$/', $value, $m) !== 1) {
            return null;
        }

        $port = isset($m[2]) ? self::parsePort($m[2]) : null;

        if (isset($m[2]) && $port === null) {
            return null;
        }

        $inner = strtolower($m[1]);

        if (str_starts_with($inner, 'ipv6:')) {
            $address = substr($inner, 5);

            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }

            return self::inBrackets($inner, $address, $port);
        }

        if (filter_var($inner, FILTER_VALIDATE_IP) !== false) {
            return self::inBrackets($inner, $inner, $port);
        }

        $domain = DomainName::normalizeValid($m[1]);

        return $domain === null ? null : self::inBrackets($domain, $domain, $port);
    }

    private static function bareForm(string $value): ?self
    {
        if (str_contains($value, ':') && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $address = strtolower($value);

            return self::inBrackets($address, $address, null);
        }

        $parts = explode(':', $value);

        if (count($parts) > 2) {
            return null;
        }

        $port = null;

        if (count($parts) === 2) {
            $port = self::parsePort($parts[1]);

            if ($port === null) {
                return null;
            }
        }

        if (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::inBrackets($parts[0], $parts[0], $port);
        }

        $domain = DomainName::normalizeValid($parts[0]);

        if ($domain === null) {
            return null;
        }

        return new self($port === null ? $domain : $domain.':'.$port, false, $domain, $domain, $port);
    }

    private static function inBrackets(string $inner, string $host, ?int $port): self
    {
        return new self('['.$inner.']'.($port === null ? '' : ':'.$port), true, $inner, $host, $port);
    }

    private static function parsePort(string $value): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,4}$/', $value) !== 1 || (int) $value > 65535) {
            return null;
        }

        return (int) $value;
    }
}
