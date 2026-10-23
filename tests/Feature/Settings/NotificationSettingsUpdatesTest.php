<?php

namespace Tests\Feature\Settings;

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/** The two string-backed notification resources retain their distinct defaults and audit policy. */
class NotificationSettingsUpdatesTest extends TestCase
{
    use SettingsTestHelpers;

    /** @return array<string, array{string, string, string, array<string, string>}> */
    public static function notifications(): array
    {
        return [
            'password reset' => [
                '/api/v2/settings/password-reset-notification', 'passwordResetNotificationUpdate', 'passwordResetNotification',
                ['sender' => 'PW_RESET_FROM', 'subject' => 'PW_RESET_SUBJ', 'textTemplate' => 'PW_RESET_TEXT', 'htmlTemplate' => 'PW_RESET_HTML'],
            ],
            'quota' => [
                '/api/v2/settings/quota-notification', 'quotaNotificationUpdate', 'quotaNotification',
                ['sender' => 'QW_SENDER', 'subject' => 'QW_SUBJ', 'htmlTemplate' => 'QW_HTML'],
            ],
        ];
    }

    /** @param array<string, string> $members */
    #[DataProvider('notifications')]
    public function test_empty_missing_and_unchanged_values_keep_the_tag_and_do_not_audit(string $uri, string $event, string $target, array $members): void
    {
        $this->asAdmin();
        $initialTag = $this->getJson($uri)->assertOk()->json('data.etag');

        $this->send('PATCH', $uri, ['subject' => ''])->assertOk()->assertJsonPath('data.etag', $initialTag);
        $this->assertNull($this->sharedGet($members['subject']), 'An empty value is equivalent to an absent key for change detection.');

        $this->sharedValue($members['subject'], 'Already stored');
        $this->sharedValue($members['htmlTemplate'], '<p>Already stored</p>');
        $storedTag = $this->getJson($uri)->assertOk()->json('data.etag');
        $this->send('PATCH', $uri, ['subject' => 'Already stored'])->assertOk()->assertJsonPath('data.etag', $storedTag);

        $this->assertSame('<p>Already stored</p>', $this->sharedGet($members['htmlTemplate']));
        $this->assertSame([], $this->successesOf($event));
    }

    /** @param array<string, string> $members */
    #[DataProvider('notifications')]
    public function test_explicit_empty_values_are_written_and_audited_without_old_template_contents(string $uri, string $event, string $target, array $members): void
    {
        $this->asAdmin();
        $oldTemplate = '<p>Private café template</p>';
        $oldValues = ['sender' => 'notification@example.org', 'subject' => 'Custom subject', 'textTemplate' => $oldTemplate, 'htmlTemplate' => $oldTemplate];

        foreach ($members as $member => $key) {
            $this->sharedValue($key, $oldValues[$member]);
        }

        $beforeTag = $this->getJson($uri)->assertOk()->json('data.etag');
        $response = $this->send('PATCH', $uri, array_fill_keys(array_keys($members), ''))->assertOk();
        $this->assertNotSame($beforeTag, $response->json('data.etag'));

        foreach ($members as $key) {
            $this->assertSame('', $this->sharedGet($key), 'Clearing a stored value writes an empty string.');
        }

        if ($target === 'passwordResetNotification') {
            $response->assertJsonPath('data.passwordResetAvailable', false)->assertJsonPath('data.templatesAreDefault', true);
        } else {
            $response->assertJsonPath('data.sender', 'quota-warning@localhost')
                ->assertJsonPath('data.senderIsDefault', true)->assertJsonPath('data.subjectIsDefault', true)->assertJsonPath('data.htmlTemplateIsDefault', true);
        }

        $records = $this->successesOf($event);
        $this->assertCount(1, $records);
        $this->assertSame($target, $records[0]->target_type);
        $changes = $records[0]->details['changes'];
        $this->assertSame(['old' => 'notification@example.org', 'new' => ''], $changes['sender']);
        $this->assertSame(['old' => 'Custom subject', 'new' => ''], $changes['subject']);

        foreach (array_intersect(array_keys($members), ['textTemplate', 'htmlTemplate']) as $member) {
            $this->assertSame(['changed' => true, 'oldBytes' => strlen($oldTemplate), 'newBytes' => 0], $changes[$member]);
        }

        $this->assertStringNotContainsString('Private', json_encode($records, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, string> $members */
    #[DataProvider('notifications')]
    public function test_commit_failure_restores_values_and_removes_previously_absent_keys(string $uri, string $event, string $target, array $members): void
    {
        $this->asAdmin();
        $this->sharedValue($members['sender'], 'before@example.org');
        $this->sharedValue($members['htmlTemplate'], '');
        $before = $this->getJson($uri)->assertOk()->json('data');
        $headers = $this->csrf();
        $patch = array_fill_keys(array_keys($members), 'New template');
        $patch['sender'] = '';
        $seenAtCommit = null;
        Event::listen(TransactionCommitted::class, function () use (&$seenAtCommit, $members): void {
            if ($seenAtCommit === null) {
                $seenAtCommit = array_map(fn (string $key): ?string => $this->sharedGet($key), $members);

                throw new RuntimeException('Synthetic settings audit commit failure');
            }
        });

        $this->send('PATCH', $uri, $patch, $headers)->assertStatus(500);

        $this->assertSame($patch, $seenAtCommit, 'The failure occurs after Redis EXEC, exercising compensation.');
        $this->assertSame('before@example.org', $this->sharedGet($members['sender']));
        $this->assertSame('', $this->sharedGet($members['htmlTemplate']));
        $this->assertNull($this->sharedGet($members['subject']));

        if (isset($members['textTemplate'])) {
            $this->assertNull($this->sharedGet($members['textTemplate']));
        }

        $this->assertSame($before, $this->getJson($uri)->assertOk()->json('data'));
    }

    /** @param array<string, string> $members */
    #[DataProvider('notifications')]
    public function test_a_legacy_write_makes_the_version_stale_without_overwriting_or_auditing_success(string $uri, string $event, string $target, array $members): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson($uri)->assertOk()->json('data.etag');
        $this->sharedValue($members['subject'], 'Updated by legacy');

        $this->send('PATCH', $uri, ['sender' => 'mine@example.org', 'htmlTemplate' => '<p>Mine</p>'], ['If-Match' => $etag])->assertStatus(412);

        $this->assertSame('Updated by legacy', $this->sharedGet($members['subject']));
        $this->assertNull($this->sharedGet($members['sender']));
        $this->assertNull($this->sharedGet($members['htmlTemplate']));
        $this->assertSame([], $this->successesOf($event));
    }
}
