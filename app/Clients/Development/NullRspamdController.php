<?php

namespace App\Clients\Development;

use App\Clients\Rspamd\RspamdController;
use App\Clients\Rspamd\RspamdUnavailable;

/**
 * Development stand-in for the Rspamd controller (RESTV2_FAKE_OUTBOUND=y with APP_ENV=local): the
 * dev stack runs no Rspamd, so the reads answer the recorded payloads of tests/fixtures/monitoring.
 */
final class NullRspamdController implements RspamdController
{
    public function stat(): array
    {
        return self::fixture('rspamd-stat.json');
    }

    public function history(): array
    {
        return self::fixture('rspamd-history.json');
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        $data = json_decode((string) @file_get_contents(base_path('tests/fixtures/monitoring/'.$name)), true);

        return is_array($data) ? $data : throw new RspamdUnavailable("The recorded payload {$name} is missing (development stand-in).");
    }
}
