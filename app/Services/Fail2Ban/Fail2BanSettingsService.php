<?php

namespace App\Services\Fail2Ban;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\Fail2Ban\Fail2BanStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\Audit\AuditLogger;
use App\Services\Monitoring\ContainerRegistry;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Network\IpNetwork;
use Illuminate\Support\Str;
use Redis;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The ban service settings (feature 011 research R18, R19, FR-044 to FR-048, FR-055 to FR-057,
 * FR-061, legacy fail2ban('get'), fail2ban('edit'), fail2ban('banlist', 'refresh')): read with the
 * ban service defaults when nothing is stored; changed by merge patch, where the ranges apply only
 * to sent values that differ from the stored ones, the parameters and the sent lists are stored
 * together in one transaction, and a missing ban list ID is generated; the ban list ID rotated on
 * its own. Each change writes one audit record inside the transaction.
 */
class Fail2BanSettingsService
{
    private const MAX = 2147483647;

    /** Contract name => [minimum, maximum]. */
    private const RANGES = [
        'banTime' => [60, self::MAX],
        'maxBanTime' => [60, self::MAX],
        'maxAttempts' => [1, self::MAX],
        'retryWindow' => [1, self::MAX],
        'ipv4PrefixLength' => [8, 32],
        'ipv6PrefixLength' => [8, 128],
    ];

    private const WATCHED = [Fail2BanStore::OPTIONS, Fail2BanStore::ALLOWLIST, Fail2BanStore::DENYLIST];

    public function __construct(
        private readonly Fail2BanStore $store,
        private readonly ContainerRegistry $containers,
        private readonly AuditLogger $logger,
    ) {}

    /**
     * @return array{data: array<string, mixed>, etag: string}
     *
     * @throws SharedStoreUnavailable
     */
    public function read(): array
    {
        return $this->present($this->store->settings());
    }

    /**
     * @param  array<string, mixed>  $patch  the validated merge patch (contract names, lists trimmed and unique)
     * @return array{data: array<string, mixed>, etag: string, auditRecorded: bool}
     */
    public function update(Principal $actor, array $patch, ?string $ifMatch): array
    {
        [$changed, $committed] = $this->store->transaction(self::WATCHED, function (Redis $redis) use ($patch, $ifMatch): array {
            $current = $this->store->readSettings($redis);
            $this->checkVersion($current, $ifMatch);
            $this->validate($current, $patch);
            $next = $current->with($patch + ($current->banListId === null ? ['banListId' => (string) Str::uuid()] : []))->with(['stored' => true]);

            return [function (Redis $multi) use ($next, $patch): void {
                $multi->set(Fail2BanStore::OPTIONS, $next->optionsJson());

                foreach (['allowlist' => Fail2BanStore::ALLOWLIST, 'denylist' => Fail2BanStore::DENYLIST] as $list => $key) {
                    if (array_key_exists($list, $patch)) {
                        $multi->del($key);

                        foreach ($next->{$list} as $entry) {
                            $multi->hSet($key, $entry, '1');
                        }
                    }
                }
            }, [$current, $next]];
        }, fn (array $states) => $this->logger->record(AuditEventType::Fail2banSettingsUpdate, true, $actor, self::target(), null, null, self::changes(...$states)));

        return $this->present($changed[1]) + ['auditRecorded' => $committed];
    }

    /**
     * Stores a new random ban list ID; the old public ban list address stops working.
     *
     * @return array{data: array<string, mixed>, etag: string, auditRecorded: bool}
     */
    public function rotate(Principal $actor, ?string $ifMatch): array
    {
        [$next, $committed] = $this->store->transaction([Fail2BanStore::OPTIONS], function (Redis $redis) use ($ifMatch): array {
            $current = $this->store->readSettings($redis);
            $this->checkVersion($current, $ifMatch);
            $next = $current->with(['banListId' => (string) Str::uuid(), 'stored' => true]);

            return [fn (Redis $multi) => $multi->set(Fail2BanStore::OPTIONS, $next->optionsJson()), $next];
        }, fn () => $this->logger->record(AuditEventType::Fail2banBanListIdRotation, true, $actor, ['type' => 'fail2banBanListId', 'id' => null, 'name' => null], null, null, []));

        return $this->present($next) + ['auditRecorded' => $committed];
    }

