<?php

namespace App\Clients\Yubico;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Port of the verification of legacy `inc/lib/Yubico.php` (Auth_Yubico 2.0, BSD) onto Laravel's
 * HTTP client (R11, protocol version 2.0):
 *
 *  - `GET <RESTV2_YUBICO_VERIFY_URL>?id&nonce&otp&h`, where `h` is the base64 HMAC-SHA1 of the
 *    sorted query, keyed with the base64-decoded API key; 5 s timeout;
 *  - only `status=OK` with a valid response signature and the echoed OTP and nonce is valid;
 *  - `BACKEND_ERROR`, `NOT_ENOUGH_ANSWERS`, HTTP errors, connection errors and timeouts are
 *    temporary failures; every other answer is invalid.
 */
class HttpYubicoOtpVerifier implements YubicoOtpVerifier
{
    public const TIMEOUT_SECONDS = 5;

    /** Response fields covered by the response signature (legacy's list). */
    private const SIGNED_FIELDS = ['nonce', 'otp', 'sessioncounter', 'sessionuse', 'sl', 'status', 't', 'timeout', 'timestamp'];

    private const TEMPORARY_STATUSES = ['BACKEND_ERROR', 'NOT_ENOUGH_ANSWERS'];

    public function verify(string $clientId, string $key, string $otp): YubicoVerificationResult
    {
        $secret = (string) base64_decode($key);
        $params = ['id' => $clientId, 'nonce' => bin2hex(random_bytes(16)), 'otp' => $otp];
        ksort($params);

        if ($secret !== '') {
            $params['h'] = base64_encode(hash_hmac('sha1', self::join($params), $secret, true));
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->get((string) config('restv2.yubico_verify_url'), $params);
        } catch (ConnectionException $e) {
            Log::warning('The Yubico verification service is unreachable: '.$e->getMessage());

            return YubicoVerificationResult::TemporaryFailure;
        }

        if (! $response->successful()) {
            Log::warning('The Yubico verification service answered HTTP '.$response->status().'.');

            return YubicoVerificationResult::TemporaryFailure;
        }

        $answer = self::parse($response->body());
        $status = $answer['status'] ?? null;

        if ($status === null || in_array($status, self::TEMPORARY_STATUSES, true)) {
            return YubicoVerificationResult::TemporaryFailure;
        }

        if (($answer['otp'] ?? null) !== $params['otp'] || ($answer['nonce'] ?? null) !== $params['nonce']) {
            return YubicoVerificationResult::Invalid;
        }

        if ($secret !== '' && ! self::signatureMatches($answer, $secret)) {
            return YubicoVerificationResult::Invalid;
        }

        return $status === 'OK' ? YubicoVerificationResult::Valid : YubicoVerificationResult::Invalid;
    }

    /**
     * @param  array<string, string>  $answer
     */
    private static function signatureMatches(array $answer, string $secret): bool
    {
        $signed = array_intersect_key($answer, array_flip(self::SIGNED_FIELDS));
        ksort($signed);
        $expected = base64_encode(hash_hmac('sha1', self::join($signed), $secret, true));

        return isset($answer['h']) && hash_equals($expected, $answer['h']);
    }

    /**
     * `key=value` lines; only the first `=` separates (base64 values contain `=`).
     *
     * @return array<string, string>
     */
    private static function parse(string $body): array
    {
        $answer = [];

        foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$name, $value] = explode('=', $line, 2);
                $answer[trim($name)] = trim($value);
            }
        }

        return $answer;
    }

    /**
     * @param  array<string, string>  $values
     */
    private static function join(array $values): string
    {
        return implode('&', array_map(fn (string $k, string $v): string => "{$k}={$v}", array_keys($values), $values));
    }
}
