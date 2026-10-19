<?php

namespace Tests\Feature\Quarantine;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T052 (FR-029 to FR-034, FR-059 to FR-061, AS-3.1 to AS-3.7): administrators read and
 * change the quarantine settings in mailcow's `Q_*` keys, in legacy's formats; invalid values are
 * refused with field errors and nothing changes; other roles read the public subset.
 */
class QuarantineSettingsTest extends TestCase
{
    use QuarantineTestHelpers;

    private const KEYS = ['Q_RETENTION_SIZE', 'Q_MAX_SIZE', 'Q_MAX_SCORE', 'Q_MAX_AGE', 'Q_EXCLUDE_DOMAINS', 'Q_RELEASE_FORMAT', 'Q_SENDER', 'Q_SUBJ', 'Q_BCC', 'Q_REDIRECT', 'Q_HTML'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('example.net');
        $this->mailcow()->aliasDomain('example.info', 'example.org');
    }

    /**
     * @return array<string, string|null>
     */
    private function stored(): array
    {
        $values = [];

        foreach (self::KEYS as $key) {
            $value = $this->redis()->get($key);
            $values[$key] = is_string($value) ? $value : null;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $patch
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function patchSettings(array $patch, array $headers = [], string $query = ''): TestResponse
    {
        return $this->send('PATCH', '/api/v2/quarantine/settings'.$query, $patch, $headers);
    }

    public function test_a_fresh_server_reports_the_legacy_defaults(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/quarantine/settings')->assertOk();
        $data = (array) $response->json('data');

        $this->assertSame('full', $data['visibility']);
        $this->assertFalse($data['active']);
        $this->assertSame(0, $data['retentionPerMailbox']);
        $this->assertSame(0, $data['maxMessageSize']);
        $this->assertNull($data['notificationScoreLimit']);
        $this->assertSame(365, $data['maxAgeDays']);
        $this->assertSame([], $data['excludedDomains']);
        $this->assertSame([], $data['missingExcludedDomains']);
        $this->assertSame('original', $data['releaseFormat']);
        $this->assertSame('quarantine@localhost', $data['notificationSender']);
        $this->assertTrue($data['notificationSenderIsDefault']);
        $this->assertSame('Spam Quarantine Notification', $data['notificationSubject']);
        $this->assertTrue($data['notificationSubjectIsDefault']);
        $this->assertNull($data['copyRecipient']);
        $this->assertNull($data['redirectRecipient']);
        $this->assertSame(file_get_contents(base_path('resources/legacy/quarantine.tpl')), $data['notificationTemplate']);
        $this->assertTrue($data['notificationTemplateIsDefault']);
        $response->assertHeader('ETag', $data['etag']);
    }

    public function test_legacy_written_values_are_read_back_converted(): void
    {
        $this->asAdmin();
        $this->sharedValue('Q_MAX_SIZE', '15');
        $this->sharedValue('Q_RETENTION_SIZE', '20');
        $this->sharedValue('Q_RELEASE_FORMAT', 'raw');
        $this->sharedValue('Q_EXCLUDE_DOMAINS', '["example.net"]');
        $this->sharedValue('Q_HTML', '<p>{{ username }}</p>');
        $this->sharedValue('Q_MAX_SCORE', '9.5');
        $this->sharedValue('Q_MAX_AGE', '0');

        $data = (array) $this->getJson('/api/v2/quarantine/settings')->assertOk()->json('data');

        $this->assertTrue($data['active']);
        $this->assertSame(15728640, $data['maxMessageSize']);
        $this->assertSame('original', $data['releaseFormat']);
        $this->assertSame(['example.net'], $data['excludedDomains']);
        $this->assertSame('<p>{{ username }}</p>', $data['notificationTemplate']);
        $this->assertFalse($data['notificationTemplateIsDefault']);
        $this->assertSame(9.5, $data['notificationScoreLimit']);
        $this->assertSame(365, $data['maxAgeDays']);
    }

    public function test_a_change_writes_only_the_changed_keys_in_legacy_formats(): void
    {
        $this->asAdmin();

        $this->patchSettings(['retentionPerMailbox' => 20, 'maxMessageSize' => 15728640])->assertOk()->assertJsonPath('data.active', true);

        $this->assertSame(['Q_RETENTION_SIZE' => '20', 'Q_MAX_SIZE' => '15'] + array_fill_keys(array_diff(self::KEYS, ['Q_RETENTION_SIZE', 'Q_MAX_SIZE']), null), array_merge(array_fill_keys(self::KEYS, null), $this->stored()));
    }

    public function test_every_setting_is_stored_as_legacy_stores_it(): void
    {
        $this->asAdmin();

        $this->patchSettings([
            'notificationScoreLimit' => 12.5,
            'maxAgeDays' => 30,
            'excludedDomains' => ['example.net', 'EXAMPLE.info', 'example.net'],
            'releaseFormat' => 'attachment',
            'notificationSender' => 'quarantine@example.org',
            'notificationSubject' => 'Your quarantine',
            'copyRecipient' => 'audit@example.org',
            'redirectRecipient' => 'postmaster@example.org',
        ])->assertOk();

        $stored = $this->stored();
        $this->assertSame('12.5', $stored['Q_MAX_SCORE']);
        $this->assertSame('30', $stored['Q_MAX_AGE']);
        $this->assertSame(['example.net', 'example.info'], json_decode((string) $stored['Q_EXCLUDE_DOMAINS'], true));
        $this->assertSame('attachment', $stored['Q_RELEASE_FORMAT']);
        $this->assertSame('quarantine@example.org', $stored['Q_SENDER']);
        $this->assertSame('Your quarantine', $stored['Q_SUBJ']);
        $this->assertSame('audit@example.org', $stored['Q_BCC']);
        $this->assertSame('postmaster@example.org', $stored['Q_REDIRECT']);

        $this->patchSettings(['releaseFormat' => 'original', 'notificationSender' => null, 'notificationSubject' => '', 'copyRecipient' => null, 'redirectRecipient' => '', 'notificationScoreLimit' => null])->assertOk();

        $stored = $this->stored();
        $this->assertSame('raw', $stored['Q_RELEASE_FORMAT']);
        $this->assertSame('', $stored['Q_SENDER']);
        $this->assertSame('', $stored['Q_SUBJ']);
        $this->assertSame('', $stored['Q_BCC']);
        $this->assertSame('', $stored['Q_REDIRECT']);
        $this->assertSame('', $stored['Q_MAX_SCORE']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string, array<string, mixed>}>
     */
    public static function invalidValues(): array
    {
        return [
            'size not whole MiB' => [['maxMessageSize' => 1000], 'maxMessageSize', 'notMultipleOf', ['factor' => 1048576]],
            'age zero' => [['maxAgeDays' => 0], 'maxAgeDays', 'outOfRange', []],
            'copy not an address' => [['copyRecipient' => 'not-an-address'], 'copyRecipient', 'invalidFormat', ['format' => 'email']],
            'subject too long' => [['notificationSubject' => str_repeat('s', 256)], 'notificationSubject', 'tooLong', ['max' => 255]],
            'template too large' => [['notificationTemplate' => str_repeat('x', 1048577)], 'notificationTemplate', 'tooLong', ['max' => 1048576, 'unit' => 'bytes']],
            'unknown domain' => [['excludedDomains' => ['unknown.example']], 'excludedDomains.0', 'notFound', []],
            'raw format' => [['releaseFormat' => 'raw'], 'releaseFormat', 'notInList', []],
            'negative retention' => [['retentionPerMailbox' => -1], 'retentionPerMailbox', 'outOfRange', []],
        ];
    }

    /**
     * @param  array<string, mixed>  $patch
     * @param  array<string, mixed>  $params
     */
    #[DataProvider('invalidValues')]
    public function test_invalid_values_are_refused_and_nothing_changes(array $patch, string $field, string $code, array $params): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();
        $this->sharedValue('Q_MAX_AGE', '30');
        $before = $this->stored();

        $response = $this->patchSettings($patch + ['retentionPerMailbox' => 5] + [])->assertStatus(422);

        $errors = (array) $response->json('errors');
        $this->assertSame($code, $errors[$field][0]['code'] ?? null, (string) $response->getContent());
        foreach ($params as $name => $value) {
            $this->assertSame($value, $errors[$field][0]['params'][$name] ?? null);
        }
        $this->assertSame($before, $this->stored());
    }

    public function test_an_invalid_empty_body_is_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->patchSettings([])->assertStatus(422)->assertJsonPath('errors.body.0.code', 'required');
    }

    public function test_excluded_domains_are_normalised_and_a_deleted_one_may_be_kept(): void
    {
        $this->asAdmin();
        $this->mailcow()->domain('xn--xample-9ua.org');

        $this->patchSettings(['excludedDomains' => ['  ÉXAMPLE.org ', 'xn--xample-9ua.org']])->assertOk()->assertJsonPath('data.excludedDomains', ['xn--xample-9ua.org']);
        $this->assertSame('["xn--xample-9ua.org"]', $this->redis()->get('Q_EXCLUDE_DOMAINS'));

        $this->sharedValue('Q_EXCLUDE_DOMAINS', '["gone.example","example.net"]');
        $this->getJson('/api/v2/quarantine/settings')->assertOk()->assertJsonPath('data.missingExcludedDomains', ['gone.example']);
        $this->patchSettings(['excludedDomains' => ['gone.example', 'example.net', 'example.org']])->assertOk();
        $this->assertSame(['gone.example', 'example.net', 'example.org'], json_decode((string) $this->redis()->get('Q_EXCLUDE_DOMAINS'), true));
    }

    public function test_a_deleted_domain_cannot_be_added_again(): void
    {
        $this->asAdmin();

        $errors = (array) $this->patchSettings(['excludedDomains' => ['gone.example']])->assertStatus(422)->json('errors');
        $this->assertSame('notFound', $errors['excludedDomains.0'][0]['code']);
    }

    public function test_an_empty_template_restores_the_default(): void
    {
        $this->asAdmin();
        $this->sharedValue('Q_HTML', '<p>custom {{ username }}</p>');

        $this->patchSettings(['notificationTemplate' => ''])->assertOk()->assertJsonPath('data.notificationTemplateIsDefault', true);

        $this->assertSame('', $this->redis()->get('Q_HTML'));
    }

    public function test_if_match_protects_against_lost_updates(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/quarantine/settings')->json('data.etag');
        $this->sharedValue('Q_SUBJ', 'Changed in the legacy UI');

        $this->assertNotSame($etag, $this->getJson('/api/v2/quarantine/settings')->json('data.etag'));
        $this->patchSettings(['maxAgeDays' => 30], ['If-Match' => $etag])->assertStatus(412);
        $this->assertNull($this->stored()['Q_MAX_AGE']);

        $fresh = (string) $this->getJson('/api/v2/quarantine/settings')->json('data.etag');
        $this->patchSettings(['maxAgeDays' => 30], ['If-Match' => $fresh])->assertOk();
    }

    public function test_an_unavailable_shared_store_answers_a_temporary_failure(): void
    {
        $this->asAdmin();
        $this->breakSharedStore();

        $this->getJson('/api/v2/quarantine/settings')->assertStatus(503)->assertJsonPath('dependency', 'sharedStore');
        $this->patchSettings(['maxAgeDays' => 30])->assertStatus(503)->assertJsonPath('dependency', 'sharedStore');
    }

    public function test_other_roles_read_the_public_subset_and_may_not_change(): void
    {
        $this->sharedValue('Q_RETENTION_SIZE', '20');
        $this->sharedValue('Q_MAX_SIZE', '15');
        $this->sharedValue('Q_SENDER', 'secret@example.org');
        $this->mailcow()->domainAdmin('reseller', ['example.org'], scheme: 'SSHA256');
        $this->quarantineMailbox('anna@example.org');

        foreach ([['reseller', Role::DomainAdmin], ['anna@example.org', Role::MailboxUser]] as [$username, $role]) {
            $this->forgetCookies();
            $this->signInAs($username, $role);
            $data = (array) $this->getJson('/api/v2/quarantine/settings')->assertOk()->json('data');
            $this->assertSame(['visibility', 'active', 'retentionPerMailbox', 'maxMessageSize', 'etag'], array_keys($data));
            $this->assertSame('public', $data['visibility']);
            $this->assertTrue($data['active']);
            $this->assertSame(15728640, $data['maxMessageSize']);
            $this->patchSettings(['maxAgeDays' => 30])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        }

        $this->forgetCookies();
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $readOnly = $this->keyHeader($this->apiKey('admin', 'readOnly'));
        $this->getJson('/api/v2/quarantine/settings', $readOnly)->assertOk()->assertJsonPath('data.visibility', 'full');
        $this->patchSettings(['maxAgeDays' => 30], $readOnly)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
    }

    public function test_one_audit_record_with_old_and_new_values_and_the_template_as_sizes(): void
    {
        $this->asAdmin();
        $this->sharedValue('Q_HTML', '<p>old {{ username }}</p>');

        $this->patchSettings(['maxAgeDays' => 30, 'notificationTemplate' => '<p>new template {{ username }}</p>'])->assertOk();

        $records = DB::table('restv2_audit_events')->where('event_type', 'quarantineSettingsUpdate')->get();
        $this->assertCount(1, $records);
        $this->assertSame('quarantineSettings', $records[0]->target_type);
        $details = json_decode((string) $records[0]->details, true);
        $this->assertSame(['old' => 365, 'new' => 30], $details['changes']['maxAgeDays']);
        $this->assertSame(['changed' => true, 'oldSize' => strlen('<p>old {{ username }}</p>'), 'newSize' => strlen('<p>new template {{ username }}</p>')], $details['changes']['notificationTemplate']);
        $this->assertStringNotContainsString('new template', (string) $records[0]->details);
    }
}
