<?php

namespace Tests\Feature\Routing\Support;

use App\Audit\AuditEventType;
use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\PermissionFlagRequiredException;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\Routing\RoutingAudit;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T016 (FR-085, data-model.md audit table, research R22): one success record per target
 * with passwords only as `set`, `changed` or `removed` and the documented keys only; and one failure
 * record per refusal of an authenticated caller on a route declaring `restv2.audit`, with the
 * target this feature's resolvers name. `401` and CSRF failures write nothing.
 */
class RoutingAuditTest extends TestCase
{
    use RoutingTestHelpers;

    /** target type => [test path, route parameter value, expected target id, expected name] */
    private const TARGETS = [
        'relayhost' => ['/api/v2/__testing/routing/relayhosts/{id}', '{relayhost}', '{relayhost}', '[smtp.example.net]:587'],
        'transport' => ['/api/v2/__testing/routing/transports/{id}', '{transport}', '{transport}', 'example.com'],
        'bccMap' => ['/api/v2/__testing/routing/bcc-maps/{id}', '{bccMap}', '{bccMap}', '@example.org'],
        'recipientMap' => ['/api/v2/__testing/routing/recipient-maps/{id}', '{recipientMap}', '{recipientMap}', '@old.example'],
        'tlsPolicyMap' => ['/api/v2/__testing/routing/tls-policy-maps/{id}', '{tlsPolicyMap}', '{tlsPolicyMap}', 'example.org'],
        'forwardingHost' => ['/api/v2/__testing/routing/forwarding-hosts/{id}', '192.0.2.0%2F24', '192.0.2.0/24', '192.0.2.0/24'],
        'domainRelayhostAssignment' => ['/api/v2/__testing/routing/domains/{id}/relayhost-assignment', 'B%C3%BCcher.Example', 'xn--bcher-kva.example', 'xn--bcher-kva.example'],
        'mailboxRelayhostAssignment' => ['/api/v2/__testing/routing/mailboxes/{id}/relayhost-assignment', 'Anna@Example.ORG', 'anna@example.org', 'anna@example.org'],
    ];

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $this->ids = [
            '{relayhost}' => (string) $f->relayhost(null, '[smtp.example.net]:587', 'acct', 's3cret'),
            '{transport}' => (string) $f->transport('example.com', '[mx.partner.net]:25'),
            '{bccMap}' => (string) $f->bccMap('@example.org', 'archive@backup.net', 'example.org'),
            '{recipientMap}' => (string) $f->recipientMap('@old.example', '@new.example'),
            '{tlsPolicyMap}' => (string) $f->tlsPolicyMap('example.org', 'dane-only'),
        ];

        $routes = [
            'relayhost' => ['relayhosts/{relayhostId}', 'relayhostUpdate'],
            'transport' => ['transports/{transportId}', 'transportUpdate'],
            'bccMap' => ['bcc-maps/{bccMapId}', 'bccMapUpdate'],
            'recipientMap' => ['recipient-maps/{recipientMapId}', 'recipientMapUpdate'],
            'tlsPolicyMap' => ['tls-policy-maps/{tlsPolicyMapId}', 'tlsPolicyMapUpdate'],
            'forwardingHost' => ['forwarding-hosts/{forwardingHostAddress}', 'forwardingHostUpdate'],
            'domainRelayhostAssignment' => ['domains/{domain}/relayhost-assignment', 'relayhostAssignmentUpdate'],
            'mailboxRelayhostAssignment' => ['mailboxes/{mailbox}/relayhost-assignment', 'relayhostAssignmentUpdate'],
        ];

