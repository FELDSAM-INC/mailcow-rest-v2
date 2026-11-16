<?php

namespace App\Clients\SyncSources;

/** Provider requests: TLS, no redirects or proxy, bounded responses and pinned public DNS for delegated administrators. */
class OAuthHttpClient
{
    /** @return list<string> */
    public function pin(string $url, bool $publicOnly): array
    {
        $p = parse_url($url);
        if (strlen($url) > 500 || preg_match('/[\x00-\x20\x7f]/', $url) || ! is_array($p)
            || strtolower($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])
            || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! filter_var($p['host'] ?? '', FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new OAuthFailure('endpointNotAllowed');
        }
        if (! $publicOnly) {
            return [];
        }
        $host = strtolower($p['host']);
        $ips = array_values(array_unique($this->resolve($host)));
        if ($ips === []) {
            throw new OAuthFailure('endpointNotAllowed');
        }
        $addresses = [];
        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw new OAuthFailure('endpointNotAllowed');
            }
            $addresses[] = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        }

        return [$host.':'.($p['port'] ?? 443).':'.implode(',', $addresses)];
    }

    /** @param array<string, scalar|null>|null $post
     * @return array<string, mixed>
     */
    public function request(string $url, bool $publicOnly, ?array $post = null, #[\SensitiveParameter] ?string $bearer = null): array
    {
        $pin = $this->pin($url, $publicOnly);
        $headers = ['Accept: application/json'];
        if ($bearer !== null) {
            if (preg_match('/[\r\n]/', $bearer)) {
                throw new OAuthFailure('malformedResponse');
            }
            $headers[] = 'Authorization: Bearer '.$bearer;
        }
        $options = [CURLOPT_URL => $url, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 7, CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RESOLVE => $pin];
        if ($post !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        [$body, $status] = $this->send($options);
        if ($body === false) {
            throw new OAuthFailure('connectionFailed');
        }
        if ($status < 200 || $status >= 300) {
            throw new OAuthFailure('providerRefused');
        }
        $data = json_decode($body, true);
        if (! is_array($data) || array_is_list($data)) {
            throw new OAuthFailure('malformedResponse');
        }

        return $data;
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        $ips = @gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    /** @param array<int, mixed> $options
     * @return array{string|false, int}
     */
    protected function send(array $options): array
    {
        $body = '';
        $curl = curl_init();
        $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 1048576) {
                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        };
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return [$ok === false ? false : $body, $status];
    }

    public static function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $v = ip2long($ip);

            return ($v & 0xFFC00000) !== 0x64400000 && ($v & 0xFFFE0000) !== 0xC6120000
                && ($v & 0xE0000000) !== 0xE0000000;
        }
        $hex = bin2hex((string) inet_pton($ip));

        return in_array($hex[0], ['2', '3'], true) && substr($hex, 0, 4) !== '2002'
            && ! in_array(substr($hex, 0, 8), ['20010000', '20010db8'], true);
    }
}
