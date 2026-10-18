<?php

namespace App\Clients\Oidc;

use RuntimeException;

/**
 * A provider call that did not succeed (research R1, R11):
 *
 *  - `refused`: the provider answered and refused (an OAuth error, a 4xx, a redirect or an answer
 *    that is not a token or user details); `status` and `error` (the OAuth error code) when known;
 *  - `unavailable`: no answer (connection or TLS failure, time-out; `curlErrno`) or a server error
 *    (5xx; `status`).
 *
 * Its message never contains a secret or a response body.
 */
final class OidcFailure extends RuntimeException
{
    public const REFUSED = 'refused';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        public readonly string $kind,
        public readonly ?int $status = null,
        public readonly ?string $error = null,
        public readonly ?int $curlErrno = null,
    ) {
        parent::__construct(sprintf('Identity provider %s (status %s, error %s, cURL %s).', $kind, $status ?? '-', $error ?? '-', $curlErrno ?? '-'));
    }

    public static function refused(?int $status, ?string $error = null): self
    {
        return new self(self::REFUSED, $status, $error);
    }

    public static function unavailable(?int $status = null, ?int $curlErrno = null): self
    {
        return new self(self::UNAVAILABLE, $status, null, $curlErrno);
    }

    public function isUnavailable(): bool
    {
        return $this->kind === self::UNAVAILABLE;
    }
}
