<?php

namespace App\Services\Branding;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * The product name of the installation (R15): mailcow's Redis key MAIN_NAME, which legacy stores
 * HTML-encoded, decoded; otherwise the default `RESTV2_DEFAULT_UI_NAME`, or
 * `<lower(MAILCOW_HOSTNAME)> - mail UI` like legacy `customize('get', 'ui_texts')`. Read on every
 * call, without a cache.
 */
class ProductName
{
    public function __construct(private readonly RedisFactory $redis) {}

    public function get(): string
    {
        try {
            $stored = $this->redis->connection('mailcow')->get('MAIN_NAME');
        } catch (Throwable) {
            $stored = null;
        }

        // Legacy treats a falsy value ("" or "0") as unset.
        if (is_string($stored) && $stored !== '' && $stored !== '0') {
            return htmlspecialchars_decode($stored, ENT_QUOTES);
        }

        return self::default();
    }

    public static function default(): string
    {
        $configured = (string) config('restv2.default_ui_name');

        return $configured !== ''
            ? $configured
            : strtolower((string) config('restv2.mailcow_hostname')).' - mail UI';
    }
}