    /**
     * @return array{data: array<string, mixed>, etag: string}
     */
    public function present(Fail2BanSettingsValues $values): array
    {
        $etag = Fail2BanVersionTag::settings($values);
        $host = (string) (config('restv2.mailcow_hostname') ?? '');

        return ['data' => [
            'banTime' => $values->banTime,
            'maxBanTime' => $values->maxBanTime,
            'banTimeIncrement' => $values->banTimeIncrement,
            'maxAttempts' => $values->maxAttempts,
            'retryWindow' => $values->retryWindow,
            'ipv4PrefixLength' => $values->ipv4PrefixLength,
            'ipv6PrefixLength' => $values->ipv6PrefixLength,
            'externallyEnforced' => $values->externallyEnforced,
            'allowlist' => $values->allowlist,
            'denylist' => $values->denylist,
            'banListId' => $values->banListId,
            'banListUrl' => $values->banListId === null || $host === '' ? null : 'https://'.$host.'/f2b-banlist?id='.rawurlencode($values->banListId),
            'stored' => $values->stored,
            'banServiceRunning' => $this->containers->banServiceRunning(),
            'etag' => $etag,
        ], 'etag' => $etag];
    }

    private function checkVersion(Fail2BanSettingsValues $current, ?string $ifMatch): void
    {
        if (ETag::preconditionFails($ifMatch, Fail2BanVersionTag::settings($current))) {
            throw new PreconditionFailedHttpException('The ban service settings were changed since they were read.');
        }
    }

    /**
     * The rules that need the stored values (research R19): ranges of changed values, the maximum
     * ban time against the ban time, and entries on both lists.
     *
     * @param  array<string, mixed>  $patch
     */
    private function validate(Fail2BanSettingsValues $current, array $patch): void
    {
        $violations = new FieldViolations;

        foreach (self::RANGES as $field => [$min, $max]) {
            if (array_key_exists($field, $patch) && $patch[$field] !== $current->{$field} && ($patch[$field] < $min || $patch[$field] > $max)) {
                $violations->add($field, 'outOfRange', ['min' => $min, 'max' => $max]);
            }
        }

        $banTime = $patch['banTime'] ?? $current->banTime;
        $maxBanTime = $patch['maxBanTime'] ?? $current->maxBanTime;
        $changesEither = (array_key_exists('banTime', $patch) && $patch['banTime'] !== $current->banTime)
            || (array_key_exists('maxBanTime', $patch) && $patch['maxBanTime'] !== $current->maxBanTime);

        if ($changesEither && $maxBanTime < $banTime && ! $violations->has('maxBanTime') && ! $violations->has('banTime')) {
            array_key_exists('maxBanTime', $patch)
                ? $violations->add('maxBanTime', 'outOfRange', ['min' => $banTime, 'max' => self::MAX, 'minField' => 'banTime'], "Must not be less than the ban time ({$banTime}).")
                : $violations->add('banTime', 'mustNotExceed', ['field' => 'maxBanTime', 'value' => $maxBanTime]);
        }

        $allowlist = $patch['allowlist'] ?? $current->allowlist;
        $denylist = $patch['denylist'] ?? $current->denylist;

        if (array_key_exists('allowlist', $patch) || array_key_exists('denylist', $patch)) {
            $canonical = fn (array $list): array => array_map(IpNetwork::canonical(...), $list);
            [$checked, $other, $otherName] = array_key_exists('denylist', $patch)
                ? ['denylist', $canonical($allowlist), 'allowlist']
                : ['allowlist', $canonical($denylist), 'denylist'];

            foreach (array_values($patch[$checked]) as $index => $entry) {
                if (in_array(IpNetwork::canonical($entry), $other, true)) {
                    $violations->add("{$checked}.{$index}", 'conflictsWith', ['field' => $otherName, 'value' => $entry], "The entry is also on the {$otherName}.");
                }
            }
        }

        $violations->throwIfAny();
    }

    /**
     * Audit details: each changed parameter (never the ban list ID) and the list entries added and
     * removed.
     *
     * @return array<string, mixed>
     */
    private static function changes(Fail2BanSettingsValues $old, Fail2BanSettingsValues $new): array
    {
        $changes = [];

        foreach (['banTime', 'maxBanTime', 'banTimeIncrement', 'maxAttempts', 'retryWindow', 'ipv4PrefixLength', 'ipv6PrefixLength', 'externallyEnforced'] as $field) {
            if ($old->{$field} !== $new->{$field}) {
                $changes[$field] = ['old' => $old->{$field}, 'new' => $new->{$field}];
            }
        }

        $details = ['changes' => (object) $changes];

        foreach (['allowlist', 'denylist'] as $list) {
            $added = array_values(array_diff($new->{$list}, $old->{$list}));
            $removed = array_values(array_diff($old->{$list}, $new->{$list}));

            if ($added !== []) {
                $details[$list.'Added'] = $added;
            }

            if ($removed !== []) {
                $details[$list.'Removed'] = $removed;
            }
        }

        return $details;
    }

    /**
     * @return array{type: string, id: null, name: null}
     */
    private static function target(): array
    {
        return ['type' => 'fail2banSettings', 'id' => null, 'name' => null];
    }
}
