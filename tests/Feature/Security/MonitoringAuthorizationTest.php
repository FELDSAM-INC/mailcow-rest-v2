<?php

namespace Tests\Feature\Security;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\Support\Redis\MailcowLogs;
use Tests\TestCase;

/**
 * Feature 011 T015 (FR-001, FR-002, FR-004, SC-005, research R24, R26): every operation of the
 * monitoring feature for every kind of caller. Administrators (sessions, read-write keys) may use
 * everything, administrator read-only keys every read; everyone else is refused. Refusals of
 * authenticated callers on state-changing operations, and on the message content view, write
 * exactly one failure record; anti-forgery failures, `401`s and other refused reads write none.
 * Each story phase adds its operations to operations().
 */
class MonitoringAuthorizationTest extends TestCase
{
    use ApiKeys;

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->admin('admin', MailcowFactory::PASSWORD, 'SSHA256');
        $f->admin('restricted', MailcowFactory::PASSWORD, 'SSHA256', attributes: ['force_pw_update' => '1']);
        $f->domain('example.org');
        $f->domainAdmin('reseller', ['example.org'], scheme: 'SSHA256');
        $f->mailbox('user@example.org', MailcowFactory::PASSWORD, 'SSHA256');
        $this->dockerApi()->withQueue(MonitoringFixtures::text('postqueue-j.txt'));
        $this->dockerApi()->onRestart('netfilter-mailcow', fn () => MailcowLogs::f2bRegex(MailcowLogs::defaultRules()));
        MailcowLogs::activeBan('203.0.113.0/24', time() + 3600);
        $this->sleeper()->advancing($this->clock());
        $this->keys = [
            'adminKey' => $this->apiKey('admin', 'readWrite')['secret'],
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'domainAdminKey' => $this->apiKey('reseller', 'readWrite')['secret'],
            'domainAdminReadOnlyKey' => $this->apiKey('reseller', 'readOnly')['secret'],
        ];
    }

    /**
     * operation => [method, path, body or null, audit event or null, audit target type or null].
     * The event and target are those of the route's `restv2.audit` default (state-changing
     * operations and the content view).
     *
     * @return array<string, array{string, string, array<string, mixed>|null, string|null, string|null}>
     */
    public static function operations(): array
    {
        return [
            // US1 (T030).
            'getHostOverview' => ['GET', '/api/v2/host', null, null, null],
            'getMailStorageUsage' => ['GET', '/api/v2/host/mail-storage', null, null, null],
            'getMonitoringCapabilities' => ['GET', '/api/v2/capabilities/monitoring', null, null, null],
            'listContainers' => ['GET', '/api/v2/containers', null, null, null],
            'getContainer' => ['GET', '/api/v2/containers/postfix-mailcow', null, null, null],
            'getContainerResourceUsage' => ['GET', '/api/v2/containers/postfix-mailcow/resource-usage', null, null, null],
            // US3 (T052).
            'listPostfixLog' => ['GET', '/api/v2/logs/postfix', null, null, null],
            'listDovecotLog' => ['GET', '/api/v2/logs/dovecot', null, null, null],
            'listSogoLog' => ['GET', '/api/v2/logs/sogo', null, null, null],
            'listNetfilterLog' => ['GET', '/api/v2/logs/netfilter', null, null, null],
            'listAutodiscoverLog' => ['GET', '/api/v2/logs/autodiscover', null, null, null],
            'listWatchdogLog' => ['GET', '/api/v2/logs/watchdog', null, null, null],
            'listAcmeLog' => ['GET', '/api/v2/logs/acme', null, null, null],
            'listCronLog' => ['GET', '/api/v2/logs/cron', null, null, null],
            'listRateLimitLog' => ['GET', '/api/v2/logs/rate-limits', null, null, null],
            'listLegacyApiRequestLog' => ['GET', '/api/v2/logs/legacy-api-requests', null, null, null],
            'listLegacyUiActivityLog' => ['GET', '/api/v2/logs/legacy-ui-activity', null, null, null],
            'listLoginLog' => ['GET', '/api/v2/logs/logins', null, null, null],
            // US4 (T066); the content view is the one audited read.
            'listMailQueueMessages' => ['GET', '/api/v2/mail-queue/messages', null, null, null],
            'getMailQueueMessageContent' => ['GET', '/api/v2/mail-queue/messages/4F0A1C2B3D/content', null, 'mailQueueMessageView', 'mailQueueMessage'],
            'bulkMailQueueMessagesDeliver' => ['POST', '/api/v2/mail-queue/messages/bulk-actions', ['action' => 'deliver', 'targets' => [['id' => '4F0A1C2B3D']]], 'mailQueueDelivery', 'mailQueue'],
            'bulkMailQueueMessagesHold' => ['POST', '/api/v2/mail-queue/messages/bulk-actions', ['action' => 'hold', 'targets' => [['id' => '4F0A1C2B3D']]], 'mailQueueHold', 'mailQueue'],
            'bulkMailQueueMessagesRelease' => ['POST', '/api/v2/mail-queue/messages/bulk-actions', ['action' => 'release', 'targets' => [['id' => '9E5F607182']]], 'mailQueueRelease', 'mailQueue'],
            'bulkMailQueueMessagesDelete' => ['POST', '/api/v2/mail-queue/messages/bulk-actions', ['action' => 'delete', 'targets' => [['id' => '9E5F607182']]], 'mailQueueDeletion', 'mailQueue'],
            'flushMailQueue' => ['POST', '/api/v2/mail-queue/flush', null, 'mailQueueFlush', 'mailQueue'],
            'purgeMailQueue' => ['POST', '/api/v2/mail-queue/purge', null, 'mailQueuePurge', 'mailQueue'],
            // US5 (T083).
            'getFail2BanSettings' => ['GET', '/api/v2/fail2ban/settings', null, null, null],
            'updateFail2BanSettings' => ['PATCH', '/api/v2/fail2ban/settings', ['banTime' => 3600], 'fail2banSettingsUpdate', 'fail2banSettings'],
            'rotateFail2BanBanListId' => ['POST', '/api/v2/fail2ban/ban-list-rotation', null, 'fail2banBanListIdRotation', 'fail2banBanListId'],
            'getFail2BanRules' => ['GET', '/api/v2/fail2ban/rules', null, null, null],
            'replaceFail2BanRules' => ['PUT', '/api/v2/fail2ban/rules', ['rules' => ['new (\\d+)']], 'fail2banRulesReplacement', 'fail2banRules'],
            'resetFail2BanRules' => ['POST', '/api/v2/fail2ban/rules/reset', null, 'fail2banRulesReset', 'fail2banRules'],
            'listFail2BanActiveBans' => ['GET', '/api/v2/fail2ban/active-bans', null, null, null],
            'liftFail2BanBan' => ['POST', '/api/v2/fail2ban/active-bans/203.0.113.0%2F24/lift', null, 'fail2banBanLift', 'fail2banActiveBan'],
            'allowlistFail2BanBan' => ['POST', '/api/v2/fail2ban/active-bans/203.0.113.0%2F24/allowlist', null, 'fail2banBanAllowlisting', 'fail2banActiveBan'],
            'denylistFail2BanBan' => ['POST', '/api/v2/fail2ban/active-bans/203.0.113.0%2F24/denylist', null, 'fail2banBanDenylisting', 'fail2banActiveBan'],
            'listFail2BanPermanentBans' => ['GET', '/api/v2/fail2ban/permanent-bans', null, null, null],
            // US2 (T041).
            'startContainer' => ['POST', '/api/v2/containers/sogo-mailcow/start', null, 'containerStart', 'container'],
            'stopContainer' => ['POST', '/api/v2/containers/clamd-mailcow/stop', null, 'containerStop', 'container'],
            'restartContainer' => ['POST', '/api/v2/containers/sogo-mailcow/restart', null, 'containerRestart', 'container'],
        ];
    }

    private const CALLERS = [
        'anonymous',
        'restricted',
        'adminSession',
        'adminKey',
        'adminReadOnlyKey',
        'domainAdminSession',
        'mailboxSession',
        'domainAdminKey',
        'domainAdminReadOnlyKey',
    ];

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $cases = [];

        foreach (array_keys(self::operations()) as $operation) {
            foreach (self::CALLERS as $caller) {
                $cases["{$operation} as {$caller}"] = [$operation, $caller];
            }
        }

        return $cases === [] ? ['no operation yet' => ['', '']] : $cases;
    }

    /**
     * @return array{int|null, string|null} expected status (null: allowed, neither 401 nor 403) and problem type name
     */
    private static function expected(string $method, string $caller): array
    {
        $unsafe = $method !== 'GET';

        if (in_array($caller, ['adminReadOnlyKey', 'domainAdminReadOnlyKey'], true) && $unsafe) {
            return [403, 'insufficient-key-scope'];
        }

        return match ($caller) {
            'anonymous' => [401, 'unauthenticated'],
            'restricted' => [403, 'pending-action-required'],
            'adminSession', 'adminKey', 'adminReadOnlyKey' => [null, null],
            default => [403, 'forbidden'],
        };
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return TestResponse<Response>
     */
    private function send(string $caller, string $method, string $path, ?array $body, bool $withCsrf = true): TestResponse
    {
        $headers = [];

        match ($caller) {
            'anonymous' => null,
            'restricted' => $this->signInAs('restricted', Role::Admin),
            'adminSession' => $this->signInAs('admin', Role::Admin),
            'domainAdminSession' => $this->signInAs('reseller', Role::DomainAdmin),
            'mailboxSession' => $this->signInAs('user@example.org', Role::MailboxUser),
            default => $headers['X-API-Key'] = $this->keys[$caller],
        };

        if ($withCsrf && ! isset($headers['X-API-Key']) && $method !== 'GET') {
            $headers['X-CSRF-Token'] = $this->csrfToken();
        }

        if ($body === null) {
            return $method === 'GET' ? $this->json($method, $path, [], $headers) : $this->postEmptyAs($method, $path, $headers);
        }

        return $method === 'PATCH'
            ? $this->patchMerge($path, $body, $headers)
            : $this->json($method, $path, $body, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function postEmptyAs(string $method, string $path, array $headers): TestResponse
    {
        return $this->call($method, $path, [], [], [], $this->transformHeadersToServerVars(array_merge(['Accept' => 'application/json'], $headers)));
    }

    private function auditRows(): int
    {
        return DB::table('restv2_audit_events')->count();
    }

    #[DataProvider('cases')]
    public function test_every_operation_for_every_caller(string $operation, string $caller): void
    {
        if ($operation === '') {
            $this->markTestSkipped('No monitoring operation is implemented yet.');
        }

        [$method, $path, $body, $event, $target] = self::operations()[$operation];
        [$status, $type] = self::expected($method, $caller);
        $before = $this->auditRows();

        $response = $this->send($caller, $method, $path, $body);

        if ($status === null) {
            $this->assertNotContains($response->getStatusCode(), [401, 403], "{$operation} refused {$caller}: ".$response->getContent());

            return;
        }

        $response->assertStatus($status)->assertJsonPath('type', '/api/v2/problems/'.$type);

        if ($status === 401 || $event === null) {
            $this->assertSame($before, $this->auditRows(), 'a refused read or a 401 was audited');

            return;
        }

        $rows = DB::table('restv2_audit_events')->where('outcome', 'failure')->get();
        $this->assertCount($before + 1, DB::table('restv2_audit_events')->get(), 'not exactly one record for the refusal');
        $row = $rows->last();
        $this->assertSame($event, $row->event_type);
        $this->assertSame($target, $row->target_type);
        $this->assertSame('permissionDenied', $row->failure_reason);
        $this->assertSame('/api/v2/problems/'.$type, json_decode((string) $row->details, true)['problemType'] ?? null);
    }

    #[DataProvider('cases')]
    public function test_a_session_without_the_anti_forgery_token_is_refused_and_not_audited(string $operation, string $caller): void
    {
        if ($operation === '') {
            $this->markTestSkipped('No monitoring operation is implemented yet.');
        }

        [$method, $path, $body] = self::operations()[$operation];

        if ($method === 'GET' || $caller !== 'adminSession') {
            $this->addToAssertionCount(1);

            return;
        }

        $this->send('adminSession', $method, $path, $body, withCsrf: false)
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        $this->assertSame(0, $this->auditRows());
    }
}
