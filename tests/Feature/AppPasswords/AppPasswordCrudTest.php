<?php

namespace Tests\Feature\AppPasswords;

use App\Support\Hashing\LegacyPasswordVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T063 (feature 005 FR-037–FR-041, US4 AS-1, AS-3, AS-5, AS-6): the app passwords of a mailbox,
 * stored as legacy app_passwd() stores them, without ever returning a password or its hash.
 */
class AppPasswordCrudTest extends TestCase
{
    use SelfServiceTestHelpers;

    private const BASE = '/api/v2/mailboxes/anna@example.org/app-passwords';

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function create(array $body): TestResponse
    {
        return $this->postJson(self::BASE, $body, $this->csrf());
    }

    private function row(int $id): object
    {
        $row = DB::table('app_passwd')->where('id', $id)->first();
        $this->assertNotNull($row);

        return $row;
    }

    private function verifies(int $id, string $password): bool
    {
        return (new LegacyPasswordVerifier)->verify((string) $this->row($id)->password, $password);
    }

    public function test_a_given_password_is_stored_hashed_and_never_returned(): void
    {
        $this->asSelfServiceUser();

        $response = $this->create(['name' => 'Phone', 'password' => 'AppPassw0rd!x', 'protocols' => ['imap', 'smtp']])->assertCreated();

        $id = (int) $response->json('data.id');
        $response->assertHeader('Location', self::BASE.'/'.$id)
            ->assertHeader('ETag', (string) $response->json('data.etag'))
            ->assertJsonPath('data.generatedPassword', null)
            ->assertJsonPath('data.name', 'Phone')
            ->assertJsonPath('data.mailbox', 'anna@example.org')
            ->assertJsonPath('data.protocols', ['imap', 'smtp'])
            ->assertJsonPath('data.active', true);
        $this->assertStringNotContainsString('AppPassw0rd!x', (string) $response->getContent());
        $this->assertStringNotContainsString('BLF-CRYPT', (string) $response->getContent());
        $row = $this->row($id);
        $this->assertStringStartsWith('{BLF-CRYPT}', (string) $row->password);
        $this->assertTrue($this->verifies($id, 'AppPassw0rd!x'));
        $this->assertSame(['example.org', 1, 1, 0, 0, 0, 0, 1], [$row->domain, (int) $row->imap_access, (int) $row->smtp_access, (int) $row->pop3_access, (int) $row->sieve_access, (int) $row->eas_access, (int) $row->dav_access, (int) $row->active]);

        $record = $this->onlyAuditRecord('appPasswordCreation');
        $this->assertSame('appPassword', $record->target_type);
        $this->assertSame('anna@example.org', $record->target_id);
        $this->assertSame('Phone', $record->target_name);
        $this->assertSame($id, $record->details['appPasswordId']);
        $this->assertFalse($record->details['passwordGenerated']);
        $this->assertStringNotContainsString('AppPassw0rd!x', (string) json_encode($record));
    }

    public function test_a_generated_password_is_returned_once(): void
    {
        $this->asSelfServiceUser();

        $response = $this->create(['name' => 'Tablet'])->assertCreated();

        $generated = (string) $response->json('data.generatedPassword');
        $this->assertSame(32, strlen($generated));
        $id = (int) $response->json('data.id');
        $this->assertTrue($this->verifies($id, $generated));
        $this->assertStringNotContainsString($generated, (string) $this->getJson(self::BASE.'/'.$id)->getContent());
        $this->assertStringNotContainsString($generated, (string) $this->getJson(self::BASE)->getContent());
        $this->assertTrue($this->onlyAuditRecord('appPasswordCreation')->details['passwordGenerated']);
        $this->assertStringNotContainsString($generated, (string) json_encode($this->onlyAuditRecord('appPasswordCreation')));
    }

