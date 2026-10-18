<?php

namespace Tests\Feature\Audit;

use App\Auth\Role;
use App\Exceptions\PermissionFlagRequiredException;
use App\Exceptions\ProblemException;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T021 (research R16, FR-025): the shared refusal auditing (002's RefusalAuditor) with
 * this feature's `syncJob` target resolver. Each refusal of an authenticated caller on a route that
 * declares `restv2.audit` with the target `syncJob` writes one failure record naming the job and its
 * mailbox, or the requested mailbox of a creation, or the requested IDs of a refused bulk request;
 * CSRF failures and `401`s write none, and no record holds request values.
 */
class SyncJobRefusalAuditTest extends TestCase
{
    use ApiKeys;
    use SyncJobTestHelpers;

    private int $job;

    protected function setUp(): void
    {
        parent::setUp();

        $refuse = function (Request $request): never {
            match ($request->input('problem')) {
                'flag' => throw new PermissionFlagRequiredException(['syncjobs']),
                'notFound' => throw new NotFoundHttpException('The sync job does not exist.'),
                'conflict' => throw new ProblemException(ProblemType::SyncJobExists, null, ['syncJobId' => 1]),
                'outdated' => throw new HttpException(412, 'The sync job was changed since it was read.'),
                'invalid' => (function (): never {
                    $violations = new FieldViolations;
                    $violations->add('customParameters', 'optionNotAllowed', ['option' => 'showpasswords']);
                    $violations->throwIfAny();

                    throw new \LogicException('unreachable');
                })(),
                default => throw new \RuntimeException('unexpected'),
            };
        };

        $middleware = ['api.v2', 'restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'];
        Route::middleware($middleware)->post('/api/v2/__testing/sync-jobs/bulk-actions', $refuse)
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'syncJob']);
        Route::middleware($middleware)->post('/api/v2/__testing/sync-jobs/{syncJobId}', $refuse)
            ->defaults('restv2.audit', ['event' => 'syncJobUpdate', 'target' => 'syncJob']);
        Route::middleware($middleware)->post('/api/v2/__testing/sync-jobs', $refuse)
            ->defaults('restv2.audit', ['event' => 'syncJobCreation', 'target' => 'syncJob']);

        $this->syncMailbox('alice@example.org');
        $this->job = $this->mailcow()->syncJob('alice@example.org');
    }

    /**
     * @return list<stdClass>
     */
    private function records(): array
    {
        return DB::table('restv2_audit_events')->orderBy('occurred_at')->orderBy('id')->get()
            ->map(function (stdClass $row): stdClass {
                $row->details = json_decode((string) $row->details, true);

                return $row;
            })->all();
    }

    /**
     * @return array<string, array{string, int, string, string}>
     */
    public static function problems(): array
    {
        return [
            'missing flag' => ['flag', 403, 'permissionDenied', '/api/v2/problems/permission-flag-required'],
            'not found' => ['notFound', 404, 'notFound', 'about:blank'],
            'duplicate' => ['conflict', 409, 'conflict', '/api/v2/problems/sync-job-exists'],
            'outdated' => ['outdated', 412, 'versionOutdated', 'about:blank'],
            'invalid' => ['invalid', 422, 'validationFailed', '/api/v2/problems/validation-failed'],
        ];
    }

    #[DataProvider('problems')]
    public function test_a_refusal_of_a_path_job_names_the_job_and_its_mailbox(string $problem, int $status, string $reason, string $type): void
    {
        $this->asAdmin();

        $this->postJson('/api/v2/__testing/sync-jobs/'.$this->job, ['problem' => $problem, 'remotePassword' => self::REMOTE_PASSWORD], $this->csrf())->assertStatus($status);

        $records = $this->records();
        $this->assertCount(1, $records);
        $this->assertSame('syncJobUpdate', $records[0]->event_type);
        $this->assertSame('failure', $records[0]->outcome);
        $this->assertSame($reason, $records[0]->failure_reason);
        $this->assertSame(['syncJob', (string) $this->job, 'alice@example.org'], [$records[0]->target_type, $records[0]->target_id, $records[0]->target_name]);
        $this->assertSame($type, $records[0]->details['problemType']);
        $this->assertSame($status, $records[0]->details['status']);
        $this->assertSame('alice@example.org', $records[0]->details['mailbox']);
        $this->assertStringNotContainsString(self::REMOTE_PASSWORD, json_encode($records) ?: '');

        if ($status === 422) {
            $this->assertSame(['customParameters' => [['code' => 'optionNotAllowed', 'params' => ['option' => 'showpasswords']]]], $records[0]->details['errors']);
        }
    }

    public function test_a_refused_creation_names_the_requested_mailbox_or_the_user_s_own_address(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v2/__testing/sync-jobs', ['problem' => 'notFound', 'mailbox' => 'Nobody@Example.org'], $this->csrf())->assertNotFound();
        $this->asSyncUser('alice@example.org');
        $this->postJson('/api/v2/__testing/sync-jobs', ['problem' => 'invalid'], $this->csrf())->assertUnprocessable();

        $records = $this->records();
        $this->assertCount(2, $records);
        $this->assertSame(['syncJobCreation', 'syncJob', null, 'Nobody@Example.org'], [$records[0]->event_type, $records[0]->target_type, $records[0]->target_id, $records[0]->target_name]);
        $this->assertSame('notFound', $records[0]->failure_reason);
        $this->assertSame(['syncJobCreation', 'alice@example.org', 'alice@example.org'], [$records[1]->event_type, $records[1]->target_name, $records[1]->details['mailbox']]);
    }

    public function test_a_bulk_request_refused_as_a_whole_is_one_record_with_the_event_of_its_action(): void
    {
        $this->mailcow()->domainAdmin('noflag', ['example.org'], ['syncjobs' => 0]);
        $this->signInAs('noflag', Role::DomainAdmin);

        foreach (['delete' => 'syncJobDeletion', 'reschedule' => 'syncJobReschedule', 'activate' => 'syncJobUpdate', 'bogus' => 'syncJobUpdate'] as $action => $event) {
            DB::table('restv2_audit_events')->delete();
            $this->postJson('/api/v2/__testing/sync-jobs/bulk-actions', ['problem' => 'flag', 'action' => $action, 'targets' => [['id' => (string) $this->job], ['id' => '99']]], $this->csrf())->assertForbidden();

            $records = $this->records();
            $this->assertCount(1, $records, $action);
            $this->assertSame($event, $records[0]->event_type);
            $this->assertNull($records[0]->target_type);
            $this->assertSame([(string) $this->job, '99'], $records[0]->details['targetIds']);
            $this->assertSame($action, $records[0]->details['bulkAction']);
            $this->assertSame('permissionDenied', $records[0]->failure_reason);
        }
    }

    public function test_pipeline_refusals_are_recorded_but_csrf_failures_and_anonymous_requests_are_not(): void
    {
        $key = $this->keyHeader($this->apiKey('admin', 'readOnly'));
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->postJson('/api/v2/__testing/sync-jobs/'.$this->job, ['problem' => 'notFound'], $key)->assertForbidden();

        $this->mailcow()->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $this->signInAs('restricted', Role::Admin);
        $this->postJson('/api/v2/__testing/sync-jobs/'.$this->job, ['problem' => 'notFound'], $this->csrf())->assertForbidden();

        $records = $this->records();
        $this->assertCount(2, $records);
        $this->assertSame(['/api/v2/problems/insufficient-key-scope', '/api/v2/problems/pending-action-required'], array_map(fn (stdClass $r): string => $r->details['problemType'], $records));
        $this->assertSame(['permissionDenied', 'permissionDenied'], array_column($records, 'failure_reason'));

        DB::table('restv2_audit_events')->delete();
        $this->asAdmin();
        $this->postJson('/api/v2/__testing/sync-jobs/'.$this->job, ['problem' => 'notFound'], ['X-CSRF-Token' => 'wrong'])->assertForbidden();
        $this->forgetCookies();
        $this->postJson('/api/v2/__testing/sync-jobs/'.$this->job, ['problem' => 'notFound'])->assertUnauthorized();
        $this->assertSame([], $this->records());
    }
}
