<?php

namespace Tests\Support\Quarantine;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mail\FakeQuarantineReleaseMailer;
use Tests\Support\Rspamd\FakeRspamdController;

/**
 * Callers, rows, requests and lookups shared by the tests of feature 006. Used in Tests\TestCase
 * subclasses; every 006 test calls setUpSpamFilter() in setUp(), which binds the Rspamd and
 * release fakes (useFakeSpamFilter(), useFakeReleaseMailer()).
 */
trait QuarantineTestHelpers
{
    use AliasTestHelpers;
    use ApiKeys;

    /** The user_acl flags of a mailbox created by the tests (all off unless given). */
    protected const NO_USER_FLAGS = [
        'quarantine' => 0, 'quarantine_attachments' => 0, 'quarantine_notification' => 0,
        'quarantine_category' => 0, 'spam_score' => 0, 'spam_policy' => 0,
    ];

    protected const ALL_USER_FLAGS = [
        'quarantine' => 1, 'quarantine_attachments' => 1, 'quarantine_notification' => 1,
        'quarantine_category' => 1, 'spam_score' => 1, 'spam_policy' => 1,
    ];

    protected FakeRspamdController $spamFilter;

    protected FakeQuarantineReleaseMailer $mailer;

    protected function setUpSpamFilter(): void
    {
        $this->spamFilter = $this->useFakeSpamFilter();
        $this->mailer = $this->useFakeReleaseMailer();
    }

    /**
     * A mailbox with its domain and the given quarantine and spam flags (`user_acl`).
     *
     * @param  array<string, int>  $flags
     */
    protected function quarantineMailbox(string $address, array $flags = self::ALL_USER_FLAGS, string $kind = ''): void
    {
        $this->mailcow()->ensureDomain(substr((string) strrchr($address, '@'), 1));
        $this->mailcow()->mailbox($address, scheme: 'SSHA256', kind: $kind, userAcl: $kind === '' ? array_merge(self::NO_USER_FLAGS, $flags) : []);
    }

    /**
     * A mailbox user signed in, with the given flags.
     *
     * @param  array<string, int>  $flags
     */
    protected function asQuarantineUser(string $address = 'alice@example.org', array $flags = self::ALL_USER_FLAGS): void
    {
        if (! DB::table('mailbox')->where('username', $address)->exists()) {
            $this->quarantineMailbox($address, $flags);
        }

        $this->signInAs($address, Role::MailboxUser);
    }

    /**
     * A quarantine item as the ingestion stores it, with a message fixture of
     * tests/fixtures/quarantine/messages (or the given message).
     *
     * @param  array<string, mixed>  $overrides  named arguments of MailcowRows::quarantineItem()
     */
    protected function item(string $rcpt, string $fixture = 'plain', array $overrides = []): int
    {
        return $this->mailcow()->quarantineItem($rcpt, ...array_merge(['msg' => LargeMessage::fixture($fixture)], $overrides));
    }

    /**
     * @phpstan-impure
     */
    protected function itemRow(int $id): ?stdClass
    {
        $row = DB::table('quarantine')->where('id', $id)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * A value in mailcow's shared store (connection `mailcow`, no prefix).
     */
    protected function sharedValue(string $key, ?string $value): void
    {
        $value === null ? $this->redis()->del($key) : $this->redis()->set($key, $value);
    }

    /**
     * Sends a JSON request with the anti-forgery token of the signed-in session (none for a key).
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function send(string $method, string $uri, ?array $body = null, array $headers = []): TestResponse
    {
        if (! isset($headers['X-API-Key']) && $method !== 'GET') {
            $headers += $this->csrf();
        }

        return match (true) {
            $method === 'PATCH' => $this->patchMerge($uri, $body ?? [], $headers),
            $body === null && $method === 'POST' => $this->postEmpty($uri, $headers),
            $body === null => $this->json($method, $uri, [], $headers),
            default => $this->json($method, $uri, $body, $headers),
        };
    }

    /**
     * Points the `restv2` Redis connection (locks, sessions) at a closed port.
     */
    protected function breakApiStore(): void
    {
        config(['database.redis.restv2.port' => 1]);
        $this->app->forgetInstance('redis');
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        Redis::clearResolvedInstances();
    }
}
