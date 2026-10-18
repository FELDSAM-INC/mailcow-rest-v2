<?php

namespace App\Clients\Fail2Ban;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes to mailcow's Redis channel F2B_CHANNEL exactly the text netfilter matches
 * (`mailcow UI: Invalid password for .+ by ([0-9a-f\.:]+)`), research R18:
 *
 *  - `mailcow UI: Invalid password for <username> by <client address>`, or `API_USER` for keys;
 *  - the address in canonical form (lower-case, compressed IPv6);
 *  - control characters in the username replaced by `?` (a newline could otherwise inject a line
 *    that bans an arbitrary address) and the username cut to 255 bytes.
 *
 * The message is also logged at warning level, as legacy calls error_log. When Redis is
 * unreachable, the failure is logged and the caller continues.
 */
class RedisFail2BanReporter implements Fail2BanReporter
{
    private const CHANNEL = 'F2B_CHANNEL';

    public function __construct(private readonly RedisFactory $redis) {}

    public function invalidPassword(string $username, string $ip): void
    {
        $this->publish('mailcow UI: Invalid password for '.$this->sanitize($username).' by '.$this->canonical($ip));
    }

    public function invalidApiKey(string $ip): void
    {
        $this->publish('mailcow UI: Invalid password for API_USER by '.$this->canonical($ip));
    }

    private function publish(string $message): void
    {
        Log::warning($message);

        try {
            $this->redis->connection('mailcow')->publish(self::CHANNEL, $message);
        } catch (Throwable $e) {
            Log::error('The fail2ban report could not be published to '.self::CHANNEL.': '.$e->getMessage());
        }
    }

    private function sanitize(string $username): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $username);

        return mb_check_encoding($clean, 'UTF-8') ? mb_strcut($clean, 0, 255, 'UTF-8') : substr($clean, 0, 255);
    }

    private function canonical(string $ip): string
    {
        $packed = @inet_pton($ip);

        return $packed === false ? $this->sanitize($ip) : (string) inet_ntop($packed);
    }
}
