<?php

namespace App\Support\Http;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * `Cache-Control: no-store` exactly as the contract states it (feature 010: DNS diagnostics and the
 * DKIM private key export). Symfony appends `private` to a Cache-Control value without `public`,
 * `private` or `s-maxage`; `no-store` already forbids every cache, so this header bag keeps it as
 * set.
 */
final class NoStore extends ResponseHeaderBag
{
    /**
     * @template TResponse of Response
     *
     * @param  TResponse  $response
     * @return TResponse
     */
    public static function apply(Response $response): Response
    {
        $headers = new self($response->headers->allPreserveCaseWithoutCookies());

        foreach ($response->headers->getCookies() as $cookie) {
            $headers->setCookie($cookie);
        }

        $headers->set('Cache-Control', 'no-store');
        $response->headers = $headers;

        return $response;
    }

    protected function computeCacheControlValue(): string
    {
        if (isset($this->cacheControl['no-store']) && ! isset($this->cacheControl['public']) && ! isset($this->cacheControl['private'])) {
            return $this->getCacheControlHeader();
        }

        return parent::computeCacheControlValue();
    }
}
