<?php

namespace App\Services\Settings;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Models\Mailcow\SchemaVersion;
use App\Services\Audit\AuditLogger;
use App\Support\Clock\Clock;
use App\Support\Http\ProblemType;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Redis;

/**
 * The server's GUID and its verification against mailcow's license service (feature 013 FR-082 to
 * FR-085, research R20).
 *
 * The GUID is the `versions` row `GUID`, which mailcow computes; `INVALID`, a missing row or any
 * value that is not 64 hexadecimal characters reads as `guid: null`, `guidValid: false` and can
 * still be verified, as in legacy.
 *
 * Verifying posts only the form field `guid` to `RESTV2_LICENSE_VERIFY_URL` (connect timeout 10 s,
 * total 20 s), as legacy's license('verify') does, and stores the result where the legacy dashboard
 * reads it: `LICENSE_STATUS_CACHE` = `json_encode({valid: "true"|"false", c, s, m})` with legacy's
 * en-gb texts (a valid GUID: the customer and service IDs and `m` as that many cows; invalid:
 * `mailbox.no` three times; unreachable or an unreadable answer: `danger.temp_error` three times).
 * The API's own record `restv2:license:last-verification` adds the time and the hash of the cache
 * value it wrote, in the same MULTI as the cache and with the audit record (RedisSettingsStore).
 *
 * Reading never contacts the service. A cache value whose hash differs from the API's record was
 * written by legacy and is read as legacy shows it, without a time; `valid: "false"` is
 * `unreachable` when its text is `danger.temp_error` in any of mailcow's languages.
 *
 * `RESTV2_LICENSE_VERIFICATION=false` refuses both operations with `403 feature-disabled`.
 */
class LicenseService
{
    public const CACHE_KEY = 'LICENSE_STATUS_CACHE';

    public const RECORD_KEY = 'restv2:license:last-verification';

    /** Legacy's en-gb `mailbox.no`, written for an invalid GUID. */
    public const INVALID_TEXT = '&#10005;';

    /** Legacy's en-gb `danger.temp_error`, written when the service could not be asked. */
    public const TEMPORARY_ERROR_TEXT = 'Temporary error';

    private const COW = "\u{1F404}";

    /** @var list<string>|null */
    private ?array $temporaryErrorTexts = null;

