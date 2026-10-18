<?php

namespace Tests\Feature\Audit;

use App\Auth\Role;
use App\Clients\DockerApi\DockerCallResult;
use Illuminate\Support\Facades\DB;
use stdClass;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 003 T016 (docs/conventions.md "Refusal auditing", FR-056, SC-010): a refusal of an
 * authenticated caller by any of the eight state-changing operations writes exactly one failure
 * record with its event type, target, reason and problem type; CSRF failures and `401`s write none.
 */
class MailboxRefusalAuditTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    private int $templateId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org', attributes: ['mailboxes' => 2]);
        $this->mailcow()->domain('foreign.example');
        $this->mailcow()->legacyMailbox('anna@example.org');
        $this->mailcow()->legacyMailbox('carl@foreign.example');
        $this->templateId = $this->mailcow()->mailboxTemplate('Small', ['quota' => self::GIB]);
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
    }

    /**
     * @return list<stdClass>
     */
    private function failures(): array
    {
        return array_values(array_filter(
            DB::table('restv2_audit_events')->orderBy('occurred_at')->orderBy('id')->get()->map(function (stdClass $row): stdClass {
                $row->details = json_decode((string) $row->details, true);

                return $row;
            })->all(),
            fn (stdClass $row): bool => $row->outcome === 'failure',
        ));
    }

    /**
     * @param  array{0: string, 1: string|null, 2: string|null, 3: string, 4: string}  $expected  event, target type, target ID, reason, problem type
     */
    private function assertOneFailure(array $expected): void
    {
        $failures = $this->failures();
        $this->assertCount(1, $failures, json_encode($failures) ?: '');
        $record = $failures[0];
        $this->assertSame($expected, [$record->event_type, $record->target_type, $record->target_id, $record->failure_reason, $record->details['problemType']]);
        DB::table('restv2_audit_events')->delete();
    }

    public function test_read_only_keys_restricted_sessions_and_mailbox_users(): void
    {
        $key = $this->keyHeader($this->apiKey('admin', 'readOnly'));
        $operations = [
            ['POST', '/api/v2/mailboxes', ['localPart' => 'new', 'domain' => 'example.org', 'password' => '{PLAIN}x'], 'mailboxCreation', 'mailbox', 'new@example.org'],
            ['PATCH', '/api/v2/mailboxes/anna@example.org', ['displayName' => 'A'], 'mailboxUpdate', 'mailbox', 'anna@example.org'],
            ['DELETE', '/api/v2/mailboxes/anna@example.org', null, 'mailboxDeletion', 'mailbox', 'anna@example.org'],
            ['POST', '/api/v2/mailboxes/anna@example.org/rename', ['newLocalPart' => 'x', 'keepOldAddressAsAlias' => false], 'mailboxRename', 'mailbox', 'anna@example.org'],
            ['POST', '/api/v2/mailboxes/bulk-actions', ['action' => 'delete', 'targets' => [['id' => 'anna@example.org']]], 'mailboxDeletion', null, null],
            ['POST', '/api/v2/mailbox-templates', ['name' => 'New'], 'mailboxTemplateCreation', null, null],
            ['PATCH', '/api/v2/mailbox-templates/'.$this->templateId, ['quota' => 0], 'mailboxTemplateUpdate', 'mailboxTemplate', (string) $this->templateId],
            ['DELETE', '/api/v2/mailbox-templates/'.$this->templateId, null, 'mailboxTemplateDeletion', 'mailboxTemplate', (string) $this->templateId],
            ['POST', '/api/v2/mailbox-templates/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $this->templateId]]], 'mailboxTemplateDeletion', null, null],
        ];

        foreach ($operations as [$method, $path, $body, $event, $targetType, $targetId]) {
            $method === 'PATCH' ? $this->patchMerge($path, (array) $body, $key)->assertStatus(403) : $this->json($method, $path, $body ?? [], $key)->assertStatus(403);
            $this->assertOneFailure([$event, $event === 'mailboxTemplateCreation' ? 'mailboxTemplate' : $targetType, $targetId, 'permissionDenied', '/api/v2/problems/insufficient-key-scope']);
        }

        $this->mailcow()->admin('restricted', MailcowFactory::PASSWORD, attributes: ['force_pw_update' => '1']);
        $this->signInAs('restricted', Role::Admin);
        $this->patchMailbox('anna@example.org', ['displayName' => 'A'])->assertStatus(403);
        $this->assertOneFailure(['mailboxUpdate', 'mailbox', 'anna@example.org', 'permissionDenied', '/api/v2/problems/pending-action-required']);

        $this->forgetCookies();
        $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->deleteMailbox('anna@example.org')->assertStatus(403);
        $this->assertOneFailure(['mailboxDeletion', 'mailbox', 'anna@example.org', 'permissionDenied', '/api/v2/problems/forbidden']);
    }

    public function test_domain_administrator_refusals(): void
    {
        $this->asDomainAdmin(['example.org'], daAcl: ['protocol_access' => 0]);

        $this->deleteJson('/api/v2/mailbox-templates/'.$this->templateId, [], $this->csrf())->assertStatus(403);
        $this->assertOneFailure(['mailboxTemplateDeletion', 'mailboxTemplate', (string) $this->templateId, 'permissionDenied', '/api/v2/problems/forbidden']);

        $this->postJson('/api/v2/mailboxes/bulk-actions', ['action' => 'disallowProtocol', 'protocol' => 'pop3', 'targets' => [['id' => 'anna@example.org']]], $this->csrf())->assertStatus(403);
        $this->assertOneFailure(['mailboxUpdate', null, null, 'permissionDenied', '/api/v2/problems/permission-flag-required']);

        $this->patchMailbox('anna@example.org', ['protocolAccess' => ['pop3' => false]])->assertStatus(403);
        $this->assertOneFailure(['mailboxUpdate', 'mailbox', 'anna@example.org', 'attributeNotPermitted', '/api/v2/problems/attribute-not-permitted']);

        $this->patchMailbox('carl@foreign.example', ['displayName' => 'C'])->assertNotFound();
        $this->assertOneFailure(['mailboxUpdate', 'mailbox', 'carl@foreign.example', 'notFound', 'about:blank']);
    }

    public function test_versions_validation_and_conflicts(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/mailboxes/anna@example.org')->headers->get('ETag');
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['name' => 'Changed']);

        $this->deleteMailbox('anna@example.org', ['If-Match' => $etag])->assertStatus(412);
        $this->assertOneFailure(['mailboxDeletion', 'mailbox', 'anna@example.org', 'versionOutdated', 'about:blank']);

        $this->createMailbox(['localPart' => '.bad', 'domain' => 'example.org', 'password' => '{PLAIN}x'])->assertStatus(422);
        $failures = $this->failures();
        $this->assertSame(['invalidFormat'], array_column($failures[0]->details['errors']['localPart'], 'code'));
        $this->assertOneFailure(['mailboxCreation', 'mailbox', '.bad@example.org', 'validationFailed', '/api/v2/problems/validation-failed']);

        $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x'])->assertStatus(409);
        $this->assertOneFailure(['mailboxCreation', 'mailbox', 'anna@example.org', 'conflict', '/api/v2/problems/address-in-use']);

        $this->postJson('/api/v2/mailbox-templates', ['name' => 'small'], $this->csrf())->assertStatus(409);
        $this->assertOneFailure(['mailboxTemplateCreation', 'mailboxTemplate', null, 'conflict', '/api/v2/problems/mailbox-template-name-exists']);
    }

    public function test_service_failures_are_recorded_once(): void
    {
        $this->asAdmin();

        $this->dockerApi()->script('dovecot-mailcow', 'maildir', 'cleanup', DockerCallResult::failed('busy'));
        $this->deleteMailbox('anna@example.org')->assertStatus(503);
        $this->assertOneFailure(['mailboxDeletion', 'mailbox', 'anna@example.org', 'temporaryFailure', '/api/v2/problems/temporary-failure']);

        $this->dockerApi()->script('sogo-mailcow', 'sogo', 'rename_user', DockerCallResult::failed('boom'));
        $this->postJson('/api/v2/mailboxes/anna@example.org/rename', ['newLocalPart' => 'x', 'keepOldAddressAsAlias' => false], $this->csrf())->assertStatus(503);
        $this->assertOneFailure(['mailboxRename', 'mailbox', 'anna@example.org', 'temporaryFailure', '/api/v2/problems/temporary-failure']);
    }

    public function test_csrf_failures_and_anonymous_requests_write_nothing(): void
    {
        $this->asAdmin();
        $this->deleteJson('/api/v2/mailboxes/anna@example.org')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');

        $this->forgetCookies();
        $this->deleteJson('/api/v2/mailboxes/anna@example.org')->assertStatus(401);

        $this->assertSame([], $this->failures());
    }
}
