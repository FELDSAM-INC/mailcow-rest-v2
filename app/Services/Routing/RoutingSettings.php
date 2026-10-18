<?php

namespace App\Services\Routing;

use App\Rules\RoutingEmail;
use Illuminate\Support\Facades\Log;

/**
 * The server settings of feature 009 (research R21, config `restv2.routing`), read on every call so
 * that a changed setting applies to the next request (SC-012). An invalid value (a non-address, a
 * timeout below 1 second, a total below the step timeout) falls back to the legacy default, with
 * one logged warning per process and setting.
 */
class RoutingSettings
{
    public const DEFAULT_TEST_SENDER = 'relay@example.org';

    public const DEFAULT_TEST_RECIPIENT = 'null@hosted.mailcow.de';

    public const DEFAULT_STEP_TIMEOUT = 15;

    public const DEFAULT_TOTAL_TIMEOUT = 60;

    public const SPF_LOOKUP_LIMIT = 10;

    /** @var array<string, true> */
    private static array $warned = [];

    public function testSender(): string
    {
        return $this->address('test_sender', 'RESTV2_ROUTING_TEST_SENDER', self::DEFAULT_TEST_SENDER);
    }

    public function testRecipient(): string
    {
        return $this->address('test_recipient', 'RESTV2_ROUTING_TEST_RECIPIENT', self::DEFAULT_TEST_RECIPIENT);
    }

    /** Seconds per network step of a connection test. */
    public function stepTimeout(): int
    {
        return $this->seconds('test_step_timeout', 'RESTV2_ROUTING_TEST_STEP_TIMEOUT', self::DEFAULT_STEP_TIMEOUT);
    }

    /** Seconds for a whole connection test; never below the step timeout. */
    public function totalTimeout(): int
    {
        $total = $this->seconds('test_total_timeout', 'RESTV2_ROUTING_TEST_TOTAL_TIMEOUT', self::DEFAULT_TOTAL_TIMEOUT);

        if ($total < $this->stepTimeout()) {
            $this->warn('test_total_timeout', 'RESTV2_ROUTING_TEST_TOTAL_TIMEOUT is below the step timeout; the default applies.');

            return max(self::DEFAULT_TOTAL_TIMEOUT, $this->stepTimeout());
        }

        return $total;
    }

    /** DNS lookups of a forwarding host's SPF evaluation (FR-076). */
    public function spfLookupLimit(): int
    {
        $limit = (int) config('restv2.routing.spf_lookup_limit', self::SPF_LOOKUP_LIMIT);

        return $limit >= 1 ? $limit : self::SPF_LOOKUP_LIMIT;
    }

    private function address(string $key, string $variable, string $default): string
    {
        $value = config('restv2.routing.'.$key);
        $address = is_string($value) ? RoutingEmail::normalize($value) : null;

        if ($address === null) {
            $this->warn($key, "{$variable} is not a valid e-mail address; the default {$default} applies.");

            return $default;
        }

        return $address;
    }

    private function seconds(string $key, string $variable, int $default): int
    {
        $value = config('restv2.routing.'.$key);

        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1)) {
            $seconds = (int) $value;

            if ($seconds >= 1) {
                return $seconds;
            }
        }

        $this->warn($key, "{$variable} must be a whole number of seconds of at least 1; the default {$default} applies.");

        return $default;
    }

    private function warn(string $key, string $message): void
    {
        if (! isset(self::$warned[$key])) {
            self::$warned[$key] = true;
            Log::warning($message);
        }
    }
}