    public function __construct(
        private readonly RedisSettingsStore $store,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @throws ProblemException `403 feature-disabled`
     */
    public static function refuseWhenDisabled(): void
    {
        if (config('restv2.license.enabled') !== true) {
            throw new ProblemException(ProblemType::FeatureDisabled, 'License verification is disabled on this server.');
        }
    }

    /**
     * Contract `LicenseStatus`.
     *
     * @return array{guid: string|null, guidValid: bool, lastVerification: array<string, mixed>}
     */
    public function status(): array
    {
        $raw = $this->store->strings([self::CACHE_KEY, self::RECORD_KEY]);
        $guid = self::guid();
        $valid = self::isValidGuid($guid);

        return ['guid' => $valid ? $guid : null, 'guidValid' => $valid, 'lastVerification' => $this->lastVerification($raw[self::CACHE_KEY], $raw[self::RECORD_KEY])];
    }

    /**
     * Asks the license service now and stores its answer.
     *
     * @return array{guid: string|null, guidValid: bool, lastVerification: array<string, mixed>}
     */
    public function verify(Principal $actor): array
    {
        // Fails with `503` before the service is contacted when the shared store is down.
        $this->store->strings([self::CACHE_KEY]);
        [$result, $customer, $service, $level] = $this->ask((string) self::guid());

        $cache = (string) json_encode(match ($result) {
            'valid' => ['valid' => 'true', 'c' => $customer, 's' => $service, 'm' => str_repeat(self::COW, (int) $level)],
            'invalid' => ['valid' => 'false', 'c' => self::INVALID_TEXT, 's' => self::INVALID_TEXT, 'm' => self::INVALID_TEXT],
            default => ['valid' => 'false', 'c' => self::TEMPORARY_ERROR_TEXT, 's' => self::TEMPORARY_ERROR_TEXT, 'm' => self::TEMPORARY_ERROR_TEXT],
        });
        $record = (string) json_encode([
            'result' => $result,
            'verifiedAt' => $this->clock->now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'customerId' => $customer,
            'serviceId' => $service,
            'supportLevel' => $level,
            'cacheHash' => hash('sha256', $cache),
        ]);

        $this->store->transaction(
            [self::CACHE_KEY, self::RECORD_KEY],
            null,
            fn (Redis $redis): array => RedisSettingsStore::stringsFrom($redis, [self::CACHE_KEY, self::RECORD_KEY]),
            fn (array $raw): string => '',
            fn (array $raw): SettingsWrite => new SettingsWrite(
                fn (Redis $multi) => $multi->mSet([self::CACHE_KEY => $cache, self::RECORD_KEY => $record]),
                function (Redis $multi) use ($raw): void {
                    foreach ($raw as $key => $value) {
                        $value === null ? $multi->del($key) : $multi->set($key, $value);
                    }
                },
                ['result' => $result],
            ),
            fn (mixed $details) => $this->audit->record(AuditEventType::LicenseVerification, true, $actor, ['type' => 'license', 'id' => null, 'name' => null], null, null, (array) $details),
        );

        return $this->status();
    }

    /**
     * The service's answer: the result, and for a valid GUID the customer ID, service ID and
     * support level (`NoMoore` is one, otherwise the number of `o` in `m`, as legacy counts cows).
     *
     * @return array{string, string|null, string|null, int|null}
     */
    private function ask(string $guid): array
    {
        try {
            $response = Http::connectTimeout(10)->timeout(20)->asForm()->post((string) config('restv2.license.verify_url'), ['guid' => $guid]);
        } catch (HttpClientException|GuzzleException) {
            return ['unreachable', null, null, null];
        }

        $answer = $response->body() === '' ? null : json_decode($response->body(), true);

        if (is_array($answer) && ($answer['response'] ?? null) === 'ok') {
            $m = is_scalar($answer['m'] ?? null) ? (string) $answer['m'] : '';
            $level = $m === 'NoMoore' ? 1 : substr_count($m, 'o');

            return ['valid', self::text($answer['c'] ?? null), self::text($answer['s'] ?? null), $level > 0 ? $level : null];
        }

        if (is_array($answer) && ($answer['response'] ?? null) === 'invalid') {
            return ['invalid', null, null, null];
        }

        return ['unreachable', null, null, null];
    }

    /**
     * @return array{result: string, verifiedAt: string|null, customerId: string|null, serviceId: string|null, supportLevel: int|null}
     */
    private function lastVerification(?string $cache, ?string $record): array
    {
        $none = ['result' => 'neverVerified', 'verifiedAt' => null, 'customerId' => null, 'serviceId' => null, 'supportLevel' => null];

        if ($cache === null) {
            return $none;
        }

        $own = $record === null ? null : json_decode($record, true);

        if (is_array($own) && ($own['cacheHash'] ?? null) === hash('sha256', $cache) && in_array($own['result'] ?? null, ['valid', 'invalid', 'unreachable'], true)) {
            return [
                'result' => (string) $own['result'],
                'verifiedAt' => self::text($own['verifiedAt'] ?? null),
                'customerId' => self::text($own['customerId'] ?? null),
                'serviceId' => self::text($own['serviceId'] ?? null),
                'supportLevel' => is_int($own['supportLevel'] ?? null) && $own['supportLevel'] > 0 ? $own['supportLevel'] : null,
            ];
        }

        $legacy = json_decode($cache, true);

        if (! is_array($legacy) || ! isset($legacy['valid'])) {
            return $none;
        }

        if ($legacy['valid'] === 'true' || $legacy['valid'] === true) {
            $cows = mb_substr_count(is_string($legacy['m'] ?? null) ? $legacy['m'] : '', self::COW);

            return ['result' => 'valid', 'verifiedAt' => null, 'customerId' => self::text($legacy['c'] ?? null), 'serviceId' => self::text($legacy['s'] ?? null), 'supportLevel' => $cows > 0 ? $cows : null];
        }

        $text = is_string($legacy['c'] ?? null) ? $legacy['c'] : null;

        return ['result' => $text !== null && in_array($text, $this->temporaryErrorTexts(), true) ? 'unreachable' : 'invalid'] + $none;
    }

    /**
     * `danger.temp_error` of every language mailcow ships, and the en-gb text.
     *
     * @return list<string>
     */
    private function temporaryErrorTexts(): array
    {
        if ($this->temporaryErrorTexts !== null) {
            return $this->temporaryErrorTexts;
        }

        $texts = [self::TEMPORARY_ERROR_TEXT];

        foreach (glob(rtrim((string) config('restv2.mailcow_lang_dir'), '/').'/lang.*.json') ?: [] as $file) {
            $lang = json_decode((string) @file_get_contents($file), true);
            $text = is_array($lang) && is_array($lang['danger'] ?? null) ? ($lang['danger']['temp_error'] ?? null) : null;

            if (is_string($text) && $text !== '') {
                $texts[] = $text;
            }
        }

        return $this->temporaryErrorTexts = array_values(array_unique($texts));
    }

    private static function guid(): ?string
    {
        $value = SchemaVersion::query()->whereKey('GUID')->value('version');

        return is_string($value) ? $value : null;
    }

    private static function isValidGuid(?string $guid): bool
    {
        return $guid !== null && preg_match('/^[0-9a-fA-F]{64}$/', $guid) === 1;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
