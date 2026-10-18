<?php

namespace Tests\Unit\LegacySession;

use App\Support\LegacySession\PhpSessionEncoder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * T009 (feature 005 research R3): the webmail hand-off writes a legacy PHP session that
 * sogo-auth.php decodes with PHP's `php` session serialiser, so the encoding must equal PHP's own
 * session_encode() byte for byte.
 */
class PhpSessionEncoderTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function sessions(): array
    {
        return [
            'strings and integers' => [['mailcow_cc_username' => 'anna@example.org', 'LAST_ACTIVITY' => 1760000000]],
            'nested arrays' => [['CSRF' => ['TOKEN' => str_repeat('ab', 32)], 'list' => ['a', 'b', ['c' => 1]]]],
            'unicode, booleans and null' => [['name' => 'Žluťoučký kůň', 'flag' => true, 'off' => false, 'none' => null, 'f' => 1.5]],
            'hand-off payload' => [self::handOff()],
        ];
    }

    /**
     * @param  array<string, mixed>  $session
     */
    #[DataProvider('sessions')]
    public function test_the_encoding_equals_php_session_encode(array $session): void
    {
        $this->assertSame($this->sessionEncode($session), PhpSessionEncoder::encode($session));
    }

    public function test_keys_containing_a_pipe_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PhpSessionEncoder::encode(['bad|key' => 'x']);
    }

    public function test_the_hand_off_payload_decodes_to_exactly_its_six_keys(): void
    {
        $decoded = $this->sessionDecode(PhpSessionEncoder::encode(self::handOff()));

        $this->assertSame(['mailcow_cc_username', 'sogo-sso-user-allowed', 'mailcow_cc_api_access', 'SESS_REMOTE_UA', 'LAST_ACTIVITY', 'CSRF'], array_keys($decoded));
        $this->assertSame(self::handOff(), $decoded);
        $this->assertArrayNotHasKey('mailcow_cc_role', $decoded);
    }

    /**
     * @return array<string, mixed>
     */
    private static function handOff(): array
    {
        return [
            'mailcow_cc_username' => 'anna@example.org',
            'sogo-sso-user-allowed' => ['anna@example.org'],
            'mailcow_cc_api_access' => 'ro',
            'SESS_REMOTE_UA' => 'Mozilla/5.0 (X11; Linux x86_64)',
            'LAST_ACTIVITY' => 1760000000,
            'CSRF' => ['TOKEN' => str_repeat('0f', 32)],
        ];
    }

    /**
     * PHP's own session_encode() in a subprocess with the `php` serialiser (the legacy default).
     *
     * @param  array<string, mixed>  $session
     */
    private function sessionEncode(array $session): string
    {
        return $this->php('session_start(); $_SESSION = unserialize(base64_decode($argv[1])); echo base64_encode(session_encode());', base64_encode(serialize($session)));
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionDecode(string $encoded): array
    {
        $result = unserialize(($this->php('session_start(); session_decode(base64_decode($argv[1])); echo base64_encode(serialize($_SESSION));', base64_encode($encoded))));
        $this->assertIsArray($result);

        return $result;
    }

    private function php(string $code, string $argument): string
    {
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $process = new Process([$php, '-n', '-d', 'session.serialize_handler=php', '-d', 'session.save_handler=files', '-d', 'session.save_path='.sys_get_temp_dir(), '-d', 'session.use_cookies=0', '-d', 'session.cache_limiter=', '-r', $code, $argument]);
        $process->mustRun();

        return (string) base64_decode($process->getOutput());
    }
}
