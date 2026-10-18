<?php

namespace App\Services\RecentLogins;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Auth\Role;
use App\Casts\MailcowDateTime;
use App\Clients\Geo\CountryLookup;
use App\Clients\SharedStore\SharedStore;
use App\Services\AppPasswords\AppPasswordService;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\DB;

/**
 * The recent logins of a mailbox (feature 005 FR-061 to FR-063, research R16), ported from legacy
 * last_login(): grouped as legacy groups them, paginated after grouping, countries from mailcow's
 * `IP_SHORTCOUNTRY` cache and, for the uncached public addresses of the page, from the external
 * lookup within its budget (written back to the cache, as legacy does).
 */
class RecentLoginService
{
    /** Contract sort value => column of the grouped rows. */
    public const SORT_COLUMNS = ['lastSeenAt' => 'datetime', 'service' => 'service_code', 'clientAddress' => 'real_rip'];

    /** The one code of web UI sign-ins, whatever mailcow stored. */
    public const WEB_UI = 'WEBUI';

    /** Web UI sign-ins as stored: `MAILCOWUI` upstream, `WEBUI` in the FeldHost fork. */
    public const WEB_UI_CODES = ['MAILCOWUI', 'WEBUI'];

    private const COUNTRY_CACHE = 'IP_SHORTCOUNTRY';

    public function __construct(
        private readonly SharedStore $store,
        private readonly CountryLookup $countries,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(string $mailbox, int $days, ?string $service, ListQuery $query): array
    {
        // legacy's grouping query, with the service filter and the search on the address.
        $grouped = DB::table('sasl_log')
            ->leftJoin('app_passwd', 'sasl_log.app_password', '=', 'app_passwd.id')
            ->select(['sasl_log.real_rip', 'sasl_log.service', 'sasl_log.app_password'])
            ->selectRaw('MAX(`sasl_log`.`datetime`) AS `datetime`, MAX(`app_passwd`.`name`) AS `app_password_name`')
            ->where('sasl_log.username', $mailbox)
            ->whereRaw('HOUR(TIMEDIFF(NOW(), `sasl_log`.`datetime`)) < ?', [$days * 24])
            ->groupBy(['sasl_log.real_rip', 'sasl_log.service', 'sasl_log.app_password']);

        if ($service !== null) {
            $code = strtoupper($service);
            $codes = in_array($code, self::WEB_UI_CODES, true) ? self::WEB_UI_CODES : [$code];
            $grouped->whereIn(DB::raw('UPPER(`sasl_log`.`service`)'), $codes);
        }

        $query->applySearch($grouped, ['sasl_log.real_rip']);

        $logins = DB::query()->fromSub($grouped, 'logins')
            ->select('logins.*')
            ->selectRaw("CASE WHEN UPPER(`service`) IN ('MAILCOWUI', 'WEBUI') THEN 'WEBUI' ELSE UPPER(`service`) END AS `service_code`");

        $total = (clone $logins)->count();
        $logins->orderBy($query->sortColumn(), $query->order);

        // Stable pages: the remaining group columns break ties.
        foreach (['datetime' => 'desc', 'real_rip' => 'asc', 'service_code' => 'asc', 'app_password' => 'asc'] as $column => $order) {
            if ($column !== $query->sortColumn()) {
                $logins->orderBy($column, $order);
            }
        }

        $rows = $logins->offset($query->offset)->limit($query->limit)->get()->all();
        $countries = $this->countriesOf($rows);

        return [
            'data' => array_map(fn (object $row): array => $this->present($row, $countries), $rows),
            'meta' => $query->meta($total),
        ];
    }

    /**
     * Clears the recent logins and, for administrators and the mailbox user, the legacy UI's
     * records of the mailbox's successful sign-ins, with legacy's statements.
     */
    public function clear(Principal $principal, string $mailbox): void
    {
        DB::transaction(function () use ($principal, $mailbox): void {
            $recentLogins = DB::delete('DELETE FROM `sasl_log` WHERE `username` = ?', [$mailbox]);
            $signIns = 0;

            if ($principal->role === Role::Admin || ($principal->role === Role::MailboxUser && $principal->username === $mailbox)) {
                $signIns = DB::delete(
                    'DELETE FROM `logs` WHERE JSON_EXTRACT(`call`, "$[0]") = "check_login" AND JSON_EXTRACT(`call`, "$[1]") = ? AND `type` = "success"',
                    [$mailbox],
                );
            }

            $this->audit->record(
                AuditEventType::RecentLoginsClear,
                true,
                $principal,
                ['type' => 'recentLogins', 'id' => $mailbox, 'name' => null],
                null,
                null,
                ['removedRecentLogins' => $recentLogins, 'removedSignInRecords' => $signIns],
            );
        });
    }

    /** A private, reserved or internal address, as legacy tells them apart. */
    public static function isInternal(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * The known countries of the page's public addresses: cached ones, then (when enabled) the
     * uncached ones looked up within the budget and written back.
     *
     * @param  list<object>  $rows
     * @return array<string, string> address => country
     */
    private function countriesOf(array $rows): array
    {
        $public = array_values(array_unique(array_filter(
            array_map(fn (object $row): string => (string) $row->real_rip, $rows),
            fn (string $address): bool => ! self::isInternal($address),
        )));

        if ($public === []) {
            return [];
        }

        $countries = [];

        foreach ($this->store->getFields(self::COUNTRY_CACHE, $public) as $address => $country) {
            if (is_string($country) && $country !== '') {
                $countries[(string) $address] = $country;
            }
        }

        $uncached = array_values(array_filter($public, fn (string $address): bool => ! isset($countries[$address])));

        if ($uncached === [] || ! config('restv2.country_lookup')) {
            return $countries;
        }

        $found = $this->countries->lookup($uncached, (int) config('restv2.country_lookup_budget_ms'));

        if ($found !== []) {
            $this->store->setFields(self::COUNTRY_CACHE, $found);
        }

        return $countries + $found;
    }

    /**
     * @param  array<string, string>  $countries
     * @return array<string, mixed> contract `RecentLogin`
     */
    private function present(object $row, array $countries): array
    {
        $address = (string) $row->real_rip;
        $internal = self::isInternal($address);
        $appPassword = (int) $row->app_password;
        $country = $internal ? null : ($countries[$address] ?? null);

        return [
            'service' => (string) $row->service_code,
            'clientAddress' => $address,
            'internal' => $internal,
            // The contract allows ISO codes only; anything else cached is reported as unknown.
            'country' => is_string($country) && preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null,
            'appPassword' => $appPassword > 0 ? [
                'id' => $appPassword,
                'name' => $row->app_password_name === null ? null : AppPasswordService::decode((string) $row->app_password_name),
            ] : null,
            'lastSeenAt' => MailcowDateTime::toUtcString($row->datetime),
        ];
    }
}
