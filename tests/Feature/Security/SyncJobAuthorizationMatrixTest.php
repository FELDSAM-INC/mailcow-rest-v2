<?php

namespace Tests\Feature\Security;

use App\Auth\Role;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\FeatureRegistry;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T063 (constitution VI, quality gate 5, FR-001–FR-005, SC-003): the 9 sync-job
 * operations for 13 kinds of caller, derived from FR-005 and the request pipeline
 * (authentication, key scope, pending actions, then the `syncjobs` flag), and the object-level
 * scope of each role: a job or target mailbox outside it is "not found".
 */
class SyncJobAuthorizationMatrixTest extends TestCase
{
    use ApiKeys;
    use SyncJobTestHelpers;

    private const CALLERS = [
        'anonymous',
        'restrictedSession',
        'adminSession',
        'domainAdminSession',
        'domainAdminWithoutFlagSession',
        'mailboxSession',
        'mailboxWithoutFlagSession',
        'adminReadOnlyKey',
        'adminKey',
        'domainAdminReadOnlyKey',
        'domainAdminKey',
        'domainAdminWithoutFlagReadOnlyKey',
        'domainAdminWithoutFlagKey',
    ];

    private int $job = 0;

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $this->syncMailbox('anna@example.org');
        $this->syncMailbox('bert@example.org', syncjobs: false);
        $this->syncMailbox('carol@example.net');
        $this->job = $f->syncJob('anna@example.org', ['returned_text' => 'log']);
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $f->domainAdmin('reseller', ['example.org'], ['syncjobs' => 1], scheme: 'SSHA256');
        $f->domainAdmin('noflag', ['example.org'], ['syncjobs' => 0], scheme: 'SSHA256');
        $this->keys = [
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'adminKey' => $this->apiKey('admin')['secret'],
            'domainAdminReadOnlyKey' => $this->apiKey('reseller', 'readOnly')['secret'],
            'domainAdminKey' => $this->apiKey('reseller')['secret'],
            'domainAdminWithoutFlagReadOnlyKey' => $this->apiKey('noflag', 'readOnly')['secret'],
            'domainAdminWithoutFlagKey' => $this->apiKey('noflag')['secret'],
        ];
    }

    /**
     * operation => [method, path, body (null: none), success status]. `{job}` is the job's ID.
     *
     * @return array<string, array{string, string, array<string, mixed>|null, int}>
     */
    private static function requests(): array
    {
        return [
            'listSyncJobs' => ['GET', '/api/v2/sync-jobs', null, 200],
            'createSyncJob' => ['POST', '/api/v2/sync-jobs', ['mailbox' => 'anna@example.org', 'remoteHost' => 'imap.new.example', 'remotePort' => 993, 'remoteUser' => 'anna', 'remotePassword' => 'x'], 201],
            'getSyncJobsCapabilities' => ['GET', '/api/v2/capabilities/sync-jobs', null, 200],
            'applySyncJobBulkAction' => ['POST', '/api/v2/sync-jobs/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => '{job}']]], 200],
            'getSyncJob' => ['GET', '/api/v2/sync-jobs/{job}', null, 200],
            'updateSyncJob' => ['PATCH', '/api/v2/sync-jobs/{job}', ['active' => false], 200],
            'deleteSyncJob' => ['DELETE', '/api/v2/sync-jobs/{job}', null, 204],
            'getSyncJobLog' => ['GET', '/api/v2/sync-jobs/{job}/log', null, 200],
            'rescheduleSyncJob' => ['POST', '/api/v2/sync-jobs/{job}/schedule-reset', null, 200],
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

    /**
     * @return array{int, string|null} status and problem type name (null: none)
     */
    private static function expected(string $operation, string $caller): array
    {
        [$method, , , $success] = self::requests()[$operation];

        return match (true) {
            $caller === 'anonymous' => [401, 'unauthenticated'],
            $method !== 'GET' && str_ends_with($caller, 'ReadOnlyKey') => [403, 'insufficient-key-scope'],
            $caller === 'restrictedSession' => [403, 'pending-action-required'],
            str_contains($caller, 'WithoutFlag') => [403, 'permission-flag-required'],
            default => [$success, null],
        };
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return TestResponse<Response>
     */
    private function send(string $caller, string $method, string $path, ?array $body): TestResponse
    {
        $headers = [];

        match ($caller) {
            'anonymous' => null,
            'restrictedSession' => $this->signInAs('restricted', Role::Admin),
            'adminSession' => $this->signInAs('admin', Role::Admin),
            'domainAdminSession' => $this->signInAs('reseller', Role::DomainAdmin),
            'domainAdminWithoutFlagSession' => $this->signInAs('noflag', Role::DomainAdmin),
            'mailboxSession' => $this->signInAs('anna@example.org', Role::MailboxUser),
            'mailboxWithoutFlagSession' => $this->signInAs('bert@example.org', Role::MailboxUser),
            default => $headers['X-API-Key'] = $this->keys[$caller],
        };

        if (! isset($headers['X-API-Key']) && $method !== 'GET' && $caller !== 'anonymous') {
            $headers['X-CSRF-Token'] = $this->csrfToken();
        }

        return match (true) {
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
        $id = (string) $this->job;

        if ($body !== null) {
            $body = json_decode(str_replace('{job}', $id, (string) json_encode($body)), true);
        }

        $response = $this->send($caller, $method, str_replace('{job}', $id, $path), $body);

        $response->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
        } elseif ($status >= 400) {
            $this->assertNotSame('/api/v2/problems/forbidden', $response->json('type'));
        }
    }

    public function test_objects_outside_the_caller_s_scope_are_not_found(): void
    {
        $foreign = $this->mailcow()->syncJob('carol@example.net');
        $other = $this->mailcow()->syncJob('bert@example.org');

        $this->signInAs('reseller', Role::DomainAdmin);
        foreach (['/api/v2/sync-jobs/'.$foreign, '/api/v2/sync-jobs/'.$foreign.'/log'] as $uri) {
            $this->getJson($uri)->assertNotFound();
        }
        $this->patchSyncJob($foreign, ['active' => false])->assertNotFound();
        $this->deleteSyncJob($foreign)->assertNotFound();
        $this->rescheduleSyncJob($foreign)->assertNotFound();
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'carol@example.net']))->assertNotFound();
        $this->syncJobBulk('delete', [$foreign])->assertOk()->assertJsonPath('data.results.0.outcome', 'notFound');

        $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->getJson('/api/v2/sync-jobs/'.$other)->assertNotFound();
        $this->patchSyncJob($other, ['active' => false])->assertNotFound();
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'bert@example.org']))->assertNotFound();

        $this->assertNotNull($this->syncJobRow($foreign));
        $this->assertSame(1, (int) $this->syncJobRow($other)?->active);
    }

    public function test_the_matrix_covers_every_operation_of_the_feature(): void
    {
        $this->assertEqualsCanonicalizing(FeatureRegistry::load()->features['008-sync-jobs']['operations'], array_keys(self::requests()));
    }
}
