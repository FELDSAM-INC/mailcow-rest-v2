<?php

namespace App\Services\Routing;

use App\Clients\Dns\DnsResolver;
use App\Clients\Dns\DnsTemporaryFailure;

/**
 * The hosts an SPF record permits (feature 009 FR-076, research R16), legacy
 * get_outgoing_hosts_best_guess() of inc/spf.inc.php with two reading fixes: the character strings
 * of a TXT record are concatenated, and `a` and `mx` honour their CIDR forms (`a/24`, `mx//64`).
 * Terms are read left to right: `ip4:` and `ip6:` add their value; `a` and `mx` add the addresses of
 * the domain or of its exchangers (at most 10); `include:` adds the included record's hosts (not a
 * self-include); `redirect=` replaces the result; a mechanism qualified `-` or `~` ends its record;
 * `all`, `ptr`, `exists` and `exp` are ignored. `include`, `redirect`, `a` and `mx` count as DNS
 * lookups; at the limit (10) evaluation stops and keeps what was found. A temporary DNS failure is
 * raised.
 */
class SpfHostCollector
{
    private int $lookups = 0;

    private bool $stopped = false;

    public function __construct(private readonly DnsResolver $dns, private readonly int $limit = 10) {}

    /**
     * @return list<string> addresses and prefixes as the records give them
     *
     * @throws DnsTemporaryFailure
     */
    public function collect(string $domain): array
    {
        $this->lookups = 0;
        $this->stopped = false;

        return $this->record(strtolower($domain));
    }

    /**
     * @return list<string>
     */
    private function record(string $domain): array
    {
        $spf = null;

        foreach ($this->dns->txt($domain) as $txt) {
            if (preg_match('/^v=spf1(\s|$)/i', $txt) === 1) {
                $spf = $txt;
                break;
            }
        }

        if ($spf === null) {
            return [];
        }

        $hosts = [];

        foreach (array_slice(preg_split('/\s+/', trim($spf)) ?: [], 1) as $term) {
            if ($this->stopped) {
                break;
            }

            $qualifier = in_array($term[0] ?? '', ['+', '-', '~', '?'], true) ? $term[0] : '+';
            $mechanism = $qualifier === '+' && ($term[0] ?? '') !== '+' ? $term : substr($term, 1);

            if ($qualifier === '-' || $qualifier === '~') {
                break;
            }

            $lower = strtolower($mechanism);

            if (str_starts_with($lower, 'ip4:') || str_starts_with($lower, 'ip6:')) {
                $hosts[] = substr($mechanism, 4);
            } elseif (preg_match('/^(a|mx)(?::([^\/]+))?(?:\/(\d{1,2}))?(?:\/\/(\d{1,3}))?$/i', $mechanism, $m, PREG_UNMATCHED_AS_NULL) === 1) {
                if (! $this->countLookup()) {
                    break;
                }

                $target = $m[2] === null ? $domain : strtolower($m[2]);
                $cidr4 = $m[3] === null ? '' : '/'.$m[3];
                $cidr6 = $m[4] === null ? '' : '/'.$m[4];
                $names = strtolower((string) $m[1]) === 'a' ? [$target] : array_slice(array_column($this->dns->mx($target), 'host'), 0, 10);

                foreach ($names as $name) {
                    foreach ($this->dns->a($name) as $address) {
                        $hosts[] = $address.$cidr4;
                    }

                    foreach ($this->dns->aaaa($name) as $address) {
                        $hosts[] = $address.$cidr6;
                    }
                }
            } elseif (str_starts_with($lower, 'include:')) {
                $included = strtolower(substr($mechanism, 8));

                if ($included === $domain) {
                    continue;
                }

                if (! $this->countLookup()) {
                    break;
                }

                $hosts = [...$hosts, ...$this->record($included)];
            } elseif (str_starts_with($lower, 'redirect=')) {
                if (! $this->countLookup()) {
                    break;
                }

                $hosts = $this->record(strtolower(substr($mechanism, 9)));
            }
        }

        return $hosts;
    }

    private function countLookup(): bool
    {
        if ($this->lookups >= $this->limit) {
            $this->stopped = true;

            return false;
        }

        $this->lookups++;

        return true;
    }
}
