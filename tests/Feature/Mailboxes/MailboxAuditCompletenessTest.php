<?php

namespace Tests\Feature\Mailboxes;

use Illuminate\Support\Facades\DB;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T089 (SC-010): one successful and one refused request of every state-changing
 * operation, and a bulk action with three targets, write exactly one audit record per affected
 * mailbox or template.
 */
class MailboxAuditCompletenessTest extends TestCase
{
    use MailboxTestHelpers;

    public function test_exactly_one_record_per_object_and_attempt(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $count = fn (): int => DB::table('restv2_audit_events')->count();
        $expect = function (callable $request, string $event, string $outcome) use ($count): void {
            $before = $count();
            $request();
            $this->assertSame($before + 1, $count(), $event.' '.$outcome);
            $last = DB::table('restv2_audit_events')->orderByDesc('occurred_at')->orderByDesc('id')->first();
            $this->assertSame([$event, $outcome], [$last?->event_type, $last?->outcome]);
        };

        $expect(fn () => $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x'])->assertCreated(), 'mailboxCreation', 'success');
        $expect(fn () => $this->createMailbox(['localPart' => 'anna', 'domain' => 'example.org', 'password' => '{PLAIN}x'])->assertStatus(409), 'mailboxCreation', 'failure');
        $expect(fn () => $this->patchMailbox('anna@example.org', ['displayName' => 'Anna'])->assertOk(), 'mailboxUpdate', 'success');
        $expect(fn () => $this->patchMailbox('anna@example.org', ['recoveryEmail' => 'not an address'])->assertStatus(422), 'mailboxUpdate', 'failure');
        $expect(fn () => $this->postJson('/api/v2/mailboxes/anna@example.org/rename', ['newLocalPart' => 'anna2', 'keepOldAddressAsAlias' => false], $this->csrf())->assertOk(), 'mailboxRename', 'success');
        $expect(fn () => $this->postJson('/api/v2/mailboxes/anna2@example.org/rename', ['newLocalPart' => 'anna2', 'keepOldAddressAsAlias' => false], $this->csrf())->assertStatus(422), 'mailboxRename', 'failure');
        $expect(fn () => $this->deleteMailbox('anna2@example.org')->assertNoContent(), 'mailboxDeletion', 'success');
        $expect(fn () => $this->deleteMailbox('anna2@example.org')->assertNotFound(), 'mailboxDeletion', 'failure');

        $id = 0;
        $expect(function () use (&$id): void {
            $id = (int) $this->postJson('/api/v2/mailbox-templates', ['name' => 'Staff'], $this->csrf())->assertCreated()->json('data.id');
        }, 'mailboxTemplateCreation', 'success');
        $expect(fn () => $this->postJson('/api/v2/mailbox-templates', ['name' => 'STAFF'], $this->csrf())->assertStatus(409), 'mailboxTemplateCreation', 'failure');
        $expect(fn () => $this->patchMerge('/api/v2/mailbox-templates/'.$id, ['quota' => 1048576], $this->csrf())->assertOk(), 'mailboxTemplateUpdate', 'success');
        $expect(fn () => $this->patchMerge('/api/v2/mailbox-templates/'.$id, ['quota' => 1048576], $this->csrf() + ['If-Match' => '"stale"'])->assertStatus(412), 'mailboxTemplateUpdate', 'failure');
        $expect(fn () => $this->deleteJson('/api/v2/mailbox-templates/'.$id, [], $this->csrf())->assertNoContent(), 'mailboxTemplateDeletion', 'success');
        $expect(fn () => $this->deleteJson('/api/v2/mailbox-templates/'.$id, [], $this->csrf())->assertNotFound(), 'mailboxTemplateDeletion', 'failure');

        foreach (['a', 'b', 'c'] as $local) {
            $this->mailcow()->legacyMailbox("{$local}@example.org");
        }

        $before = $count();
        $this->postJson('/api/v2/mailboxes/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => 'a@example.org'], ['id' => 'b@example.org'], ['id' => 'c@example.org']]], $this->csrf())->assertOk();
        $this->assertSame($before + 3, $count());
        $this->assertSame(3, DB::table('restv2_audit_events')->where('event_type', 'mailboxUpdate')->whereIn('target_id', ['a@example.org', 'b@example.org', 'c@example.org'])->count());
    }
}
