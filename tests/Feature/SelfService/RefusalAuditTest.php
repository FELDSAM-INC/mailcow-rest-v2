<?php

namespace Tests\Feature\SelfService;

use App\Auth\PermissionFlag;
use App\Auth\Role;
use App\Exceptions\PermissionFlagRequiredException;
use App\Exceptions\ProblemException;
use App\Services\Audit\RefusalAuditor;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * T013 (docs/conventions.md "Refusal auditing", feature 005 research R22): refusals of
 * authenticated callers on self-service routes are audited once with the feature's event and
 * target types; the target of a mailbox object is the mailbox address, the target of an own-account
 * function the account itself.
 */
class RefusalAuditTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $action = function (Request $request): never {
            match ($request->input('problem')) {
                'flag' => throw new PermissionFlagRequiredException([PermissionFlag::AppPasswds]),
                'notFound' => throw new NotFoundHttpException('The mailbox does not exist.'),
                'outdated' => throw new HttpException(412, 'The resource has changed.'),
                'invalid' => (function (): never {
                    $violations = new FieldViolations;
                    $violations->add('name', 'tooLong', ['max' => 255]);
                    $violations->throwIfAny();

                    throw new \LogicException('unreachable');
                })(),
                'currentPassword' => throw new ProblemException(ProblemType::CurrentPasswordIncorrect),
                'audited' => (function () use ($request): never {
                    RefusalAuditor::markRecorded($request);

                    throw new ProblemException(ProblemType::Forbidden);
                })(),
                default => throw new \RuntimeException('unexpected'),
            };
        };

        Route::middleware(['api.v2', 'restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])
            ->post('/api/v2/__testing/self-service/mailboxes/{mailbox}/app-passwords', $action)
            ->defaults('restv2.audit', ['event' => 'appPasswordCreation', 'target' => 'appPassword']);
        Route::middleware(['api.v2', 'restv2.auth:session', 'restv2.csrf', 'restv2.pending'])
            ->post('/api/v2/__testing/self-service/me/password', $action)
            ->defaults('restv2.audit', ['event' => 'ownPasswordChange', 'target' => 'accountPassword']);
    }

    /**
     * @return array<string, array{string, int, string, string}>
     */
    public static function refusals(): array
    {
        return [
            'missing flag' => ['flag', 403, 'permissionDenied', '/api/v2/problems/permission-flag-required'],
            'out of scope' => ['notFound', 404, 'notFound', 'about:blank'],
            'outdated' => ['outdated', 412, 'versionOutdated', 'about:blank'],
            'invalid' => ['invalid', 422, 'validationFailed', '/api/v2/problems/validation-failed'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_refusal_on_a_mailbox_object_is_recorded_against_the_mailbox(string $problem, int $status, string $reason, string $type): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->asAdmin();

        $this->postJson('/api/v2/__testing/self-service/mailboxes/ANNA@example.org/app-passwords', ['problem' => $problem, 'password' => 'hunter2'], $this->csrf())->assertStatus($status);

        $records = $this->auditOf('appPasswordCreation');
        $this->assertCount(1, $records);
        $this->assertSame('failure', $records[0]->outcome);
        $this->assertSame($reason, $records[0]->failure_reason);
        $this->assertSame('appPassword', $records[0]->target_type);
        $this->assertSame('anna@example.org', $records[0]->target_id);
        $this->assertSame($type, $records[0]->details['problemType']);
        $this->assertSame($status, $records[0]->details['status']);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($records[0]));

        if ($problem === 'flag') {
            $this->assertSame(['appPasswds'], $records[0]->details['requiredFlags']);
        }
    }

    public function test_a_read_only_key_and_a_restricted_session_are_recorded_as_permission_denied(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $key = $this->apiKey('admin', 'readOnly');

        $this->postJson('/api/v2/__testing/self-service/mailboxes/anna@example.org/app-passwords', ['problem' => 'flag'], $this->keyHeader($key))->assertStatus(403);

        DB::table('mailbox')->where('username', 'anna@example.org')->update(['attributes' => DB::raw("JSON_SET(`attributes`, '$.force_pw_update', '1')")]);
        $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->postJson('/api/v2/__testing/self-service/mailboxes/anna@example.org/app-passwords', ['problem' => 'flag'], $this->csrf())->assertStatus(403);

        $records = $this->auditOf('appPasswordCreation');
        $this->assertCount(2, $records);
        $this->assertSame('/api/v2/problems/insufficient-key-scope', $records[0]->details['problemType']);
        $this->assertSame('/api/v2/problems/pending-action-required', $records[1]->details['problemType']);
        $this->assertSame(['permissionDenied', 'permissionDenied'], [$records[0]->failure_reason, $records[1]->failure_reason]);
    }

    public function test_an_own_account_refusal_is_recorded_against_the_account_with_its_specific_reason(): void
    {
        $this->mailcow()->domainAdmin('reseller', []);
        $this->signInAs('reseller', Role::DomainAdmin);

        $this->postJson('/api/v2/__testing/self-service/me/password', ['problem' => 'currentPassword'], $this->csrf())->assertStatus(403);

        $record = $this->auditOf('ownPasswordChange')[0];
        $this->assertSame('currentPasswordIncorrect', $record->failure_reason);
        $this->assertSame('accountPassword', $record->target_type);
        $this->assertSame('reseller', $record->target_id);
    }

    public function test_csrf_failures_and_unauthenticated_requests_write_nothing(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');

        $this->postJson('/api/v2/__testing/self-service/mailboxes/anna@example.org/app-passwords', ['problem' => 'flag'])->assertStatus(401);
        $this->asAdmin();
        $this->postJson('/api/v2/__testing/self-service/mailboxes/anna@example.org/app-passwords', ['problem' => 'flag'], ['X-CSRF-Token' => 'wrong'])->assertStatus(403);

        $this->assertSame([], $this->auditOf('appPasswordCreation'));
    }

    public function test_a_refusal_already_audited_by_a_service_is_not_recorded_twice(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->asAdmin();

        $this->postJson('/api/v2/__testing/self-service/mailboxes/anna@example.org/app-passwords', ['problem' => 'audited'], $this->csrf())->assertStatus(403);

        $this->assertSame([], $this->auditOf('appPasswordCreation'));
    }
}