    public function test_defaults_are_all_protocols_and_active_and_an_empty_list_is_accepted(): void
    {
        $this->asSelfServiceUser();

        $all = $this->create(['name' => 'All'])->assertCreated();
        $all->assertJsonPath('data.protocols', ['imap', 'pop3', 'smtp', 'sieve', 'activeSync', 'dav'])->assertJsonPath('data.active', true);

        $none = $this->create(['name' => 'None', 'protocols' => [], 'active' => false])->assertCreated();
        $none->assertJsonPath('data.protocols', [])->assertJsonPath('data.active', false);
        $row = $this->row((int) $none->json('data.id'));
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], [(int) $row->imap_access, (int) $row->pop3_access, (int) $row->smtp_access, (int) $row->sieve_access, (int) $row->eas_access, (int) $row->dav_access, (int) $row->active]);
    }

    public function test_names_are_stored_encoded_and_returned_plain(): void
    {
        $this->asSelfServiceUser();

        $id = (int) $this->create(['name' => ' A&B '])->assertCreated()->assertJsonPath('data.name', 'A&B')->json('data.id');

        $this->assertSame('A&amp;B', $this->row($id)->name);
        $this->getJson(self::BASE.'/'.$id)->assertJsonPath('data.name', 'A&B');
    }

    public function test_invalid_values_are_refused(): void
    {
        $this->asSelfServiceUser();
        $this->passwordPolicy(10, numbers: true);

        $this->create(['name' => str_repeat('&', 60)])->assertStatus(422)->assertJsonPath('errors.name.0.code', 'tooLong')->assertJsonPath('errors.name.0.params.stored', true);
        $this->create(['name' => 'Phone', 'password' => 'short'])->assertStatus(422)->assertJsonPath('errors.password.0.code', 'tooShort');
        $this->create(['name' => 'Phone', 'password' => '{SSHA512}abcdefghij1'])->assertStatus(422)->assertJsonPath('errors.password.0.code', 'preHashedPassword');
        $this->assertSame(0, DB::table('app_passwd')->count());
    }

    public function test_the_list_pages_sorts_filters_and_searches_without_secrets(): void
    {
        $this->asSelfServiceUser();
        for ($i = 1; $i <= 40; $i++) {
            $this->mailcow()->appPasswd('anna@example.org', sprintf('App %02d', $i), active: $i % 2);
        }
        $this->mailcow()->appPasswd('anna@example.org', 'A&B');

        $response = $this->getJson(self::BASE)->assertOk()->assertJsonPath('meta', ['total' => 41, 'limit' => 25, 'offset' => 0])->assertJsonCount(25, 'data');
        $this->assertSame('A&B', $response->json('data.0.name'));
        $this->assertStringNotContainsString('BLF-CRYPT', (string) $response->getContent());
        $first = $response->json('data.1');
        $this->getJson(self::BASE.'/'.$first['id'])->assertHeader('ETag', $first['etag']);

        $this->assertSame('App 40', $this->getJson(self::BASE.'?sort=name&order=desc&limit=1')->json('data.0.name'));
        $this->getJson(self::BASE.'?active=false')->assertJsonPath('meta.total', 20);
        $this->assertSame(['A&B'], array_column($this->getJson(self::BASE.'?q=%26')->json('data'), 'name'));
        $this->getJson(self::BASE.'?q=app%200')->assertJsonPath('meta.total', 9);
        $this->getJson(self::BASE.'?sort=createdAt&limit=1')->assertOk();
    }

    public function test_a_change_sets_only_the_values_sent(): void
    {
        $this->asSelfServiceUser();
        $id = $this->mailcow()->appPasswd('anna@example.org', 'Phone', 'AppPassw0rd!x');
        $hash = $this->row($id)->password;

        $this->patchMerge(self::BASE.'/'.$id, ['name' => 'Phone 2', 'protocols' => ['imap']], $this->csrf())
            ->assertOk()
            ->assertJsonPath('data.name', 'Phone 2')
            ->assertJsonPath('data.protocols', ['imap'])
            ->assertJsonPath('data.generatedPassword', null);
        $this->assertSame($hash, $this->row($id)->password);
        $record = $this->auditRecords('appPasswordUpdate')[0];
        $this->assertSame(['name' => ['old' => 'Phone', 'new' => 'Phone 2'], 'protocols' => ['old' => ['imap', 'pop3', 'smtp', 'sieve', 'activeSync', 'dav'], 'new' => ['imap']]], $record->details['changes']);

        $this->patchMerge(self::BASE.'/'.$id, ['password' => 'N3w!AppPass'], $this->csrf())->assertOk();
        $this->assertTrue($this->verifies($id, 'N3w!AppPass'));
        $this->assertSame('changed', $this->auditRecords('appPasswordUpdate')[1]->details['changes']['password']);

        $generated = (string) $this->patchMerge(self::BASE.'/'.$id, ['generatePassword' => true], $this->csrf())->assertOk()->json('data.generatedPassword');
        $this->assertSame(32, strlen($generated));
        $this->assertTrue($this->verifies($id, $generated));

        $this->patchMerge(self::BASE.'/'.$id, ['active' => false], $this->csrf())->assertOk()->assertJsonPath('data.active', false);
    }

    public function test_invalid_changes_are_refused(): void
    {
        $this->asSelfServiceUser();
        $id = $this->mailcow()->appPasswd('anna@example.org', 'Phone');
        $tag = (string) $this->getJson(self::BASE.'/'.$id)->headers->get('ETag');

        $this->patchMerge(self::BASE.'/'.$id, ['password' => 'N3w!AppPass', 'generatePassword' => true], $this->csrf())
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0.code', 'mutuallyExclusive')
            ->assertJsonPath('errors.password.0.params.with', 'generatePassword');
        $this->withoutRequestValidation();
        $this->patchMerge(self::BASE.'/'.$id, [], $this->csrf())->assertStatus(422)->assertJsonPath('errors.body.0.code', 'required');

        DB::table('app_passwd')->where('id', $id)->update(['name' => 'Legacy rename']);
        $this->patchMerge(self::BASE.'/'.$id, ['name' => 'Mine'], $this->csrf() + ['If-Match' => $tag])->assertStatus(412);
        $this->assertSame('Legacy rename', $this->row($id)->name);
    }

    public function test_an_administrator_keeps_the_owner_and_applies_a_new_password(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $id = $this->mailcow()->appPasswd('anna@example.org', 'Phone');
        $this->asAdmin();

        $this->patchMerge(self::BASE.'/'.$id, ['name' => 'Renamed', 'password' => 'N3w!AppPass'], $this->csrf())->assertOk()->assertJsonPath('data.mailbox', 'anna@example.org');

        $this->assertSame('anna@example.org', $this->row($id)->mailbox);
        $this->assertTrue($this->verifies($id, 'N3w!AppPass'));
    }

    public function test_deletion_removes_the_row(): void
    {
        $this->asSelfServiceUser();
        $id = $this->mailcow()->appPasswd('anna@example.org', 'Phone');

        $this->deleteJson(self::BASE.'/'.$id, [], $this->csrf())->assertNoContent();

        $this->assertFalse(DB::table('app_passwd')->where('id', $id)->exists());
        $record = $this->onlyAuditRecord('appPasswordDeletion');
        $this->assertSame($id, $record->details['appPasswordId']);
        $this->assertSame('Phone', $record->target_name);
        $this->getJson(self::BASE.'/'.$id)->assertNotFound();
    }

    public function test_changes_made_through_the_legacy_ui_are_visible_at_once(): void
    {
        $this->asSelfServiceUser();
        $id = $this->mailcow()->appPasswd('anna@example.org', 'Phone');
        $before = $this->getJson(self::BASE)->json('data.0.etag');

        DB::table('app_passwd')->where('id', $id)->update(['name' => 'Legacy &amp; renamed', 'pop3_access' => 0]);

        $after = $this->getJson(self::BASE)->assertJsonPath('data.0.name', 'Legacy & renamed')->json('data.0');
        $this->assertNotSame($before, $after['etag']);
        $this->assertNotContains('pop3', $after['protocols']);
    }

    public function test_the_tag_changes_when_only_the_password_changes(): void
    {
        $this->asSelfServiceUser();
        $id = $this->mailcow()->appPasswd('anna@example.org', 'Phone', 'AppPassw0rd!x');
        $before = (string) $this->getJson(self::BASE.'/'.$id)->headers->get('ETag');

        DB::table('app_passwd')->where('id', $id)->update(['password' => '{PLAIN}other']);

        $after = (string) $this->getJson(self::BASE.'/'.$id)->headers->get('ETag');
        $this->assertNotSame($before, $after);
        $this->assertStringNotContainsString(hash('sha256', '{PLAIN}other'), $after);
    }
}
