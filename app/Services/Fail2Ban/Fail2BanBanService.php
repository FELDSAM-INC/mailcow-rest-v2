<?php

namespace App\Services\Fail2Ban;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\Fail2Ban\Fail2BanStore;
use App\Rules\Fail2BanDenylistEntry;
use App\Services\Audit\AuditLogger;
use App\Services\Monitoring\ContainerRegistry;
use App\Support\Http\FieldViolations;
use App\Support\Http\ListQuery;
use App\Support\Network\IpNetwork;
use Carbon\CarbonImmutable;
use Redis;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Active and permanent bans and the actions on an active ban (feature 011 research R22, FR-052 to
 * FR-054, legacy fail2ban('get') and the `unban`, `whitelist` and `blacklist` actions, restricted
 * to active bans): lift queues the ban in `F2B_QUEUE_UNBAN`; allowlisting adds the network to the
 * allowlist, removes it from the denylist and queues the lift; denylisting adds it to the denylist
 * (with the denylist's protected networks) and removes it from the allowlist. Each action is one
 * transaction watching the ban, so a ban that expires meanwhile is "not found".
 */
class Fail2BanBanService
{
    private const EVENTS = [
        'lift' => AuditEventType::Fail2banBanLift,
        'allowlist' => AuditEventType::Fail2banBanAllowlisting,
        'denylist' => AuditEventType::Fail2banBanDenylisting,
    ];

    public function __construct(
        private readonly Fail2BanStore $store,
        private readonly ContainerRegistry $containers,
        private readonly AuditLogger $logger,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function active(ListQuery $list, ?bool $queuedForLifting, bool $orderGiven): array
    {
        $queue = $this->store->hash(Fail2BanStore::UNBAN_QUEUE);
        $bans = [];

        foreach ($this->store->hash(Fail2BanStore::ACTIVE_BANS) as $network => $endsAt) {
            $ban = self::ban((string) $network, (int) $endsAt, ($queue[$network] ?? null) === '1');

            if ($queuedForLifting === null || $ban['queuedForLifting'] === $queuedForLifting) {
                $bans[] = $ban + ['_time' => (int) $endsAt];
            }
        }

        return self::page($bans, $list, $orderGiven);
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function permanent(ListQuery $list, bool $orderGiven): array
    {
        $bans = [];

        foreach ($this->store->hash(Fail2BanStore::PERMANENT_BANS) as $network => $startedAt) {
            $bans[] = [
                'network' => (string) $network,
                'address' => IpNetwork::address((string) $network),
                'startedAt' => gmdate('Y-m-d\TH:i:s\Z', (int) $startedAt),
                '_time' => (int) $startedAt,
            ];
        }

        return self::page($bans, $list, $orderGiven);
    }

    /**
     * @param  'lift'|'allowlist'|'denylist'  $action
     * @return array{data: array<string, mixed>, auditRecorded: bool}
     */
    public function act(Principal $actor, string $action, string $network): array
    {
        $keys = [Fail2BanStore::ACTIVE_BANS, Fail2BanStore::UNBAN_QUEUE, Fail2BanStore::ALLOWLIST, Fail2BanStore::DENYLIST];

        [$plan, $committed] = $this->store->transaction($keys, function (Redis $redis) use ($action, $network): array {
            $bans = $redis->hGetAll(Fail2BanStore::ACTIVE_BANS);
            $stored = null;

            foreach (is_array($bans) ? $bans : [] as $field => $endsAt) {
                if (IpNetwork::canonical((string) $field) === IpNetwork::canonical($network)) {
                    $stored = ['network' => (string) $field, 'endsAt' => (int) $endsAt];

                    break;
                }
            }

            if ($stored === null) {
                throw new NotFoundHttpException('The network is not actively banned.');
            }

            $net = $stored['network'];
            $allowlist = $this->store->fields($redis, Fail2BanStore::ALLOWLIST);
            $denylist = $this->store->fields($redis, Fail2BanStore::DENYLIST);
            $queued = ($redis->hGet(Fail2BanStore::UNBAN_QUEUE, $net) === '1') || $action !== 'denylist';
            $details = ['network' => $net];

            if ($action === 'allowlist' || $action === 'denylist') {
                $this->checkList($action, $net, $action === 'allowlist' ? $allowlist : $denylist);
                [$add, $remove] = $action === 'allowlist' ? ['allowlist', 'denylist'] : ['denylist', 'allowlist'];

                if (! in_array($net, $action === 'allowlist' ? $allowlist : $denylist, true)) {
                    $details[$add.'Added'] = [$net];
                }

                if (in_array($net, $action === 'allowlist' ? $denylist : $allowlist, true)) {
                    $details[$remove.'Removed'] = [$net];
                }
            }

            return [function (Redis $multi) use ($action, $net): void {
                if ($action === 'denylist') {
                    $multi->hSet(Fail2BanStore::DENYLIST, $net, '1');
                    $multi->hDel(Fail2BanStore::ALLOWLIST, $net);

                    return;
                }

                if ($action === 'allowlist') {
                    $multi->hSet(Fail2BanStore::ALLOWLIST, $net, '1');
                    $multi->hDel(Fail2BanStore::DENYLIST, $net);
                }

                $multi->hSet(Fail2BanStore::UNBAN_QUEUE, $net, '1');
            }, ['ban' => self::ban($net, $stored['endsAt'], $queued), 'details' => $details]];
        }, fn (array $plan) => $this->logger->record(self::EVENTS[$action], true, $actor, ['type' => 'fail2banActiveBan', 'id' => $plan['details']['network'], 'name' => $plan['details']['network']], null, null, $plan['details']));

        return ['data' => [
            'action' => $action,
            'ban' => $plan['ban'],
            'banServiceRunning' => $this->containers->banServiceRunning(),
        ], 'auditRecorded' => $committed];
    }

    /**
     * @param  list<string>  $entries  the list the network is added to
     */
    private function checkList(string $list, string $network, array $entries): void
    {
        $violations = new FieldViolations;

        $reserved = Fail2BanDenylistEntry::reserved();

        if ($list === 'denylist' && in_array(IpNetwork::canonical($network), array_map(IpNetwork::canonical(...), $reserved), true)) {
            $violations->add('network', 'reserved', ['reserved' => $reserved], 'This network would ban everything or mailcow itself.');
        }

        $max = (int) config('restv2.monitoring.ban_list_max_entries', 10000);

        if (! in_array($network, $entries, true) && count($entries) >= $max) {
            $violations->add('network', 'tooMany', ['max' => $max], "The {$list} already holds {$max} entries.");
        }

        $violations->throwIfAny();
    }

    /**
     * @return array{network: string, address: string, endsAt: string, remainingSeconds: int, queuedForLifting: bool}
     */
    private static function ban(string $network, int $endsAt, bool $queued): array
    {
        return [
            'network' => $network,
            'address' => IpNetwork::address($network),
            'endsAt' => gmdate('Y-m-d\TH:i:s\Z', $endsAt),
            'remainingSeconds' => max(0, $endsAt - CarbonImmutable::now('UTC')->getTimestamp()),
            'queuedForLifting' => $queued,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $bans  each with `_time`
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    private static function page(array $bans, ListQuery $list, bool $orderGiven): array
    {
        $search = $list->search === null ? null : mb_strtolower($list->search);

        if ($search !== null) {
            $bans = array_values(array_filter($bans, fn (array $b): bool => str_contains(mb_strtolower((string) $b['network']), $search)));
        }

        $order = $orderGiven || $list->sort !== 'network' ? $list->order : 'asc';
        usort($bans, function (array $a, array $b) use ($list, $order): int {
            $network = IpNetwork::sortKey((string) $a['network']) <=> IpNetwork::sortKey((string) $b['network']);
            $result = $list->sort === 'network' ? $network : (($a['_time'] <=> $b['_time']) ?: $network);

            return $order === 'desc' ? -$result : $result;
        });

        $page = array_map(function (array $b): array {
            unset($b['_time']);

            return $b;
        }, array_slice($bans, $list->offset, $list->limit));

        return ['data' => $page, 'meta' => $list->meta(count($bans))];
    }
}
