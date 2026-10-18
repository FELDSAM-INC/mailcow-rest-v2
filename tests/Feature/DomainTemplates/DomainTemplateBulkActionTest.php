<?php

namespace Tests\Feature\DomainTemplates;

use Database\Factories\MailcowRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Gap G40 (added with feature 003): legacy deletes several domain templates at once
 * (`delete_selected` on `tab-templates-domains.twig`). Each target is deleted, checked and audited
 * like the single deletion, with one result per distinct template. Unlike legacy, whose loop
 * returns after the first template, every target is processed.
 */
class DomainTemplateBulkActionTest extends TestCase
{
    use ApiKeys;
    use DomainTestHelpers;

    /**
     * @param  list<array<string, string>|string|int>  $targets
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function bulk(array $targets, string $action = 'delete', array $extra = []): TestResponse
    {
        $body = ['action' => $action, 'targets' => array_map(fn (mixed $t): array => is_array($t) ? $t : ['id' => (string) $t], $targets)] + $extra;

        return $this->postJson('/api/v2/domain-templates/bulk-actions', $body, $this->csrf());
    }

    public function test_every_selected_template_is_deleted_with_one_record_each(): void
    {
        $default = $this->mailcow()->defaultDomainTemplate();
        $ids = [
            $this->mailcow()->domainTemplate('Alpha', MailcowRows::DEFAULT_DOMAIN_TEMPLATE),
            $this->mailcow()->domainTemplate('Beta', MailcowRows::DEFAULT_DOMAIN_TEMPLATE),
            $this->mailcow()->domainTemplate('Gamma', MailcowRows::DEFAULT_DOMAIN_TEMPLATE),
        ];
        $this->asAdmin();

        $data = $this->bulk($ids)->assertOk()->json('data');

        $this->assertSame(['total' => 3, 'done' => 3, 'notFound' => 0, 'refused' => 0, 'outdated' => 0, 'failed' => 0], $data['summary']);
        $this->assertSame(array_map('strval', $ids), array_column($data['results'], 'id'));
        $this->assertSame([$default], DB::table('templates')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(), 'Every target is deleted, not only the first.');

        $records = $this->auditOf('domainTemplateDeletion');
        $this->assertSame(array_map('strval', $ids), array_column($records, 'target_id'));
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], array_column($records, 'target_name'));
        $this->assertSame(['success'], array_values(array_unique(array_column($records, 'outcome'))));
        $this->assertSame([$data['bulkRequestId']], array_values(array_unique(array_map(fn (object $r): string => $r->details['bulkRequestId'], $records))));
        $this->assertIsArray($records[0]->details['values']);
    }

    public function test_the_default_template_is_refused_and_the_others_proceed(): void
    {
        $default = $this->mailcow()->defaultDomainTemplate();
        $alpha = $this->mailcow()->domainTemplate('Alpha', MailcowRows::DEFAULT_DOMAIN_TEMPLATE);
        $this->asAdmin();

        $data = $this->bulk([$default, $alpha])->assertOk()->json('data');

        $this->assertSame(['refused', 'done'], array_column($data['results'], 'outcome'));
        $this->assertSame('/api/v2/problems/domain-template-protected', $data['results'][0]['problem']['type']);
        $this->assertSame('Default', DB::table('templates')->where('id', $default)->value('template'));

        $failure = array_values(array_filter($this->auditOf('domainTemplateDeletion'), fn (object $r): bool => $r->outcome === 'failure'))[0];
        $this->assertSame(
            ['domainTemplate', (string) $default, 'Default', 'conflict', $data['bulkRequestId']],
            [$failure->target_type, $failure->target_id, $failure->target_name, $failure->failure_reason, $failure->details['bulkRequestId']],
        );
    }

    public function test_unknown_ids_mailbox_templates_and_repeated_ids(): void
    {
        $alpha = $this->mailcow()->domainTemplate('Alpha', MailcowRows::DEFAULT_DOMAIN_TEMPLATE);
        $mailboxTemplate = $this->mailcow()->mailboxTemplate('Small');
        $this->asAdmin();

        $data = $this->bulk([$alpha, '0'.$alpha, 999999, $mailboxTemplate, 'abc'])->assertOk()->json('data');

        $this->assertSame(
            [[(string) $alpha, 'done'], ['999999', 'notFound'], [(string) $mailboxTemplate, 'notFound'], ['abc', 'notFound']],
            array_map(fn (array $r): array => [$r['id'], $r['outcome']], $data['results']),
        );
        $this->assertNotNull(DB::table('templates')->where('id', $mailboxTemplate)->first(), 'A mailbox template is never deleted here.');
        $this->assertSame(['success', 'failure', 'failure', 'failure'], array_column($this->auditOf('domainTemplateDeletion'), 'outcome'), 'One record per distinct target.');
    }

    public function test_outdated_versions_affect_only_their_item(): void
    {
        $ids = [
            $this->mailcow()->domainTemplate('One', MailcowRows::DEFAULT_DOMAIN_TEMPLATE),
            $this->mailcow()->domainTemplate('Two', MailcowRows::DEFAULT_DOMAIN_TEMPLATE),
        ];
        $this->asAdmin();
        $tags = [];

        foreach ((array) $this->getJson('/api/v2/domain-templates')->json('data') as $entry) {
            $tags[$entry['id']] = $entry['etag'];
        }

        DB::table('templates')->where('id', $ids[0])->update(['template' => 'One (changed in the legacy UI)']);

        $data = $this->bulk([
            ['id' => (string) $ids[0], 'version' => $tags[$ids[0]]],
            ['id' => (string) $ids[1], 'version' => trim($tags[$ids[1]], '"')],
        ])->assertOk()->json('data');

        $this->assertSame(['outdated', 'done'], array_column($data['results'], 'outcome'));
        $this->assertSame(412, $data['results'][0]['problem']['status']);
        $this->assertNotNull(DB::table('templates')->where('id', $ids[0])->first());
    }

    public function test_invalid_requests_are_refused_as_a_whole(): void
    {
        $this->withoutRequestValidation();
        $alpha = $this->mailcow()->domainTemplate('Alpha', MailcowRows::DEFAULT_DOMAIN_TEMPLATE);
        $this->asAdmin();

        $this->bulk(range(1, 101))->assertStatus(422)
            ->assertJsonPath('errors.targets.0.code', 'tooMany')
            ->assertJsonPath('errors.targets.0.params.max', 100);
        $this->bulk([$alpha], 'deactivate')->assertStatus(422)->assertJsonPath('errors.action.0.code', 'notInList');
        $this->bulk([$alpha], 'delete', ['force' => true])->assertStatus(422)->assertJsonPath('errors.force.0.code', 'immutable');
        $this->assertNotNull(DB::table('templates')->where('id', $alpha)->first());
    }

    public function test_domain_administrators_and_read_only_keys_are_refused(): void
    {
        $alpha = $this->mailcow()->domainTemplate('Alpha', MailcowRows::DEFAULT_DOMAIN_TEMPLATE);

        $this->asDomainAdmin(['example.org']);
        $this->bulk([$alpha])->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
        $records = $this->auditOf('domainTemplateDeletion');
        $this->assertCount(1, $records);
        $this->assertSame(['failure', null, [(string) $alpha], 'delete', 'permissionDenied'], [$records[0]->outcome, $records[0]->target_type, $records[0]->details['targetIds'], $records[0]->details['bulkAction'], $records[0]->failure_reason]);

        $this->forgetCookies();
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->postJson('/api/v2/domain-templates/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $alpha]]], $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->assertNotNull(DB::table('templates')->where('id', $alpha)->first());
    }
}
