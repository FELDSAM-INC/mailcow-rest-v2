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
     * A key of the API's Redis connection (`restv2:` prefix added).
     */
    protected function restv2Redis(string $command, string ...$arguments): mixed
    {
        return Redis::connection('restv2')->command($command, $arguments);
    }
}
