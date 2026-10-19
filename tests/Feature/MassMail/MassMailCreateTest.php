<?php

namespace Tests\Feature\MassMail;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\MassMail\MassMailTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T067 (FR-074, FR-075, AS-6.2 to AS-6.4, AS-6.7): a signed-in administrator sends mass
 * mail by creating a job with the recipient count of the preview; a different count, a running job,
 * an empty selection or an API key refuse it, and nothing is sent.
 */
class MassMailCreateTest extends TestCase
{
    use MassMailTestHelpers;

    private const URI = '/api/v2/mass-mail-jobs';

    protected function setUp(): void
    {
        parent::setUp();
        $this->receivingMailboxes(['example.org' => 60, 'example.net' => 61]);
        $this->extraMailbox('inactive@example.org', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function body(array $overrides = []): array
    {
        return $overrides + [
            'sender' => 'noreply@mail.example.org',
            'subject' => 'Maintenance tonight',
            'textBody' => "Dear user,\nthe server is updated tonight.\n",
            'htmlBody' => '<p>Dear user,</p>',
            'selection' => (object) [],
            'expectedRecipients' => 121,
        ];
    }

    public function test_a_job_is_created_with_its_recipients_pending(): void
    {
        $this->asAdmin();

        $response = $this->send('POST', self::URI, self::body())->assertCreated();

        $id = (string) $response->json('data.id');
        $response->assertHeader('Location', self::URI.'/'.$id)
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.recipientCount', 121)
            ->assertJsonPath('data.counts', ['pending' => 121, 'delivered' => 0, 'failed' => 0, 'skipped' => 0])
            ->assertJsonPath('data.createdBy', ['username' => 'admin'])
            ->assertJsonPath('data.selection', ['domains' => [], 'include' => [], 'exclude' => []])
            ->assertJsonPath('data.startedAt', null)
            ->assertJsonPath('data.cancelledBy', null);
        $this->assertSame(121, DB::table('restv2_mass_mail_recipients')->where('job_id', $id)->where('outcome', 'pending')->count());
        $this->assertFalse(DB::table('restv2_mass_mail_recipients')->where('address', 'inactive@example.org')->exists());
        $this->lmtp()->assertNothingDelivered();

        $record = $this->successesOf('massMailJobCreation');
        $this->assertCount(1, $record);
        $this->assertSame(['massMailJob', $id, 'Maintenance tonight'], [$record[0]->target_type, $record[0]->target_id, $record[0]->target_name]);
        $this->assertSame(['sender' => 'noreply@mail.example.org', 'subject' => 'Maintenance tonight', 'selection' => ['domains' => [], 'domainCount' => 0, 'includeCount' => 0, 'excludeCount' => 0], 'recipientCount' => 121], $record[0]->details);
    }

    public function test_the_selection_is_stored_normalised(): void
    {
        $this->asAdmin();

        $this->send('POST', self::URI, self::body(['selection' => ['domains' => ['EXAMPLE.net'], 'exclude' => ['User001@example.net', 'user001@example.net']], 'expectedRecipients' => 60]))->assertCreated()
            ->assertJsonPath('data.selection', ['domains' => ['example.net'], 'include' => [], 'exclude' => ['user001@example.net']]);
    }

    public function test_a_changed_recipient_count_refuses_the_job(): void
    {
        $this->asAdmin();
        $this->extraMailbox('new@example.org');

        $this->send('POST', self::URI, self::body())->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/mass-mail-recipient-count-changed')
            ->assertJsonPath('actualRecipients', 122);

        $this->assertSame(0, DB::table('restv2_mass_mail_jobs')->count());
        $this->assertSame(0, DB::table('restv2_mass_mail_recipients')->count());
        $this->assertSame(['conflict'], array_column($this->refusalsOf('massMailJobCreation'), 'failure_reason'));
    }

    public function test_a_second_job_while_one_runs_is_refused(): void
    {
        $this->asAdmin();
        $running = $this->runningJob(['someone@example.org']);

        $this->send('POST', self::URI, self::body())->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/mass-mail-already-running')
            ->assertJsonPath('runningJobId', $running);

        $this->assertSame(1, DB::table('restv2_mass_mail_jobs')->count());
    }

    public function test_the_database_allows_one_running_job_only(): void
    {
        $this->runningJob(['a@example.org']);

        $this->expectException(QueryException::class);

        $this->runningJob(['b@example.org']);
    }

    public function test_an_empty_selection_is_too_few(): void
    {
        $this->asAdmin();

        DB::table('mailbox')->where('domain', 'example.org')->update(['active' => 0]);

        $errors = (array) $this->send('POST', self::URI, self::body(['selection' => ['domains' => ['example.org']], 'expectedRecipients' => 1]))->assertStatus(422)->json('errors');

        $this->assertSame(['tooFew', ['min' => 1]], [$errors['selection'][0]['code'], $errors['selection'][0]['params']]);
    }

    public function test_invalid_fields_are_refused(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();

        $errors = (array) $this->send('POST', self::URI, self::body(['sender' => 'no-reply@', 'subject' => '', 'textBody' => '', 'htmlBody' => str_repeat('x', 1048577), 'expectedRecipients' => 0]))->assertStatus(422)->json('errors');

        $this->assertSame('invalidFormat', $errors['sender'][0]['code']);
        $this->assertSame('required', $errors['subject'][0]['code']);
        $this->assertSame('required', $errors['textBody'][0]['code']);
        $this->assertSame(['max' => 1048576, 'unit' => 'bytes'], $errors['htmlBody'][0]['params']);
        $this->assertSame('outOfRange', $errors['expectedRecipients'][0]['code']);
        $this->send('POST', self::URI, self::body(['subject' => "two\nlines"]))->assertStatus(422);
        $this->send('POST', self::URI, self::body(['textBody' => str_repeat('x', 1048577)]))->assertStatus(422);
        $this->assertSame(0, DB::table('restv2_mass_mail_jobs')->count());
    }

    public function test_an_api_key_is_refused_with_session_required(): void
    {
        $this->send('POST', self::URI, self::body(), $this->adminKey())->assertForbidden()->assertJsonPath('type', '/api/v2/problems/session-required');
        $this->send('POST', self::URI, self::body(), $this->adminKey('readOnly'))->assertForbidden();

        $this->assertSame(0, DB::table('restv2_mass_mail_jobs')->count());
    }

    public function test_the_creation_audit_summarises_a_long_selection(): void
    {
        $this->asAdmin();
        $domains = [];

        for ($i = 1; $i <= 22; $i++) {
            $this->receivingMailboxes(["d{$i}.example" => 1]);
            $domains[] = "d{$i}.example";
        }

        $this->send('POST', self::URI, self::body(['selection' => ['domains' => $domains], 'expectedRecipients' => 22]))->assertCreated();

        $selection = $this->successesOf('massMailJobCreation')[0]->details['selection'];
        $this->assertCount(20, $selection['domains']);
        $this->assertSame(22, $selection['domainCount']);
    }
}