        foreach ($routes as $target => [$path, $event]) {
            Route::middleware(['api.v2', 'restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])
                ->post('/api/v2/__testing/routing/'.$path, fn (Request $request) => self::refuse($request))
                ->where('forwardingHostAddress', '.+')
                ->where('domain', '[^/]+')
                ->where('mailbox', '[^/]+')
                ->defaults('restv2.audit', ['event' => $event, 'target' => $target]);
        }
    }

    private static function refuse(Request $request): never
    {
        match ($request->query('fail')) {
            'forbidden' => throw new ProblemException(ProblemType::Forbidden),
            'flag' => throw new PermissionFlagRequiredException(['domainRelayhost']),
            'notFound' => throw new NotFoundHttpException('gone'),
            'validation' => (function (): never {
                $violations = new FieldViolations;
                $violations->add('host', 'invalidFormat', ['format' => 'nextHop']);
                $violations->throwIfAny();
                throw new RuntimeException('unreachable');
            })(),
            'conflict' => throw new ProblemException(ProblemType::RoutingEntryExists, null, ['field' => 'destination', 'conflictingId' => '1']),
            'outdated' => throw new PreconditionFailedHttpException('outdated'),
            'temporary' => throw new ProblemException(ProblemType::TemporaryFailure, null, ['dependency' => 'sharedStore'], ['Retry-After' => '5']),
            default => throw new RuntimeException('no failure requested'),
        };
    }

    private static function principal(): Principal
    {
        return new Principal('admin', Role::Admin, AccountKind::Admin, AuthMethod::Password);
    }

    public function test_a_success_record_names_its_target_and_records_passwords_as_state_changes_only(): void
    {
        DB::transaction(fn () => app(RoutingAudit::class)->success(
            AuditEventType::RelayhostUpdate,
            self::principal(),
            RoutingAudit::target('relayhost', 7, '[smtp.example.net]:587'),
            ['changes' => ['password' => RoutingAudit::passwordChange('old', 'new'), 'active' => ['old' => true, 'new' => false]], 'password' => 's3cret', 'secret' => 'x'],
        ));

        [$record] = $this->auditRecords('relayhostUpdate', 'relayhost', '7');
        $this->assertSame('success', $record->outcome);
        $this->assertSame('[smtp.example.net]:587', $record->target_name);
        $this->assertSame(['changes' => ['password' => 'changed', 'active' => ['old' => true, 'new' => false]]], $record->details);
    }

    public function test_the_password_states(): void
    {
        $this->assertSame('set', RoutingAudit::passwordChange('', 'new'));
        $this->assertSame('changed', RoutingAudit::passwordChange('old', 'new'));
        $this->assertSame('removed', RoutingAudit::passwordChange('old', ''));
        $this->assertNull(RoutingAudit::passwordChange('same', 'same'));
        $this->assertNull(RoutingAudit::passwordChange('', ''));
    }

    public function test_a_bulk_item_carries_the_bulk_request_id(): void
    {
        app(RoutingAudit::class)->success(AuditEventType::TransportUpdate, self::principal(), RoutingAudit::target('transport', 3, 'example.com'), ['changes' => []], 'f'.str_repeat('0', 31));

        [$record] = $this->auditRecords('transportUpdate', 'transport', '3');
        $this->assertSame('f'.str_repeat('0', 31), $record->details['bulkRequestId']);
    }

    public function test_a_failing_audit_insert_rolls_the_change_back(): void
    {
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(AuditEventType $type, bool $success, Principal|array|null $actor, ?array $target, ?string $authMethod, ?string $failureReason, array $details): never
            {
                throw new RuntimeException('audit insert failed');
            }
        });
        $id = (int) $this->ids['{relayhost}'];

