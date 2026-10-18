<?php

namespace App\Services\IdentityProvider;

/**
 * The new web app's sign-in return addresses (research R4, FR-006, FR-021):
 * `<scheme>://<host><path>` for `MAILCOW_HOSTNAME` and each of `ADDITIONAL_SERVER_NAMES`, primary
 * first, lower-cased, duplicates and entries that are not host names dropped. A single sign-on
 * started on one of these host names returns there, any other request returns to the primary.
 * Derived from the server's settings, never stored.
 */
class AppReturnUrls
{
    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_map(fn (string $host): string => $this->url($host), $this->hosts());
    }

    /**
     * The return address for a request's host (`Host` header, with or without port).
     */
    public function forHost(string $host): string
    {
        $host = strtolower(trim($host));

        if (str_starts_with($host, '[')) {
            $host = substr($host, 0, (int) strpos($host, ']') + 1);
        } elseif (substr_count($host, ':') === 1) {
            $host = explode(':', $host, 2)[0];
        }

        $hosts = $this->hosts();

        return $this->url(in_array($host, $hosts, true) ? $host : ($hosts[0] ?? $host));
    }

    /**
     * @return list<string>
     */
    private function hosts(): array
    {
        $candidates = [
            (string) config('restv2.mailcow_hostname'),
            ...explode(',', (string) config('restv2.additional_server_names')),
        ];
        $hosts = [];

        foreach ($candidates as $candidate) {
            $host = strtolower(trim($candidate));

            if ($host !== '' && self::isHostName($host) && ! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    private function url(string $host): string
    {
        $scheme = config('restv2.identity_provider.sso_return_scheme') === 'http' ? 'http' : 'https';
        $path = '/'.ltrim((string) config('restv2.identity_provider.sso_return_path', '/ng/sso/return'), '/');

        return $scheme.'://'.$host.$path;
    }

    private static function isHostName(string $host): bool
    {
        return strlen($host) <= 253
            && preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) === 1;
    }
}
