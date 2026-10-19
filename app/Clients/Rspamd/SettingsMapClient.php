<?php

namespace App\Clients\Rspamd;

/**
 * mailcow's generated Rspamd settings map (feature 013 research R12): the text php-fpm-mailcow's
 * `settings.php` serves to Rspamd, read as legacy's admin page reads it. Faked in tests.
 */
interface SettingsMapClient
{
    /**
     * The settings map as Rspamd receives it.
     *
     * @throws RspamdUnavailable when the endpoint cannot be reached, times out or does not answer 200
     */
    public function fetch(): string;
}
