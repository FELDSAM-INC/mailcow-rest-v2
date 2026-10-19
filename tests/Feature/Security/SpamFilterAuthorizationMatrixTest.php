<?php

namespace Tests\Feature\Security;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FeatureRegistry;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T080 (constitution VI, quality gate 5, FR-001 to FR-006, SC-005): the 42 operations
 * of quarantine, spam policy and rate limits for 11 kinds of caller, derived from FR-006 and the
 * request pipeline (authentication, key scope, pending actions, then the role and the permission
 * flags, both before any lookup). Every refusal of a state-changing operation by an authenticated
 * caller writes exactly one failure audit record; anonymous requests and reads write none. The
 * object-level scope of each role answers "not found", identical to an unknown object.
 *
 * Mailbox users act on their own mailbox: `alice` holds every flag, `bob` none of the six.
 */
class SpamFilterAuthorizationMatrixTest extends TestCase
{
    use QuarantineTestHelpers;

    private const CALLERS = [
        'anonymous',
        'restrictedSession',
        'adminSession',
        'domainAdminSession',
        'domainAdminWithoutFlagsSession',
        'mailboxSession',
        'mailboxWithoutFlagsSession',
        'adminKey',
        'adminReadOnlyKey',
        'domainAdminKey',
        'domainAdminReadOnlyKey',
    ];

    /** Who may use an operation: `ok`, `forbidden`, `flag` (a missing flag) or `attribute`. */
    private const ADMIN_ONLY = ['admin' => 'ok', 'domainAdmin' => 'forbidden', 'mailbox' => 'forbidden'];

    private const EVERYONE = ['admin' => 'ok', 'domainAdmin' => 'ok', 'mailbox' => 'ok'];

    private const ITEM_ACTION = ['admin' => 'ok', 'domainAdmin' => 'flag', 'mailbox' => 'flag'];

    private const DOMAIN_READ = ['admin' => 'ok', 'domainAdmin' => 'ok', 'mailbox' => 'forbidden'];

    private const DOMAIN_CHANGE = ['admin' => 'ok', 'domainAdmin' => 'flag', 'mailbox' => 'forbidden'];

    /** @var array<string, string> */
    private array $keys = [];

    /** @var array<string, string> placeholder => value for administrators and domain administrators */
    private array $values = [];

    /** @var array<string, array<string, string>> mailbox => placeholder => value for mailbox users */
    private array $own = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('other.example');
        $f->aliasDomain('example.info', 'example.org');
        $f->aliasDomain('other.info', 'other.example');
        $this->quarantineMailbox('alice@example.org');
        $this->quarantineMailbox('bob@example.org', []);
        $this->quarantineMailbox('otto@other.example');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $f->domainAdmin('reseller', ['example.org'], ['quarantine' => 1, 'spam_policy' => 1, 'ratelimit' => 1], scheme: 'SSHA256');
        $f->domainAdmin('noflags', ['example.org'], ['quarantine' => 0, 'spam_policy' => 0, 'ratelimit' => 0], scheme: 'SSHA256');
        $this->keys = [
            'adminKey' => $this->apiKey('admin')['secret'],
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'domainAdminKey' => $this->apiKey('reseller')['secret'],
            'domainAdminReadOnlyKey' => $this->apiKey('reseller', 'readOnly')['secret'],
        ];

        foreach (['alice@example.org', 'bob@example.org'] as $mailbox) {
            $this->own[$mailbox] = [
                '{mailbox}' => $mailbox,
                '{item}' => (string) $this->item($mailbox, 'multipart-two-attachments'),
                '{mailboxEntry}' => (string) $f->filterconf($mailbox, 'blacklist_from', '*@'.strstr($mailbox, '@', true).'.example'),
            ];
        }