        try {
            DB::transaction(function () use ($id): void {
                DB::table('relayhosts')->where('id', $id)->update(['active' => 0]);
                app(RoutingAudit::class)->success(AuditEventType::RelayhostUpdate, self::principal(), RoutingAudit::target('relayhost', $id, 'x'), []);
            });
            $this->fail('The failure was swallowed.');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, (int) $this->relayhostRow($id)?->active);
    }

    public function test_targets_are_cut_to_255_characters(): void
    {
        $target = RoutingAudit::target('transport', null, str_repeat('x', 300));

        $this->assertSame(['type' => 'transport', 'id' => null, 'name' => str_repeat('x', 255)], $target);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refusals(): array
    {
        $cases = [];

        foreach (array_keys(self::TARGETS) as $target) {
            foreach ([
                'forbidden' => ['permissionDenied', '/api/v2/problems/forbidden'],
                'flag' => ['permissionDenied', '/api/v2/problems/permission-flag-required'],
                'notFound' => ['notFound', 'about:blank'],
                'validation' => ['validationFailed', '/api/v2/problems/validation-failed'],
                'conflict' => ['conflict', '/api/v2/problems/routing-entry-exists'],
                'outdated' => ['versionOutdated', 'about:blank'],
                'temporary' => ['temporaryFailure', '/api/v2/problems/temporary-failure'],
            ] as $fail => [$reason, $type]) {
                $cases["{$target} {$fail}"] = [$target, $fail, $reason.'|'.$type];
            }
        }

        return $cases;
    }

    #[DataProvider('refusals')]
    public function test_a_refusal_writes_one_failure_record_for_the_target(string $target, string $fail, string $expected): void
    {
        [$reason, $type] = explode('|', $expected);
        [$path, $raw, $id, $name] = self::TARGETS[$target];
        $this->signInAs('admin', Role::Admin);
        $before = $this->auditCount();

        $this->postJson(str_replace('{id}', strtr($raw, $this->ids), $path).'?fail='.$fail, ['host' => 'x'], $this->csrf());

        $this->assertSame($before + 1, $this->auditCount());
        $record = DB::table('restv2_audit_events')->orderByDesc('occurred_at')->orderByDesc('id')->first();
        $this->assertNotNull($record);
        $details = json_decode((string) $record->details, true);
        $this->assertSame('failure', $record->outcome);
        $this->assertSame($target, $record->target_type);
        $this->assertSame(strtr($id, $this->ids), $record->target_id);
        $this->assertSame($reason, $record->failure_reason);
        $this->assertSame($type, $details['problemType']);

        if ($fail === 'flag') {
            $this->assertSame(['domainRelayhost'], $details['requiredFlags']);
        }

        if ($fail === 'validation') {
            $this->assertSame(['host' => [['code' => 'invalidFormat', 'params' => ['format' => 'nextHop']]]], $details['errors']);
        }

        if (! in_array($target, ['forwardingHost', 'domainRelayhostAssignment', 'mailboxRelayhostAssignment'], true)) {
            $this->assertSame(strtr($name, $this->ids), $record->target_name);
        }
    }

    public function test_a_read_only_key_and_a_restricted_session_are_recorded_as_permission_denied(): void
    {
        $key = $this->apiKey('admin', 'readOnly');
        $this->postJson('/api/v2/__testing/routing/relayhosts/'.$this->ids['{relayhost}'], [], $this->keyHeader($key))->assertStatus(403);

        $this->signInAs('restricted', Role::Admin);
        $this->postJson('/api/v2/__testing/routing/transports/'.$this->ids['{transport}'], [], $this->csrf())->assertStatus(403);

        $records = DB::table('restv2_audit_events')->where('outcome', 'failure')->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertCount(2, $records);
        $this->assertSame(['permissionDenied', 'permissionDenied'], $records->pluck('failure_reason')->all());
        $this->assertSame('/api/v2/problems/insufficient-key-scope', json_decode((string) $records[0]->details, true)['problemType']);
        $this->assertSame('/api/v2/problems/pending-action-required', json_decode((string) $records[1]->details, true)['problemType']);
    }

    public function test_unauthenticated_and_csrf_refusals_write_nothing(): void
    {
        $this->postJson('/api/v2/__testing/routing/relayhosts/'.$this->ids['{relayhost}'].'?fail=forbidden', [])->assertStatus(401);
        $this->signInAs('admin', Role::Admin);
        $this->postJson('/api/v2/__testing/routing/relayhosts/'.$this->ids['{relayhost}'].'?fail=forbidden', [])->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');

        $this->assertSame(0, $this->auditCount());
    }
}
