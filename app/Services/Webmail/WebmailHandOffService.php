<?php

namespace App\Services\Webmail;

use App\Audit\AuditEventType;
use App\Auth\AccountKind;
use App\Auth\Principal;
use App\Clients\SharedStore\SharedStore;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\RecentLoginRecorder;
use App\Services\Auth\SessionEndListener;
use App\Services\Auth\SessionManager;
use App\Services\Auth\SessionRegistry;
use App\Services\Config\MailcowConfig;
use App\Services\SelfService\SelfServiceCapabilitiesService;
use App\Support\Http\ProblemType;
use App\Support\LegacySession\PhpSessionEncoder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Redis;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The webmail hand-off (feature 005 FR-067, FR-068, research R3): the one coupling of this API to
 * legacy mailcow's session format that the spec sanctions (plan Complexity Tracking).
 *
 * mailcow's nginx asks legacy `sogo-auth.php` about every webmail request; it grants webmail to a
 * PHP session (phpredis, `PHPREDIS_SESSION:<id>` in mailcow's Redis) whose `mailcow_cc_username` is
 * listed in `sogo-sso-user-allowed`, without looking at a role. The hand-off creates such a session
 * for the requesting browser with exactly six keys and sets legacy's session cookie:
 *
 *  - no `mailcow_cc_role`, so every legacy UI page and form handler treats the browser as signed out
 *    (and `sogo-auth.php?login=` refuses a dual login);
 *  - `mailcow_cc_api_access = 'ro'`, so `/api/v1` refuses every change through it;
 *  - `LAST_ACTIVITY`, so legacy's own idle rule ends an unused hand-off.
 *
 * It ends with the session of this API that created it: at sign-out, invalidation and when another
 * session ends it (SessionEndListener), and within a minute after an idle expiry (the sweeper,
 * restv2:webmail-handoffs:sweep). One hand-off per session: a new one replaces the previous.
 * Records: `restv2:webmail-handoff:<id>` (handle, owner, mailbox, createdAt) and
 * `restv2:session-handoffs:<handle>`. Feature 007 reuses handOff() for webmail on behalf of a user
 * (App\Services\Webmail\WebmailOnBehalfService).
 */
class WebmailHandOffService implements SessionEndListener
{
    public const URL = SelfServiceCapabilitiesService::WEBMAIL_URL;

    private const LEGACY_PREFIX = 'PHPREDIS_SESSION:';

    private const RECORD = 'webmail-handoff:';

    private const SESSION_SET = 'session-handoffs:';

    public function __construct(
        private readonly SharedStore $store,
        private readonly RedisFactory $redis,
        private readonly MailcowConfig $config,
        private readonly RecentLoginRecorder $recentLogins,
        private readonly AuditLogger $audit,
        private readonly SessionManager $sessions,
        private readonly SessionRegistry $registry,
    ) {}

    /**
     * Hands webmail for $mailbox to the browser of $request, on behalf of $creator's session.
     * Feature 007 audits a webmail sign-in on a user's behalf under its own event and target.
     *
     * @param  array{type: string, id?: string|null, name?: string|null}|null  $auditTarget
     *
     * @throws ProblemException groupware-disabled
     */
    public function handOff(Principal $creator, string $mailbox, Request $request, AuditEventType $auditEvent = AuditEventType::WebmailHandOff, ?array $auditTarget = null): WebmailHandOff
    {
        if (config('restv2.skip_sogo')) {
            throw new ProblemException(ProblemType::GroupwareDisabled);
        }

        $handle = $this->sessions->handle($request->session()) ?? throw new ProblemException(ProblemType::SessionRequired);
        $lifetime = $this->config->sessionLifetime();
        $this->store->ping();

        $this->sessionEnded($handle);

        $payload = PhpSessionEncoder::encode([
            'mailcow_cc_username' => $mailbox,
            'sogo-sso-user-allowed' => [$mailbox],
            'mailcow_cc_api_access' => 'ro',
            'SESS_REMOTE_UA' => (string) $request->userAgent(),
            'LAST_ACTIVITY' => CarbonImmutable::now()->getTimestamp(),
            'CSRF' => ['TOKEN' => bin2hex(random_bytes(32))],
        ]);

        $id = $this->store->run(function (Redis $redis) use ($payload, $lifetime): string {
            do {
                $id = bin2hex(random_bytes(16));
            } while ($redis->set(self::LEGACY_PREFIX.$id, $payload, ['nx', 'ex' => $lifetime]) !== true);

            return $id;
        });

        DB::transaction(function () use ($creator, $mailbox, $request, $auditEvent, $auditTarget): void {
            $this->recentLogins->record($mailbox, (string) $request->ip(), 'SSO');
            $this->audit->record(
                $auditEvent,
                true,
                $creator,
                $auditTarget ?? ['type' => 'webmailHandOff', 'id' => $mailbox, 'name' => null],
                $creator->authMethod->value,
                null,
                [],
            );
        });

        $connection = $this->redis->connection('restv2');
        $connection->command('hmset', [self::RECORD.$id, [
            'handle' => $handle,
            'owner' => $creator->accountKind->value.':'.$creator->username,
            'mailbox' => $mailbox,
            'createdAt' => (string) CarbonImmutable::now()->getTimestamp(),
        ]]);
        $connection->command('sadd', [self::SESSION_SET.$handle, $id]);
        $connection->command('expire', [self::SESSION_SET.$handle, $lifetime]);

        return new WebmailHandOff($mailbox, self::URL, $this->cookie($id));
    }

    /**
     * Whether the session with this handle has a live hand-off.
     */
    public function hasHandOff(string $handle): bool
    {
        return (int) $this->redis->connection('restv2')->command('scard', [self::SESSION_SET.$handle]) > 0;
    }

    /**
     * The cookie that removes legacy's session cookie from the browser (sign-out).
     */
    public function expiredCookie(): Cookie
    {
        return Cookie::create($this->config->sessionName())
            ->withValue('deleted')
            ->withExpires(1)
            ->withPath('/')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withRaw(true)
            ->withSameSite(strtolower($this->config->sessionSameSitePolicy()));
    }

    /**
     * Ends the hand-offs of the session with this handle (SessionEndListener).
     */
    public function sessionEnded(string $handle): void
    {
        $connection = $this->redis->connection('restv2');
        $ids = $connection->command('smembers', [self::SESSION_SET.$handle]);

        foreach (is_array($ids) ? $ids : [] as $id) {
            $this->end((string) $id);
        }

        $connection->command('del', [self::SESSION_SET.$handle]);
    }

    public function sessionTouched(string $handle, int $lifetimeSeconds): void
    {
        $this->redis->connection('restv2')->command('expire', [self::SESSION_SET.$handle, $lifetimeSeconds]);
    }

    /**
     * Ends the hand-offs whose session of this API no longer exists (idle expiry raises no event),
     * and forgets those whose legacy session already expired. Returns the counts.
     *
     * @return array{ended: int, forgotten: int, kept: int}
     */
    public function sweep(): array
    {
        $counts = ['ended' => 0, 'forgotten' => 0, 'kept' => 0];
        $connection = $this->redis->connection('restv2');
        $client = $connection->client();
        $prefix = $client instanceof Redis ? (string) $client->getOption(Redis::OPT_PREFIX) : '';
        $cursor = null;

        do {
            $keys = $client instanceof Redis ? $client->scan($cursor, $prefix.self::RECORD.'*', 100) : false;

            foreach (is_array($keys) ? $keys : [] as $key) {
                $id = substr((string) $key, strlen($prefix.self::RECORD));
                $record = $connection->command('hgetall', [self::RECORD.$id]);
                $record = is_array($record) ? $record : [];
                [$kind, $username] = array_pad(explode(':', (string) ($record['owner'] ?? ''), 2), 2, '');
                $accountKind = AccountKind::tryFrom($kind);
                $handle = (string) ($record['handle'] ?? '');

                $legacyAlive = $this->store->run(fn (Redis $redis): bool => (bool) $redis->exists(self::LEGACY_PREFIX.$id));
                $apiAlive = $accountKind !== null && $handle !== '' && $this->registry->isAlive($accountKind, $username, $handle);

                if (! $legacyAlive) {
                    $this->forget($id, $handle);
                    $counts['forgotten']++;
                } elseif (! $apiAlive) {
                    $this->end($id);
                    $counts['ended']++;
                } else {
                    $counts['kept']++;
                }
            }
        } while ($cursor !== 0 && $cursor !== null);

        return $counts;
    }

    /**
     * Deletes a hand-off's legacy session and its records.
     */
    private function end(string $id): void
    {
        $this->store->run(fn (Redis $redis): mixed => $redis->del(self::LEGACY_PREFIX.$id));
        $handle = $this->redis->connection('restv2')->command('hget', [self::RECORD.$id, 'handle']);
        $this->forget($id, is_string($handle) ? $handle : '');
    }

    private function forget(string $id, string $handle): void
    {
        $connection = $this->redis->connection('restv2');
        $connection->command('del', [self::RECORD.$id]);

        if ($handle !== '') {
            $connection->command('srem', [self::SESSION_SET.$handle, $id]);
        }
    }

    /**
     * Legacy's session cookie as PHP issues it: no Domain, no expiry, Secure, HttpOnly, the
     * operator's SameSite policy.
     */
    private function cookie(string $id): Cookie
    {
        return Cookie::create($this->config->sessionName())
            ->withValue($id)
            ->withPath('/')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withRaw(true)
            ->withSameSite(strtolower($this->config->sessionSameSitePolicy()));
    }
}
