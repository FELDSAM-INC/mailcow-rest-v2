<?php

namespace App\Services\Logs;

use App\Casts\MailcowDateTime;
use App\Support\Http\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The login log, table `sasl_log` (feature 011 research R14, FR-030): `datetime` holds mailcow's
 * local time and is returned in UTC; legacy web UI logins stored as `MAILCOWUI` are reported with
 * feature 001's label (`WEBUI` in the FeldHost deployment), and filters and `q` see the reported
 * label. The table has no row ID and rows are replaced, so paging is a keyset over its ordering
 * `(datetime, service, real_rip, username)`, newest first; `before` is that key.
 */
class LoginLogSource
{
    public function page(LogQuery $query): LogPage
    {
        $label = (string) config('restv2.sasl_log_service', 'MAILCOWUI') === 'WEBUI' ? 'WEBUI' : null;
        $service = $label === null ? '`service`' : "CASE WHEN `service` = 'MAILCOWUI' THEN 'WEBUI' ELSE `service` END";
        $rows = DB::table('sasl_log')->select(['username', 'service', 'real_rip', 'datetime', 'app_password'])->selectRaw("{$service} AS reported_service");

        if ($query->from !== null) {
            $rows->where('datetime', '>=', self::local($query->from));
        }

        if ($query->to !== null) {
            $rows->where('datetime', '<=', self::local($query->to));
        }

        if ($query->before !== null && ($key = LogCursor::parseLogin($query->before)) !== null) {
            $rows->whereRaw('(`datetime`, `service`, `real_rip`, `username`) < (?, ?, ?, ?)', $key);
        }

        foreach ($query->filters as $field => $values) {
            $column = match ($field) {
                'username' => '`username`',
                'service' => $service,
                default => '`real_rip`',
            };
            self::anyLike($rows, [$column], $values);
        }

        if ($query->search !== null) {
            self::anyLike($rows, ['`username`', $service, '`real_rip`'], [$query->search]);
        }

        $total = (clone $rows)->count();
        $direction = $query->order === 'asc' ? 'asc' : 'desc';

        foreach (['datetime', 'service', 'real_rip', 'username'] as $column) {
            $rows->orderBy($column, $direction);
        }

        $entries = [];

        foreach ($rows->offset($query->offset)->limit($query->limit)->get() as $row) {
            $local = (string) $row->datetime;
            $entries[] = [
                'id' => LogCursor::login($local, (string) $row->service, (string) $row->real_rip, (string) $row->username),
                'time' => CarbonImmutable::parse($local, MailcowDateTime::zone())->utc()->format('Y-m-d\TH:i:s\Z'),
                'username' => (string) $row->username,
                'service' => (string) $row->reported_service,
                'clientAddress' => (string) $row->real_rip,
                'appPasswordUsed' => (int) ($row->app_password ?? 0) > 0,
            ];
        }

        return new LogPage($entries, $total);
    }

    /**
     * @param  list<string>  $expressions
     * @param  list<string>  $values
     */
    private static function anyLike(Builder $rows, array $expressions, array $values): void
    {
        $rows->where(function (Builder $inner) use ($expressions, $values): void {
            foreach ($values as $value) {
                foreach ($expressions as $expression) {
                    $inner->orWhereRaw("LOWER({$expression}) LIKE ?", [ListQuery::likePattern(mb_strtolower($value))]);
                }
            }
        });
    }

    private static function local(CarbonImmutable $time): string
    {
        return $time->setTimezone(MailcowDateTime::zone())->format('Y-m-d H:i:s');
    }
}
