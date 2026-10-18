<?php

namespace Tests\Unit\Clients;

use App\Clients\Yubico\HttpYubicoOtpVerifier;
use App\Clients\Yubico\YubicoVerificationResult;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T059 (R11): the port of legacy's Auth_Yubico verification onto Laravel's HTTP client.
 */
class HttpYubicoOtpVerifierTest extends TestCase
{
    private const CLIENT_ID = '87654';

    private const KEY = 'c2VjcmV0LWtleS1vZi1rZXktYQ==';

    private const OTP = 'cccccccccccbdteffujehknhfjbrjnlnldnhcujvddbi';

    private const URL = 'https://yubico.test/wsapi/2.0/verify';

    protected function setUp(): void
    {
        parent::setUp();

        config(['restv2.yubico_verify_url' => self::URL]);
    }

    /**
     * A signed answer of the Yubico service to the request.
     *
     * @param  array<string, string>  $overrides
     */
    private function answer(Request $request, string $status, array $overrides = [], ?string $key = self::KEY): string
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $values = array_merge([
            'nonce' => (string) $query['nonce'],
            'otp' => (string) $query['otp'],
            'sl' => '100',
            'status' => $status,
            't' => '2026-10-18T12:00:00Z0123',
        ], $overrides);
        ksort($values);

        $check = implode('&', array_map(fn (string $k, string $v): string => "{$k}={$v}", array_keys($values), $values));
        $h = base64_encode(hash_hmac('sha1', $check, (string) base64_decode((string) $key), true));

        return 'h='.$h."\r\n".implode("\r\n", array_map(fn (string $k, string $v): string => "{$k}={$v}", array_keys($values), $values))."\r\n";
    }

    /**
     * Replaces the HTTP fake (repeated $this->fake() calls would keep the first stub).
     *
     * @param  callable|array<string, mixed>  $callback
     */
    private function fake(callable|array $callback): void
    {
        Http::swap(new Factory($this->app->make(Dispatcher::class)));
        Http::fake($callback);
    }

    private function verify(): YubicoVerificationResult
    {
        return $this->app->make(HttpYubicoOtpVerifier::class)->verify(self::CLIENT_ID, self::KEY, self::OTP);
    }

    public function test_the_request_is_signed_like_legacy(): void
    {
        $this->fake(fn (Request $request) => Http::response($this->answer($request, 'OK')));

        $this->assertSame(YubicoVerificationResult::Valid, $this->verify());

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertStringStartsWith(self::URL.'?', $request->url());
            $this->assertSame('GET', $request->method());
            $this->assertSame(self::CLIENT_ID, $query['id']);
            $this->assertSame(self::OTP, $query['otp']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $query['nonce']);

            $signed = 'id='.$query['id'].'&nonce='.$query['nonce'].'&otp='.$query['otp'];
            $this->assertSame(base64_encode(hash_hmac('sha1', $signed, (string) base64_decode(self::KEY), true)), $query['h']);

            return true;
        });
    }

    public function test_a_nonce_is_used_once(): void
    {
        $this->fake(fn (Request $request) => Http::response($this->answer($request, 'OK')));

        $this->verify();
        $this->verify();

        $nonces = [];
        Http::assertSent(function (Request $request) use (&$nonces): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $nonces[] = $query['nonce'];

            return true;
        });
        $this->assertCount(2, array_unique($nonces));
    }

    public function test_rejected_otps_are_invalid(): void
    {
        foreach (['REPLAYED_OTP', 'BAD_OTP', 'BAD_SIGNATURE', 'NO_SUCH_CLIENT', 'MISSING_PARAMETER', 'OPERATION_NOT_ALLOWED'] as $status) {
            $this->fake(fn (Request $request) => Http::response($this->answer($request, $status)));

            $this->assertSame(YubicoVerificationResult::Invalid, $this->verify(), $status);
        }
    }

    public function test_a_bad_response_signature_is_invalid(): void
    {
        $this->fake(fn (Request $request) => Http::response($this->answer($request, 'OK', [], base64_encode('another key'))));

        $this->assertSame(YubicoVerificationResult::Invalid, $this->verify());
    }

    public function test_a_response_that_does_not_echo_the_otp_or_nonce_is_invalid(): void
    {
        $this->fake(fn (Request $request) => Http::response($this->answer($request, 'OK', ['nonce' => str_repeat('0', 32)])));
        $this->assertSame(YubicoVerificationResult::Invalid, $this->verify());

        $this->fake(fn (Request $request) => Http::response($this->answer($request, 'OK', ['otp' => 'cccccccccccbaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'])));
        $this->assertSame(YubicoVerificationResult::Invalid, $this->verify());
    }

    public function test_service_failures_are_temporary(): void
    {
        $this->fake(fn () => Http::response('Internal error', 500));
        $this->assertSame(YubicoVerificationResult::TemporaryFailure, $this->verify());

        $this->fake(fn (Request $request) => Http::response($this->answer($request, 'BACKEND_ERROR')));
        $this->assertSame(YubicoVerificationResult::TemporaryFailure, $this->verify());

        $this->fake(fn (Request $request) => Http::response($this->answer($request, 'NOT_ENOUGH_ANSWERS')));
        $this->assertSame(YubicoVerificationResult::TemporaryFailure, $this->verify());

        $this->fake(['*' => Http::failedConnection('Connection refused')]);
        $this->assertSame(YubicoVerificationResult::TemporaryFailure, $this->verify());

        $this->fake(['*' => Http::failedConnection('Operation timed out after 5000 milliseconds')]);
        $this->assertSame(YubicoVerificationResult::TemporaryFailure, $this->verify());
    }

    public function test_the_timeout_is_five_seconds(): void
    {
        $this->assertSame(5, HttpYubicoOtpVerifier::TIMEOUT_SECONDS);
    }

    public function test_a_body_without_a_status_is_temporary(): void
    {
        $this->fake(fn () => Http::response("hello\r\n"));

        $this->assertSame(YubicoVerificationResult::TemporaryFailure, $this->verify());
    }
}
