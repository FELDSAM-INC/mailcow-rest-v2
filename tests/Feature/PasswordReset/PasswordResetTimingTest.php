<?php

namespace Tests\Feature\PasswordReset;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T052 (feature 005 FR-020, SC-005): the acknowledgement of a reset request is identical, and takes
 * the same time, whatever the address. The account lookup, the token and the message are deferred
 * until after the response, so a slow mail server never reaches the client. The time is measured
 * to the end of the response; the deferred work runs afterwards and is not part of it.
 */
#[Group('performance')]
class PasswordResetTimingTest extends TestCase
{
    use SelfServiceTestHelpers;

    private const RUNS = 100;

    public function test_the_acknowledgement_does_not_reveal_the_account(): void
    {
        Mail::extend('slow', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                usleep(100_000);
            }

            public function __toString(): string
            {
                return 'slow://';
            }
        });
        config([
            'mail.default' => 'slow',
            'mail.mailers.slow' => ['transport' => 'slow'],
            'restv2.pw_reset_max_attempts' => 10 * self::RUNS,
            'restv2.vars_overrides.PW_RESET_TOKEN_LIMIT' => (string) (2 * self::RUNS),
        ]);
        $this->configureResetMessage();
        $this->mailcow()->mailboxWithDomain('anna@example.org', ['recovery_email' => 'anna.private@example.net']);
        $this->mailcow()->mailboxWithDomain('norecovery@example.org');
        $token = $this->csrfToken();

        $bodies = [];
        $medians = [];

        foreach (['eligible' => 'anna@example.org', 'ineligible' => 'norecovery@example.org', 'unknown' => 'nobody@example.org'] as $class => $address) {
            $times = [];

            for ($i = 0; $i < self::RUNS; $i++) {
                [$elapsed, $body] = $this->timedRequest($address, $token);
                $times[] = $elapsed;
                $bodies[$class] = $body;
            }

            sort($times);
            $medians[$class] = $times[intdiv(self::RUNS, 2)];
        }

        $this->assertSame($bodies['eligible'], $bodies['ineligible']);
        $this->assertSame($bodies['eligible'], $bodies['unknown']);
        $this->assertLessThan(0.05, max($medians) - min($medians), 'Medians: '.json_encode($medians));
    }

    /**
     * Sends one request through the kernel and measures until the response is complete; the
     * deferred callbacks run in terminate(), outside the measurement.
     *
     * @return array{float, string}
     */
    private function timedRequest(string $address, string $token): array
    {
        $kernel = $this->app->make(Kernel::class);
        $request = Request::create(
            'https://mail.example.org/api/v2/password-resets',
            'POST',
            [],
            [$this->sessionCookieName() => (string) $this->sessionCookie()],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            (string) json_encode(['address' => $address]),
        );

        $start = hrtime(true);
        $response = $kernel->handle($request);
        $content = (string) $response->getContent();
        $elapsed = (hrtime(true) - $start) / 1e9;
        $kernel->terminate($request, $response);

        $this->assertSame(200, $response->getStatusCode(), $content);

        return [$elapsed, $content];
    }
}
