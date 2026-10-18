<?php

namespace App\Services\DnsDiagnostics;

/**
 * The zone file of a run (feature 010 FR-047, research R21), for import at a DNS provider: an
 * `$ORIGIN` line and one line per checked record of the name, with absolute owner names, class
 * `IN`, MX preference 10, SRV priority 0 and weight 1 (a null record `0 0 0 .`), and TXT values
 * quoted (`"` and `\` escaped) and split into strings of at most 255 characters. Checks with an
 * exact expected value contribute it; SPF, DMARC and TLS reporting contribute the values found, or
 * a commented placeholder when none was found. Server-wide records are left out, as are checks
 * whose expected value is unknown.
 */
class ZoneFileRenderer
{
    /**
     * @param  list<DnsCheckResult>  $checks
     */
    public function render(string $name, array $checks): string
    {
        $zone = "\$ORIGIN {$name}.\n";

        foreach ($checks as $check) {
            if ($check->scope !== ExpectedCheck::DOMAIN || ($check->recordName !== $name && ! str_ends_with($check->recordName, '.'.$name))) {
                continue;
            }

            $owner = $check->recordName.'.';

            if (in_array($check->id, ['spf', 'dmarc', 'tls-rpt'], true)) {
                $zone .= $check->found === []
                    ? "; {$owner}\tIN\tTXT\t".self::quoted($this->placeholder($check->id))."\n"
                    : implode('', array_map(fn (string $value): string => "{$owner}\tIN\tTXT\t".self::quoted($value)."\n", $check->found));

                continue;
            }

            if ($check->expected === null) {
                continue;
            }

            $data = match ($check->recordType) {
                'MX' => '10 '.self::absolute($check->expected),
                'CNAME', 'PTR' => self::absolute($check->expected),
                'SRV' => self::srv($check->expected),
                'TXT' => self::quoted($check->expected),
                default => $check->expected,
            };

            $zone .= "{$owner}\tIN\t{$check->recordType}\t{$data}\n";
        }

        return $zone;
    }

    private function placeholder(string $id): string
    {
        $spf = trim((string) config('restv2.dns.suggested_spf_record'));

        return match ($id) {
            'spf' => $spf !== '' ? $spf : 'v=spf1 ... -all',
            'dmarc' => 'v=DMARC1; p=...; rua=mailto:...',
            default => 'v=TLSRPTv1; rua=mailto:...',
        };
    }

    private static function absolute(string $host): string
    {
        return $host === '.' || str_ends_with($host, '.') ? $host : $host.'.';
    }

    /** `<target> <port>` as `0 1 <port> <target>.`; a null record as `0 0 0 .`. */
    private static function srv(string $value): string
    {
        [$target, $port] = array_pad(explode(' ', $value, 2), 2, '0');

        return $target === '.' ? '0 0 0 .' : '0 1 '.(int) $port.' '.self::absolute($target);
    }

    /** A TXT value as quoted character strings of at most 255 characters. */
    private static function quoted(string $value): string
    {
        $parts = $value === '' ? [''] : str_split($value, 255);

        return implode(' ', array_map(fn (string $part): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $part).'"', $parts));
    }
}
