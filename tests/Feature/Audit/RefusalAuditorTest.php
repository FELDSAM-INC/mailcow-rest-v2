<?php

namespace Tests\Feature\Audit;

use App\Auth\Role;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Exceptions\ProblemException;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\Support\Http\NonEmptyBodyProbeRequest;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * docs/conventions.md "Refusal auditing" (the mechanism 008 designed, created with feature 002):
 * every refusal of an authenticated caller on a route that declares `restv2.audit` writes exactly
 * one failure record with the shared failure reason; CSRF failures, `401`s and routes without the
 * declaration write none, and no record holds request values.
 */
class RefusalAuditorTest extends TestCase
{
    use ApiKeys;
    use DomainTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $action = function (Request $request, string $domain): never {
            match ($request->input('problem')) {
                'forbidden' => throw new ProblemException(ProblemType::Forbidden),
                'attribute' => throw new ProblemException(ProblemType::AttributeNotPermitted, null, ['attributes' => ['active']]),
                'notFound' => throw new NotFoundHttpException('The domain does not exist.'),
                'conflict' => throw new ProblemException(ProblemType::DomainNotEmpty, null, ['mailboxes' => 1, 'resources' => 0]),
                'outdated' => throw new HttpException(412, 'The resource has changed.'),
                'invalid' => (function (): never {
                    $violations = new FieldViolations;
                    $violations->add('maxMailboxes', 'belowInUse', ['inUse' => 8]);
                    $violations->throwIfAny();

                    throw new \LogicException('unreachable');
                })(),
                'unavailable' => throw new SharedStoreUnavailable('connection refused'),
                default => throw new \RuntimeException('unexpected'),
            };
        };

        Route::middleware(['api.v2', 'restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])
            ->post('/api/v2/__testing/refusals/{domain}', $action)
            ->defaults('restv2.audit', ['event' => 'domainUpdate', 'target' => 'domain']);
        Route::middleware(['api.v2', 'restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])
            ->post('/api/v2/__testing/unaudited/{domain}', $action);
        Route::middleware(['api.v2', 'restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])
            ->post('/api/v2/__testing/refusals-json/{domain}', fn (NonEmptyBodyProbeRequest $request) => response()->noContent())
            ->defaults('restv2.audit', ['event' => 'domainUpdate', 'target' => 'domain']);
    }

    /**
     * @return list<\stdClass>
     */
    private function refusals(): array
    {
        return $this->auditOf('domainUpdate');
    }

    /**
     * @return array<string, array{string, int, string, string}>
     */
    public static function problems(): array
    {
        return [
            'forbidden' => ['forbidden', 403, 'permissionDenied', '/api/v2/problems/forbidden'],
            'attribute' => ['attribute', 403, 'attributeNotPermitted', '/api/v2/problems/attribute-not-permitted'],
            'notFound' => ['notFound', 404, 'notFound', 'about:blank'],
            'conflict' => ['conflict', 409, 'conflict', '/api/v2/problems/domain-not-empty'],
            'outdated' => ['outdated', 412, 'versionOutdated', 'about:blank'],
            'invalid' => ['invalid', 422, 'validationFailed', '/api/v2/problems/validation-failed'],
            'unavailable' => ['unavailable', 503, 'temporaryFailure', '/api/v2/problems/temporary-failure'],
        ];
    }

    #[DataProvider('problems')]
    public function test_each_refusal_writes_one_failure_record(string $problem, int $status, string $reason, string $type): void
    {
        $this->asAdmin();

        $this->postJson('/api/v2/__testing/refusals/M%C3%9CNCHEN.de', ['problem' => $problem, 'secret' => 'hunter2'], $this->csrf())->assertStatus($status);

        $records = $this->refusals();
        $this->assertCount(1, $records);
        $record = $records[0];
        $this->assertSame('failure', $record->outcome);
        $this->assertSame($reason, $record->failure_reason);
        $this->assertSame('admin', $record->actor_username);
        $this->assertSame('domain', $record->target_type);
        $this->assertSame('xn--mnchen-3ya.de', $record->target_id);
        $this->assertSame('münchen.de', $record->target_name);
        $this->assertSame($type, $record->details['problemType']);
        $this->assertSame($status, $record->details['status']);
        $this->assertStringNotContainsString('hunter2', json_encode($record) ?: '');
    }

    public function test_validation_errors_keep_codes_and_parameters_only(): void
    {
        $this->asAdmin();

        $this->postJson('/api/v2/__testing/refusals/example.org', ['problem' => 'invalid'], $this->csrf())->assertStatus(422);

        $this->assertSame(['maxMailboxes' => [['code' => 'belowInUse', 'params' => ['inUse' => 8]]]], $this->refusals()[0]->details['errors']);
    }

    public function test_pipeline_refusals_of_authenticated_callers_are_recorded(): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $key = $this->apiKey('admin', 'readOnly');
        $this->postJson('/api/v2/__testing/refusals/example.org', ['problem' => 'forbidden'], $this->keyHeader($key))->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->mailcow()->admin('restricted', MailcowFactory::PASSWORD, attributes: ['force_pw_update' => '1']);
        $this->signInAs('restricted', Role::Admin);
        $this->postJson('/api/v2/__testing/refusals/example.org', ['problem' => 'forbidden'], $this->csrf())->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/pending-action-required');

        $records = $this->refusals();
        $this->assertCount(2, $records);
        $this->assertSame(['permissionDenied', 'permissionDenied'], array_column($records, 'failure_reason'));
        $this->assertSame('/api/v2/problems/insufficient-key-scope', $records[0]->details['problemType']);
        $this->assertNotNull($records[0]->actor_api_key_id);
        $this->assertSame('/api/v2/problems/pending-action-required', $records[1]->details['problemType']);
    }

    public function test_a_malformed_body_is_a_validation_failure(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->call('POST', '/api/v2/__testing/refusals-json/example.org', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken()], '{"broken":')->assertStatus(400);

        $this->assertSame('validationFailed', $this->refusals()[0]->failure_reason ?? null);
    }

    public function test_csrf_failures_unauthenticated_requests_and_unmarked_routes_write_nothing(): void
    {
        $this->postJson('/api/v2/__testing/refusals/example.org', ['problem' => 'forbidden'])->assertStatus(401);

        $this->asAdmin();
        $this->postJson('/api/v2/__testing/refusals/example.org', ['problem' => 'forbidden'])->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        $this->postJson('/api/v2/__testing/unaudited/example.org', ['problem' => 'forbidden'], $this->csrf())->assertStatus(403);
        $this->postJson('/api/v2/__testing/refusals/example.org', ['problem' => 'boom'], $this->csrf())->assertStatus(500);

        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }
}
