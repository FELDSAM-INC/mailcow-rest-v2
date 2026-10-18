<?php

namespace Tests\Support\Redis;

use Illuminate\Support\Facades\Redis;

/**
 * Writes mailcow's log lists and ban service keys on the test Redis exactly as their producers do
 * (feature 011 T007): syslog-ng's `format-json` (Postfix, Dovecot, SOGo: text times), netfilter's
 * Python `json.dumps` (`", "` and `": "` separators, integer times), legacy PHP's `json_encode`
 * (autodiscover, cron, rate limits, `api_log()`), the shell producers (watchdog, ACME: text times),
 * and the `F2B_*` keys as legacy and netfilter store them. The newest entry is pushed last (LPUSH).
 */
final class MailcowLogs
{
    /** Pushes a raw line, for example one that is not JSON. */
    public static function raw(string $key, string $line): void
    {
        Redis::connection('mailcow')->command('lpush', [$key, $line]);
    }

    /**
     * @param  list<string>  $lines  oldest first
     */
    public static function rawMany(string $key, array $lines): void
    {
        foreach (array_chunk($lines, 1000) as $chunk) {
            Redis::connection('mailcow')->command('lpush', [$key, ...$chunk]);
        }
    }

    /** syslog-ng `$(format-json time="$S_UNIXTIME" priority="$PRIORITY" program="$PROGRAM" message="$MESSAGE")`. */
    public static function syslogLine(int $time, string $priority, string $program, string $message): string
    {
        return (string) json_encode(['time' => (string) $time, 'priority' => $priority, 'program' => $program, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function syslog(string $key, int $time, string $priority, string $program, string $message): void
    {
        self::raw($key, self::syslogLine($time, $priority, $program, $message));
    }

    /** netfilter's Logger: `json.dumps({'time': int, 'priority': …, 'message': …}, ensure_ascii=False)`. */
    public static function netfilter(int $time, string $priority, string $message): void
    {
        self::raw('NETFILTER_LOG', self::pythonJson(['time' => $time, 'priority' => $priority, 'message' => $message]));
    }

    /** autodiscover.php: `json_encode(['time' => time(), 'ua' => …, 'user' => …, 'ip' => …, 'service' => …])`. */
    public static function autodiscover(int $time, ?string $userAgent, string $user, string $ip, string $service): void
    {
        self::raw('AUTODISCOVER_LOG', (string) json_encode(['time' => $time, 'ua' => $userAgent, 'user' => $user, 'ip' => $ip, 'service' => $service]));
    }

    /** watchdog.sh health line: every value is text. */
    public static function watchdogHealth(int $time, string $service, int $level, int $now, int $total, int $diff): void
    {
        self::raw('WATCHDOG_LOG', sprintf('{"time":"%d","service":"%s","lvl":"%d","hpnow":"%d","hptotal":"%d","hpdiff":"%d"}', $time, $service, $level, $now, $total, $diff));
    }

    /** watchdog.sh log_msg(). */
    public static function watchdogMessage(int $time, string $message): void
    {
        self::raw('WATCHDOG_LOG', sprintf('{"time":"%d","message":"%s"}', $time, $message));
    }

    /** acme functions.sh log_f(): `base64,` is a prefix, the text itself is written as is. */
    public static function acme(int $time, string $message): void
    {
        self::raw('ACME_LOG', (string) json_encode(['time' => (string) $time, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** keycloak-sync.php / ldap-sync.php logMsg(). */
    public static function cron(int $time, string $priority, string $task, string $message): void
    {
        self::raw('CRON_LOG', (string) json_encode(['time' => $time, 'priority' => $priority, 'task' => $task, 'message' => $message]));
    }

    /**
     * pipe_rl.php: `rcpt` joined with ", ", `rl_name`/`rl_hash` `err` when the limit is unknown.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function rateLimit(int $time, array $overrides = []): void
    {
        self::raw('RL_LOG', (string) json_encode([
            'time' => $time,
            'rcpt' => 'bob@partner.example, carol@partner.example',
            'from' => 'alice@example.org',
            'user' => 'alice@example.org',
            'rl_info' => 'alice@example.org(RLsdz3tuabozgd4oxywmxq)',
            'rl_name' => 'alice@example.org',
            'rl_hash' => 'RLsdz3tuabozgd4oxywmxq',
            'qid' => 'B1C2D3E4F5',
            'ip' => '192.0.2.44',
            'message_id' => '<rl-0001@example.org>',
            'header_subject' => 'Newsletter',
            'header_from' => 'Alice <alice@example.org>',
            ...$overrides,
        ]));
    }

    /**
     * json_api.php api_log(): each posted field as `name='<json>'`, top-level keys matching /pass/i
     * masked with `*`, joined with ", ".
     *
     * @param  array<string, mixed>  $post  form field => decoded value (arrays are JSON-encoded)
     */
    public static function apiLog(int $time, string $uri, string $method, string $remote, array $post): void
    {
        self::raw('API_LOG', (string) json_encode(['time' => $time, 'uri' => $uri, 'method' => $method, 'remote' => $remote, 'data' => self::apiLogData($post)]));
    }

    /**
     * @param  array<string, mixed>  $post
     */
    public static function apiLogData(array $post): string
    {
        $parts = [];

        foreach ($post as $name => $value) {
            if ($name === 'csrf_token') {
                continue;
            }

            if (is_array($value)) {
                unset($value['csrf_token']);

                foreach ($value as $key => $v) {
                    if (preg_match('/pass/i', (string) $key) === 1) {
                        $value[$key] = '*';
                    }
                }

                $value = json_encode($value);
            }

            $parts[] = $name."='".$value."'";
        }

        return implode(', ', $parts);
    }

    // ---- Ban service keys -----------------------------------------------------------------

    /**
     * F2B_OPTIONS as netfilter re-stores it (`json.dumps`), or as legacy PHP writes it.
     *
     * @param  array<string, mixed>  $options
     */
    public static function f2bOptions(array $options, bool $python = true): void
    {
        Redis::connection('mailcow')->command('set', ['F2B_OPTIONS', $python ? self::pythonJson($options) : (string) json_encode($options)]);
    }

    /** The netfilter defaults with a ban list ID, as netfilter stores them on its first run. */
    public static function netfilterDefaults(string $banListId = '1b6c4e1e-7b0f-4c9e-9a65-0f6a6c1c8f11'): void
    {
        self::f2bOptions([
            'ban_time' => 1800, 'max_ban_time' => 10000, 'ban_time_increment' => true, 'max_attempts' => 10,
            'retry_window' => 600, 'netban_ipv4' => 32, 'netban_ipv6' => 128, 'banlist_id' => $banListId,
            'manage_external' => 0,
        ]);
    }

    /**
     * @param  list<string>  $entries
     */
    public static function f2bList(string $key, array $entries): void
    {
        $redis = Redis::connection('mailcow');
        $redis->command('del', [$key]);

        foreach ($entries as $entry) {
            $redis->command('hset', [$key, $entry, '1']);
        }
    }

    public static function activeBan(string $network, int $endsAt): void
    {
        Redis::connection('mailcow')->command('hset', ['F2B_ACTIVE_BANS', $network, (string) $endsAt]);
    }

    public static function queuedUnban(string $network): void
    {
        Redis::connection('mailcow')->command('hset', ['F2B_QUEUE_UNBAN', $network, '1']);
    }

    public static function permanentBan(string $network, int $startedAt): void
    {
        Redis::connection('mailcow')->command('hset', ['F2B_PERM_BANS', $network, (string) $startedAt]);
    }

    /**
     * F2B_REGEX as netfilter stores its defaults (`json.dumps` of a dict with integer keys).
     *
     * @param  list<string>  $rules
     */
    public static function f2bRegex(array $rules): void
    {
        $numbered = [];

        foreach ($rules as $i => $rule) {
            $numbered[(string) ($i + 1)] = $rule;
        }

        Redis::connection('mailcow')->command('set', ['F2B_REGEX', self::pythonJson($numbered)]);
    }

    /**
     * netfilter's nine default rules (main.py refreshF2bregex()).
     *
     * @return list<string>
     */
    public static function defaultRules(): array
    {
        return [
            'mailcow UI: Invalid password for .+ by ([0-9a-f\.:]+)',
            'Rspamd UI: Invalid password by ([0-9a-f\.:]+)',
            'warning: .*\[([0-9a-f\.:]+)\]: SASL .+ authentication failed: (?!.*Connection lost to authentication server).+',
            'warning: non-SMTP command from .*\[([0-9a-f\.:]+)]:.+',
            'NOQUEUE: reject: RCPT from \[([0-9a-f\.:]+)].+Protocol error.+',
            '\w+\([^,]+,([0-9a-f\.:]+),<[^>]+>\): Password mismatch \(SHA1 of given password: [a-f0-9]+\)',
            '\w+\([^,]+,([0-9a-f\.:]+),<[^>]+>\): unknown user \(SHA1 of given password: [a-f0-9]+\)',
            'SOGo.+ Login from \'([0-9a-f\.:]+)\' for user .+ might not have worked',
            '([0-9a-f\.:]+) \"GET \/SOGo\/.* HTTP.+\" 403 .+',
        ];
    }

    public static function get(string $key): mixed
    {
        return Redis::connection('mailcow')->command('get', [$key]);
    }

    /**
     * @return array<string, string>
     */
    public static function hash(string $key): array
    {
        $value = Redis::connection('mailcow')->command('hgetall', [$key]);

        return is_array($value) ? $value : [];
    }

    /**
     * Python's `json.dumps(obj, ensure_ascii=False)` of a flat dict: `", "` between members and
     * `": "` after keys.
     *
     * @param  array<int|string, mixed>  $object
     */
    public static function pythonJson(array $object): string
    {
        $members = [];

        foreach ($object as $key => $value) {
            $members[] = json_encode((string) $key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).': '.json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return '{'.implode(', ', $members).'}';
    }
}
