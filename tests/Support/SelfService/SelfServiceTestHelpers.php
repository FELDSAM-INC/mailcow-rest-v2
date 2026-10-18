<?php

namespace Tests\Support\SelfService;

use App\Auth\Role;
use App\Services\Auth\SessionManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\Mailboxes\MailboxTestHelpers;

/**
 * Callers, rows and lookups shared by the tests of feature 005. Used in Tests\TestCase subclasses.
 */
trait SelfServiceTestHelpers
{
    use AuthRequests;
    use MailboxTestHelpers;

    /**
     * A signed-in mailbox user of a new mailcow-source mailbox.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, int|bool>  $userAcl
     */
    protected function asSelfServiceUser(string $username = 'anna@example.org', array $attributes = [], array $userAcl = []): string
    {
        if (! DB::table('mailbox')->where('username', $username)->exists()) {
            $this->mailcow()->mailboxWithDomain($username, $attributes, userAcl: $userAcl);
        }

        return $this->signInAs($username, Role::MailboxUser);
    }

    /**
     * The decoded `attributes` of an `admin` row.
     *
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    protected function adminAttributes(string $username): array
    {
        return (array) json_decode((string) DB::table('admin')->where('username', $username)->value('attributes'), true);
    }

    /**
     * Whether a session still exists in the session store.
     */
    protected function sessionExists(string $sessionId): bool
    {
        return $this->app['session']->driver()->getHandler()->read($sessionId) !== '';
    }

    /**
     * The handle of a stored session (SessionManager::HANDLE).
     */
    protected function sessionHandle(string $sessionId): ?string
    {
        $store = new Store($this->sessionCookieName(), $this->app['session']->driver()->getHandler(), $sessionId, 'json');
        $store->start();
        $handle = $store->get(SessionManager::HANDLE);

        return is_string($handle) ? $handle : null;
    }

    /**
     * `PUT /me/password` with the session's anti-forgery token.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    protected function changeOwnPassword(array $body): TestResponse
    {
        return $this->putJson('/api/v2/me/password', $body, $this->csrf());
    }

    /**
     * Sets a field of mailcow's password policy hash (`PASSWD_POLICY`).
     */
    protected function passwordPolicy(int $length, bool $chars = false, bool $numbers = false, bool $special = false, bool $lowerUpper = false): void
    {
        $this->redis()->hMSet('PASSWD_POLICY', [
            'length' => (string) $length,
            'chars' => $chars ? '1' : '0',
            'numbers' => $numbers ? '1' : '0',
            'special_chars' => $special ? '1' : '0',
            'lowerupper' => $lowerUpper ? '1' : '0',
        ]);
    }

    /**
     * Configures the reset message as the legacy UI saves it (`PW_RESET_*` in mailcow's Redis).
     */
    protected function configureResetMessage(string $from = 'noreply@example.org', string $subject = 'Reset your {{hostname}} password', string $html = '', string $text = ''): void
    {
        $this->redis()->set('PW_RESET_FROM', $from);
        $this->redis()->set('PW_RESET_SUBJ', $subject);
        $this->redis()->set('PW_RESET_HTML', $html);
        $this->redis()->set('PW_RESET_TEXT', $text);
    }

    /**
     * The messages the `array` mailer collected.
     *
     * @return list<Email>
     */
    protected function sentMessages(): array
    {
        $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();
        $messages = [];

        if (! $transport instanceof ArrayTransport) {
            return [];
        }

        foreach ($transport->messages() as $sent) {
            $original = $sent->getOriginalMessage();

            if ($original instanceof Email) {
                $messages[] = $original;
            }
        }

        return $messages;
    }

    /**
     * `POST /me/webmail-handoff`; returns the response and the legacy session ID of its cookie.
     *
     * @return array{TestResponse<Response>, string|null}
     */
    protected function webmailHandOff(): array
    {
        $response = $this->postEmpty('/api/v2/me/webmail-handoff', $this->csrf());
        $id = null;

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'MCSESSID' && ! $cookie->isCleared()) {
                $id = (string) $cookie->getValue();
            }
        }

        return [$response, $id];
    }

    /**
     * The decoded legacy PHP session `PHPREDIS_SESSION:<id>` in mailcow's Redis, or null.
     *
     * @return array<string, mixed>|null
     */
    protected function legacySession(string $id): ?array
    {
        $raw = $this->redis()->get('PHPREDIS_SESSION:'.$id);

        if (! is_string($raw)) {
            return null;
        }

        // PHP's own session_decode() in a subprocess, with legacy's `php` serialiser.
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $process = new Process([$php, '-n', '-d', 'session.serialize_handler=php', '-d', 'session.save_handler=files', '-d', 'session.save_path='.sys_get_temp_dir(), '-d', 'session.use_cookies=0', '-d', 'session.cache_limiter=', '-r', 'session_start(); session_decode(base64_decode($argv[1])); echo base64_encode(serialize($_SESSION));', base64_encode($raw)]);
        $process->mustRun();
        $session = unserialize((string) base64_decode($process->getOutput()), ['allowed_classes' => false]);
        $this->assertIsArray($session);

        return $session;
    }

    /**
     * A key of the API's Redis connection (`restv2:` prefix added).
     */
    protected function restv2Redis(string $command, string ...$arguments): mixed
    {
        return Redis::connection('restv2')->command($command, $arguments);
    }
}
