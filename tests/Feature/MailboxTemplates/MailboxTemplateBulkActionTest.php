<?php

namespace Tests\Feature\MailboxTemplates;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 003, gap G39: legacy deletes several mailbox templates at once (`delete_selected` on
 * `tab-templates-mbox.twig`). Each target is deleted, checked and audited like the single deletion,
 * with one result per distinct template.
 */
class MailboxTemplateBulkActionTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    /**
     * @param  list<array<string, string>|string|int>  $targets
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function bulk(array $targets, string $action = 'delete', array $extra = []): TestResponse
    {
        $body = ['action' => $action, 'targets' => array_map(fn (mixed $t): array => is_array($t) ? $t : ['id' => (string) $t], $targets)] + $extra;

        return $this->postJson('/api/v2/mailbox-templates/bulk-actions', $body, $this->csrf());
    }

    public function test_several_templates_are_deleted_with_one_record_each(): void
    {
        $default = $this->mailcow()->defaultMailboxTemplate();
        $ids = [
            $this->mailcow()->mailboxTemplate('Small', ['quota' => self::GIB]),
            $this->mailcow()->mailboxTemplate('Students', ['quota' => 2 * self::GIB]),
            $this->mailcow()->mailboxTemplate('Staff'),
        ];
        $this->asAdmin();

        $data = $this->bulk($ids)->assertOk()->json('data');

        $this->assertSame(['total' => 3, 'done' => 3, 'notFound' => 0, 'refused' => 0, 'outdated' => 0, 'failed' => 0], $data['summary']);
        $this->assertSame(array_map('strval', $ids), array_column($data['results'], 'id'));
        $this->assertSame([], array_filter(array_column($data['results'], 'version')), 'A deleted template has no new version.');
        $this->assertSame([$default], DB::table('templates')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        $records = $this->auditOf('mailboxTemplateDeletion');
        $this->assertSame(array_map('strval', $ids), array_column($records, 'target_id'));
        $this->assertSame(['Small', 'Students', 'Staff'], array_column($records, 'target_name'));
        $this->assertSame(['success'], array_values(array_unique(array_column($records, 'outcome'))));
        $this->assertSame([$data['bulkRequestId']], array_values(array_unique(array_map(fn (object $r): string => $r->details['bulkRequestId'], $records))));
        $this->assertSame(2 * self::GIB, $records[1]->details['values']['quota']);
    }

    public function test_the_default_template_is_refused_and_the_others_proceed(): void
    {
        $default = $this->mailcow()->defaultMailboxTemplate();
        $small = $this->mailcow()->mailboxTemplate('Small');
        $this->asAdmin();

        $data = $this->bulk([$default, $small])->assertOk()->json('data');

        $this->assertSame(['refused', 'done'], array_column($data['results'], 'outcome'));
        $this->assertSame('/api/v2/problems/mailbox-template-protected', $data['results'][0]['problem']['type']);
        $this->assertSame(409, $data['results'][0]['problem']['status']);
        $this->assertSame('Default', DB::table('templates')->where('id', $default)->value('template'));

        $failure = array_values(array_filter($this->auditOf('mailboxTemplateDeletion'), fn (object $r): bool => $r->outcome === 'failure'))[0];
        $this->assertSame(
            ['mailboxTemplate', (string) $default, 'Default', 'conflict', '/api/v2/problems/mailbox-template-protected', $data['bulkRequestId']],
            [$failure->target_type, $failure->target_id, $failure->target_name, $failure->failure_reason, $failure->details['problemType'], $failure->details['bulkRequestId']],
        );
    }

    public function test_unknown_ids_domain_templates_and_repeated_ids(): void
    {
        $small = $this->mailcow()->mailboxTemplate('Small');
        $domainTemplate = $this->mailcow()->domainTemplate('Domains');
        $this->asAdmin();

        $data = $this->bulk([$small, '0'.$small, 999999, $domainTemplate, 'abc'])->assertOk()->json('data');

        $this->assertSame(
            [[(string) $small, 'done'], ['999999', 'notFound'], [(string) $domainTemplate, 'notFound'], ['abc', 'notFound']],
            array_map(fn (array $r): array => [$r['id'], $r['outcome']], $data['results']),
        );
        $this->assertArrayNotHasKey('problem', $data['results'][1]);
        $this->assertNotNull(DB::table('templates')->where('id', $domainTemplate)->first(), 'A domain template is never deleted here.');
        $this->assertSame(['success', 'failure', 'failure', 'failure'], array_column($this->auditOf('mailboxTemplateDeletion'), 'outcome'), 'One record per distinct target.');
    }

    public function test_outdated_versions_affect_only_their_item(): void
    {
        $ids = [$this->mailcow()->mailboxTemplate('One'), $this->mailcow()->mailboxTemplate('Two'), $this->mailcow()->mailboxTemplate('Three')];
        $this->asAdmin();
        $tags = [];

        foreach ((array) $this->getJson('/api/v2/mailbox-templates')->json('data') as $entry) {
            $tags[$entry['id']] = $entry['etag'];
        }

        DB::table('templates')->where('id', $ids[1])->update(['template' => 'Two (changed in the legacy UI)']);

        $data = $this->bulk([
            ['id' => (string) $ids[0], 'version' => trim($tags[$ids[0]], '"')],
            ['id' => (string) $ids[1], 'version' => $tags[$ids[1]]],
            ['id' => (string) $ids[2]],
        ])->assertOk()->json('data');

        $this->assertSame(['done', 'outdated', 'done'], array_column($data['results'], 'outcome'));
        $this->assertSame(412, $data['results'][1]['problem']['status']);
        $this->assertNotNull(DB::table('templates')->where('id', $ids[1])->first());
    }

    public function test_invalid_requests_are_refused_as_a_whole(): void
    {
        $this->withoutRequestValidation();
        $small = $this->mailcow()->mailboxTemplate('Small');
        $this->asAdmin();

        $this->bulk(range(1, 101))->assertStatus(422)
            ->assertJsonPath('errors.targets.0.code', 'tooMany')
            ->assertJsonPath('errors.targets.0.params.max', 100);
        $this->bulk([$small], 'activate')->assertStatus(422)->assertJsonPath('errors.action.0.code', 'notInList');
        $this->bulk([$small], 'delete', ['protocol' => 'pop3'])->assertStatus(422)->assertJsonPath('errors.protocol.0.code', 'immutable');
        $this->bulk([])->assertStatus(422);
        $this->assertNotNull(DB::table('templates')->where('id', $small)->first());
    }

    public function test_domain_administrators_mailbox_users_and_read_only_keys_are_refused(): void
    {
        $small = $this->mailcow()->mailboxTemplate('Small');

        $this->asDomainAdmin(['example.org']);
        $this->bulk([$small])->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
        $records = $this->auditOf('mailboxTemplateDeletion');
        $this->assertCount(1, $records);
        $this->assertSame(['failure', null, [(string) $small], 'delete', 'permissionDenied'], [$records[0]->outcome, $records[0]->target_type, $records[0]->details['targetIds'], $records[0]->details['bulkAction'], $records[0]->failure_reason]);

        $this->forgetCookies();
        $this->asMailboxUser();
        $this->bulk([$small])->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->postJson('/api/v2/mailbox-templates/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $small]]], $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->postJson('/api/v2/mailbox-templates/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $small]]], $this->keyHeader($this->apiKey('admin', 'readWrite')))
            ->assertOk()->assertJsonPath('data.summary.done', 1);
    }
}