        $this->values = $this->own['alice@example.org'] + [
            '{domainEntry}' => (string) $f->filterconf('example.org', 'blacklist_from', '*@domain.example'),
            '{aliasEntry}' => (string) $f->filterconf('example.info', 'whitelist_from', 'partner@example.net'),
        ];
        $this->own['alice@example.org'] += $this->values;
        $this->own['bob@example.org'] += $this->values;
        $this->redis()->hSet('RL_VALUE', 'example.org', '10 / 1m');
        $this->redis()->hSet('RL_VALUE', 'example.info', '10 / 1m');
        $this->redis()->hSet('RL_VALUE', 'alice@example.org', '10 / 1m');
        $this->redis()->hSet('RL_VALUE', 'bob@example.org', '10 / 1m');
        $this->redis()->hSet('RLabc=', 'cnt', '1');
    }

    /**
     * operation => [method, path, body (null: none), success status, rule per role].
     *
     * @return array<string, array{string, string, array<string, mixed>|null, int, array<string, string>}>
     */
    private static function requests(): array
    {
        $mailboxRead = self::EVERYONE;
        $mailboxEntryChange = ['admin' => 'ok', 'domainAdmin' => 'flag', 'mailbox' => 'flag'];
        $scoreChange = ['admin' => 'ok', 'domainAdmin' => 'ok', 'mailbox' => 'flag'];

        return [
            'listQuarantineItems' => ['GET', '/api/v2/quarantine', null, 200, self::EVERYONE],
            'applyQuarantineBulkAction' => ['POST', '/api/v2/quarantine/bulk-actions', ['action' => 'delete', 'targets' => [['id' => '{item}']]], 200, self::ITEM_ACTION],
            'getQuarantineSettings' => ['GET', '/api/v2/quarantine/settings', null, 200, self::EVERYONE],
            'updateQuarantineSettings' => ['PATCH', '/api/v2/quarantine/settings', ['retentionPerMailbox' => 10], 200, self::ADMIN_ONLY],
            'previewQuarantineTemplate' => ['POST', '/api/v2/quarantine/settings/template-preview', ['template' => '<p>{{ username }}</p>'], 200, self::ADMIN_ONLY],
            'getQuarantineItem' => ['GET', '/api/v2/quarantine/{item}', null, 200, self::EVERYONE],
            'deleteQuarantineItem' => ['DELETE', '/api/v2/quarantine/{item}', null, 204, self::ITEM_ACTION],
            'releaseQuarantineItem' => ['POST', '/api/v2/quarantine/{item}/release', null, 200, self::ITEM_ACTION],
            'learnQuarantineItemAsSpam' => ['POST', '/api/v2/quarantine/{item}/spam-learning', null, 200, self::ITEM_ACTION],
            'downloadQuarantineMessage' => ['GET', '/api/v2/quarantine/{item}/message', null, 200, self::ITEM_ACTION],
            'downloadQuarantineAttachment' => ['GET', '/api/v2/quarantine/{item}/attachments/0', null, 200, ['admin' => 'ok', 'domainAdmin' => 'ok', 'mailbox' => 'flag']],
            'getQuarantineCapabilities' => ['GET', '/api/v2/capabilities/quarantine', null, 200, self::EVERYONE],
            'applyQuarantineNotificationBulkAction' => ['POST', '/api/v2/quarantine-notification-settings/bulk-actions', ['frequency' => 'weekly', 'targets' => [['id' => 'alice@example.org']]], 200, self::DOMAIN_READ],
            'getMailboxQuarantineNotificationSettings' => ['GET', '/api/v2/mailboxes/{mailbox}/quarantine-notification-settings', null, 200, $mailboxRead],
            'updateMailboxQuarantineNotificationSettings' => ['PATCH', '/api/v2/mailboxes/{mailbox}/quarantine-notification-settings', ['frequency' => 'daily'], 200, ['admin' => 'ok', 'domainAdmin' => 'ok', 'mailbox' => 'attribute']],
            'listDomainSpamPolicyEntries' => ['GET', '/api/v2/domains/example.org/spam-policy-entries', null, 200, self::DOMAIN_READ],
            'createDomainSpamPolicyEntry' => ['POST', '/api/v2/domains/example.org/spam-policy-entries', ['list' => 'block', 'pattern' => '*@new.example'], 201, self::DOMAIN_CHANGE],
            'getDomainSpamPolicyEntry' => ['GET', '/api/v2/domains/example.org/spam-policy-entries/{domainEntry}', null, 200, self::DOMAIN_READ],
            'deleteDomainSpamPolicyEntry' => ['DELETE', '/api/v2/domains/example.org/spam-policy-entries/{domainEntry}', null, 204, self::DOMAIN_CHANGE],
            'listAliasDomainSpamPolicyEntries' => ['GET', '/api/v2/alias-domains/example.info/spam-policy-entries', null, 200, self::DOMAIN_READ],
            'createAliasDomainSpamPolicyEntry' => ['POST', '/api/v2/alias-domains/example.info/spam-policy-entries', ['list' => 'allow', 'pattern' => 'new@example.net'], 201, self::DOMAIN_CHANGE],
            'getAliasDomainSpamPolicyEntry' => ['GET', '/api/v2/alias-domains/example.info/spam-policy-entries/{aliasEntry}', null, 200, self::DOMAIN_READ],
            'deleteAliasDomainSpamPolicyEntry' => ['DELETE', '/api/v2/alias-domains/example.info/spam-policy-entries/{aliasEntry}', null, 204, self::DOMAIN_CHANGE],
            'listMailboxSpamPolicyEntries' => ['GET', '/api/v2/mailboxes/{mailbox}/spam-policy-entries', null, 200, $mailboxRead],
            'createMailboxSpamPolicyEntry' => ['POST', '/api/v2/mailboxes/{mailbox}/spam-policy-entries', ['list' => 'block', 'pattern' => '*@new.example'], 201, $mailboxEntryChange],
            'getMailboxSpamPolicyEntry' => ['GET', '/api/v2/mailboxes/{mailbox}/spam-policy-entries/{mailboxEntry}', null, 200, $mailboxRead],
            'deleteMailboxSpamPolicyEntry' => ['DELETE', '/api/v2/mailboxes/{mailbox}/spam-policy-entries/{mailboxEntry}', null, 204, $mailboxEntryChange],
            'getMailboxSpamScore' => ['GET', '/api/v2/mailboxes/{mailbox}/spam-score', null, 200, $mailboxRead],
            'setMailboxSpamScore' => ['PUT', '/api/v2/mailboxes/{mailbox}/spam-score', ['junkScore' => 6, 'rejectScore' => 12], 200, $scoreChange],
            'resetMailboxSpamScore' => ['DELETE', '/api/v2/mailboxes/{mailbox}/spam-score', null, 204, $scoreChange],
            'getSpamFilterActionStatistics' => ['GET', '/api/v2/spam-filter-action-statistics', null, 200, self::ADMIN_ONLY],
            'listRateLimits' => ['GET', '/api/v2/rate-limits', null, 200, self::DOMAIN_READ],
            'getDomainRateLimit' => ['GET', '/api/v2/domains/example.org/rate-limit', null, 200, self::DOMAIN_READ],
            'setDomainRateLimit' => ['PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 5, 'frame' => 'hour'], 200, self::DOMAIN_CHANGE],
            'removeDomainRateLimit' => ['DELETE', '/api/v2/domains/example.org/rate-limit', null, 204, self::DOMAIN_CHANGE],
            'getAliasDomainRateLimit' => ['GET', '/api/v2/alias-domains/example.info/rate-limit', null, 200, self::DOMAIN_READ],
            'setAliasDomainRateLimit' => ['PUT', '/api/v2/alias-domains/example.info/rate-limit', ['value' => 5, 'frame' => 'hour'], 200, self::DOMAIN_CHANGE],
            'removeAliasDomainRateLimit' => ['DELETE', '/api/v2/alias-domains/example.info/rate-limit', null, 204, self::DOMAIN_CHANGE],
            'getMailboxRateLimit' => ['GET', '/api/v2/mailboxes/{mailbox}/rate-limit', null, 200, self::DOMAIN_READ],
            'setMailboxRateLimit' => ['PUT', '/api/v2/mailboxes/{mailbox}/rate-limit', ['value' => 5, 'frame' => 'hour'], 200, self::DOMAIN_CHANGE],
            'removeMailboxRateLimit' => ['DELETE', '/api/v2/mailboxes/{mailbox}/rate-limit', null, 204, self::DOMAIN_CHANGE],
            'resetRateLimitBucket' => ['DELETE', '/api/v2/rate-limit-buckets/RLabc=', null, 204, self::ADMIN_ONLY],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $cases = [];

        foreach (array_keys(self::requests()) as $operation) {
            foreach (self::CALLERS as $caller) {
                $cases["{$operation} as {$caller}"] = [$operation, $caller];
            }
        }

        return $cases;
    }

    private static function role(string $caller): string
    {
        return match (true) {
            str_starts_with($caller, 'domainAdmin') => 'domainAdmin',
            str_starts_with($caller, 'mailbox') => 'mailbox',
            default => 'admin',
        };
    }

    /**
     * @return array{int, string|null} status and problem type name (null: none)
     */
    private static function expected(string $operation, string $caller): array
    {
        [$method, , , $success, $rules] = self::requests()[$operation];
        $rule = $rules[self::role($caller)];

        return match (true) {
            $caller === 'anonymous' => [401, 'unauthenticated'],
            $method !== 'GET' && str_ends_with($caller, 'ReadOnlyKey') => [403, 'insufficient-key-scope'],
            $caller === 'restrictedSession' => [403, 'pending-action-required'],
            $rule === 'forbidden' => [403, 'forbidden'],
            $rule === 'flag' && str_contains($caller, 'WithoutFlags') => [403, 'permission-flag-required'],
            $rule === 'attribute' && str_contains($caller, 'WithoutFlags') => [403, 'attribute-not-permitted'],
            default => [$success, null],
        };
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return TestResponse<Response>
     */
    private function request(string $caller, string $method, string $path, ?array $body): TestResponse
    {
        $headers = [];

        match ($caller) {
            'anonymous' => null,
            'restrictedSession' => $this->signInAs('restricted', Role::Admin),
            'adminSession' => $this->signInAs('admin', Role::Admin),
            'domainAdminSession' => $this->signInAs('reseller', Role::DomainAdmin),
            'domainAdminWithoutFlagsSession' => $this->signInAs('noflags', Role::DomainAdmin),
            'mailboxSession' => $this->signInAs('alice@example.org', Role::MailboxUser),
            'mailboxWithoutFlagsSession' => $this->signInAs('bob@example.org', Role::MailboxUser),
            default => $headers['X-API-Key'] = $this->keys[$caller],
        };

        if (! isset($headers['X-API-Key']) && $method !== 'GET' && $caller !== 'anonymous') {
            $headers['X-CSRF-Token'] = $this->csrfToken();
        }

        return match (true) {
            $method === 'GET' && preg_match('#/(message|attachments/\d+)$#', $path) === 1 => $this->get($path, $headers),
            $body === null && $method === 'POST' => $this->postEmpty($path, $headers),
            $body === null => $this->json($method, $path, [], $headers),
            $method === 'PATCH' => $this->patchMerge($path, $body, $headers),
            default => $this->json($method, $path, $body, $headers),
        };
    }

    #[DataProvider('cases')]
    public function test_every_operation_for_every_caller(string $operation, string $caller): void
    {
        [$method, $path, $body] = self::requests()[$operation];
        [$status, $type] = self::expected($operation, $caller);
        $values = match ($caller) {
            'mailboxSession' => $this->own['alice@example.org'],
            'mailboxWithoutFlagsSession' => $this->own['bob@example.org'],
            default => $this->values,
        };
        $resolve = fn (string $text): string => strtr($text, $values);

        $before = DB::table('restv2_audit_events')->where('outcome', 'failure')->count();
        $response = $this->request($caller, $method, $resolve($path), $body === null ? null : json_decode($resolve((string) json_encode($body)), true));

        $response->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
        }

        $audited = $method !== 'GET' && $operation !== 'previewQuarantineTemplate' && $status >= 400 && $caller !== 'anonymous';
        $this->assertSame($before + ($audited ? 1 : 0), DB::table('restv2_audit_events')->where('outcome', 'failure')->count(), 'failure audit records');
    }

    public function test_objects_outside_the_scope_answer_like_unknown_objects(): void
    {
        $f = $this->mailcow();
        $foreignItem = $this->item('otto@other.example');
        $foreignEntry = $f->filterconf('otto@other.example', 'blacklist_from', '*@otto.example');
        $foreignDomainEntry = $f->filterconf('other.example', 'blacklist_from', '*@other-domain.example');
        $aliceEntry = $this->own['alice@example.org']['{mailboxEntry}'];
        $pairs = [
            ['GET', "/api/v2/quarantine/{$foreignItem}", '/api/v2/quarantine/999999'],
            ['DELETE', "/api/v2/quarantine/{$foreignItem}", '/api/v2/quarantine/999999'],
            ['GET', '/api/v2/mailboxes/otto@other.example/spam-score', '/api/v2/mailboxes/nobody@example.org/spam-score'],
            ['PUT', '/api/v2/mailboxes/otto@other.example/rate-limit', '/api/v2/mailboxes/nobody@example.org/rate-limit'],
            ['GET', '/api/v2/domains/other.example/spam-policy-entries', '/api/v2/domains/unknown.example/spam-policy-entries'],
            ['GET', '/api/v2/alias-domains/other.info/rate-limit', '/api/v2/alias-domains/unknown.info/rate-limit'],
            ['DELETE', "/api/v2/domains/example.org/spam-policy-entries/{$foreignDomainEntry}", '/api/v2/domains/example.org/spam-policy-entries/999999'],
            ['DELETE', "/api/v2/mailboxes/alice@example.org/spam-policy-entries/{$foreignEntry}", '/api/v2/mailboxes/alice@example.org/spam-policy-entries/999999'],
        ];
        $this->signInAs('reseller', Role::DomainAdmin);

        foreach ($pairs as [$method, $foreign, $unknown]) {
            $body = $method === 'PUT' ? ['value' => 1, 'frame' => 'hour'] : null;
            $a = $this->send($method, $foreign, $body)->assertNotFound();
            $b = $this->send($method, $unknown, $body)->assertNotFound();
            $this->assertSame([$a->json('type'), $a->json('title'), $a->json('detail')], [$b->json('type'), $b->json('title'), $b->json('detail')], "{$method} {$foreign}");
        }

        $this->forgetCookies();
        $this->signInAs('alice@example.org', Role::MailboxUser);
        $bobItem = $this->own['bob@example.org']['{item}'];
        $bobEntry = $this->own['bob@example.org']['{mailboxEntry}'];
        $this->getJson("/api/v2/quarantine/{$bobItem}")->assertNotFound();
        $this->send('POST', "/api/v2/quarantine/{$bobItem}/release")->assertNotFound();
        $this->getJson('/api/v2/mailboxes/bob@example.org/spam-score')->assertNotFound();
        $this->getJson("/api/v2/mailboxes/bob@example.org/spam-policy-entries/{$bobEntry}")->assertNotFound();
        $this->send('DELETE', "/api/v2/mailboxes/alice@example.org/spam-policy-entries/{$bobEntry}")->assertNotFound();
        $this->getJson("/api/v2/mailboxes/alice@example.org/spam-policy-entries/{$aliceEntry}")->assertOk();
        $this->mailer->assertNothingDelivered();
    }

    public function test_the_matrix_covers_every_operation_of_the_feature(): void
    {
        $operations = FeatureRegistry::load()->features['006-quarantine-spam-policy']['operations'];

        $this->assertCount(42, $operations);
        $this->assertEqualsCanonicalizing($operations, array_keys(self::requests()));
    }
}
