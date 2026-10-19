<?php

namespace App\Services\Settings;

use Closure;
use Redis;

/**
 * One planned change of settings in mailcow's Redis (research R1): the commands that apply it on
 * the MULTI client, the commands that restore the values read before it (used when the audit
 * record's commit fails after EXEC), and what the audit record needs.
 */
final readonly class SettingsWrite
{
    /**
     * @param  Closure(Redis): mixed  $apply
     * @param  Closure(Redis): mixed  $restore
     */
    public function __construct(
        public Closure $apply,
        public Closure $restore,
        public mixed $audit = null,
    ) {}
}
