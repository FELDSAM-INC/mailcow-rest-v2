<?php

namespace App\Clients\Rspamd;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads mailcow's generated Rspamd settings map from php-fpm's `settings.php` on nginx-mailcow's
 * internal port (feature 013 research R12), as legacy's admin page reads it. Without
 * `If-Modified-Since` the endpoint always answers with the whole map. Connect timeout 5 s, total
 * 10 s; any failure or a status other than 200 is RspamdUnavailable.
 */
class HttpSettingsMapClient implements SettingsMapClient
{
    public function fetch(): string
    {
        try {
            $response = Http::connectTimeout(5)->timeout(10)->get((string) config('restv2.rspamd_settings_map_url'));
        } catch (Throwable $e) {
            throw new RspamdUnavailable('The Rspamd settings map could not be read: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() !== 200) {
            throw new RspamdUnavailable('The Rspamd settings map endpoint answered '.$response->status().'.');
        }

        return $response->body();
    }
}
