<?php

namespace Tests\Feature\SieveFilters;

use App\Services\SieveFilters\SieveFilterService;
use Illuminate\Support\Facades\DB;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T104 (feature 005 FR-048 to FR-051, US9 AS-1, AS-2): creating, changing and deleting the Sieve
 * filters of a mailbox with legacy's validation and activation rules.
 */
class SieveFilterCrudTest extends TestCase
{
    use SelfServiceTestHelpers;

    private const URL = '/api/v2/mailboxes/anna@example.org/sieve-filters';

    private const SCRIPT = "require \"fileinto\";\nif header :contains \"subject\" \"[SPAM]\" {\n  fileinto \"Junk\";\n}";

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->asAdmin();
    }

    /**
     * @return array<int, string> id => script_name
     */
    private function states(): array
    {
        return DB::table('sieve_filters')->where('username', 'anna@example.org')->orderBy('id')->pluck('script_name', 'id')->all();
    }

    public function test_a_new_active_prefilter_deactivates_the_old_one(): void
    {
        $old = $this->mailcow()->sieveFilterRow('anna@example.org', 'Old', 'prefilter');
        $post = $this->mailcow()->sieveFilterRow('anna@example.org', 'Post', 'postfilter');

        $response = $this->postJson(self::URL, ['description' => 'Spam to Junk', 'kind' => 'prefilter', 'script' => self::SCRIPT], $this->csrf())
            ->assertCreated()
            ->assertJsonPath('data.mailbox', 'anna@example.org')
            ->assertJsonPath('data.description', 'Spam to Junk')
            ->assertJsonPath('data.kind', 'prefilter')
            ->assertJsonPath('data.script', self::SCRIPT)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.modifiedAt', null);

        $id = (int) $response->json('data.id');
        $response->assertHeader('Location', '/api/v2/mailboxes/anna@example.org/sieve-filters/'.$id);
        $this->assertSame([$old => 'inactive', $post => 'active', $id => 'active'], $this->states());
        $this->assertSame([self::SCRIPT], DB::table('sieve_before')->where('username', 'anna@example.org')->where('script_name', 'active')->pluck('script_data')->all());

        $record = $this->auditRecords('sieveFilterCreation')[0];
        $this->assertSame('success', $record->outcome);
        $this->assertSame('anna@example.org', $record->target_id);
        $this->assertSame('Spam to Junk', $record->target_name);
        $this->assertSame([
            'sieveFilterId' => $id,
            'values' => ['description' => 'Spam to Junk', 'kind' => 'prefilter', 'active' => true, 'script' => ['size' => strlen(self::SCRIPT)]],
            'deactivatedIds' => [$old],
        ], $record->details);
        $this->assertStringNotContainsString('fileinto', json_encode($record->details) ?: '');
    }

    public function test_an_inactive_filter_leaves_the_others(): void
    {
        $old = $this->mailcow()->sieveFilterRow('anna@example.org', 'Old', 'prefilter');

        $id = (int) $this->postJson(self::URL, ['description' => 'Later', 'kind' => 'prefilter', 'script' => 'keep;', 'active' => false], $this->csrf())
            ->assertCreated()
            ->assertJsonPath('data.active', false)
            ->json('data.id');

        $this->assertSame([$old => 'active', $id => 'inactive'], $this->states());
    }

    public function test_changing_the_kind_of_an_active_filter(): void
    {
        $pre = $this->mailcow()->sieveFilterRow('anna@example.org', 'Pre', 'prefilter');
        $post = $this->mailcow()->sieveFilterRow('anna@example.org', 'Post', 'postfilter');

        $this->patchMerge(self::URL.'/'.$pre, ['kind' => 'postfilter'], $this->csrf())
            ->assertOk()
            ->assertJsonPath('data.kind', 'postfilter')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.description', 'Pre');

        $this->assertSame([$pre => 'active', $post => 'inactive'], $this->states());
        $this->assertSame(['postfilter', 'postfilter'], DB::table('sieve_filters')->orderBy('id')->pluck('filter_type')->all());
        $this->assertSame([
            'sieveFilterId' => $pre,
            'changes' => ['kind' => ['old' => 'prefilter', 'new' => 'postfilter']],
            'deactivatedIds' => [$post],
        ], $this->auditRecords('sieveFilterUpdate')[0]->details);
    }

    public function test_activating_and_changing_only_sent_members(): void
    {
        $first = $this->mailcow()->sieveFilterRow('anna@example.org', 'First', 'prefilter');
        $second = $this->mailcow()->sieveFilterRow('anna@example.org', 'Second', 'prefilter', 'discard;', false);

        $this->patchMerge(self::URL.'/'.$second, ['active' => true], $this->csrf())
            ->assertOk()
            ->assertJsonPath('data.script', 'discard;')
            ->assertJsonPath('data.description', 'Second');
        $this->assertSame([$first => 'inactive', $second => 'active'], $this->states());

        $response = $this->patchMerge(self::URL.'/'.$second, ['description' => 'Renamed', 'script' => self::SCRIPT], $this->csrf())->assertOk();
        $this->assertNotNull($response->json('data.modifiedAt'));
        $this->assertSame([
            'sieveFilterId' => $second,
            'changes' => [
                'description' => ['old' => 'Second', 'new' => 'Renamed'],
                'script' => ['changed' => true, 'oldSize' => 8, 'newSize' => strlen(self::SCRIPT)],
            ],
            'deactivatedIds' => [],
        ], $this->auditRecords('sieveFilterUpdate')[1]->details);

        $this->patchMerge(self::URL.'/'.$second, ['active' => false], $this->csrf())->assertOk()->assertJsonPath('data.active', false);
        $this->assertSame([$first => 'inactive', $second => 'inactive'], $this->states());
        $this->assertSame('anna@example.org', DB::table('sieve_filters')->where('id', $second)->value('username'));
    }

    public function test_invalid_scripts_and_descriptions(): void
    {
        $this->postJson(self::URL, ['description' => 'Broken', 'kind' => 'prefilter', 'script' => 'fileinto2 "x";'], $this->csrf())
            ->assertStatus(422)
            ->assertJsonPath('errors.script.0.code', 'sieveSyntax')
            ->assertJsonPath('errors.script.0.params.message', 'line 1: unknown command fileinto2')
            ->assertJsonPath('errors.script.0.params.line', 1);

        $big = 'keep;'.str_repeat(' ', 65531);
        $this->postJson(self::URL, ['description' => 'Big', 'kind' => 'prefilter', 'script' => $big], $this->csrf())
            ->assertStatus(422)
            ->assertJsonPath('errors.script.0.code', 'tooLong')
            ->assertJsonPath('errors.script.0.params', ['max' => 65535, 'unit' => 'bytes']);
        // 65,535 bytes fit.
        $this->postJson(self::URL, ['description' => 'Fits', 'kind' => 'prefilter', 'script' => substr($big, 0, 65535)], $this->csrf())->assertCreated();

        $this->withoutRequestValidation();
        $this->postJson(self::URL, ['description' => str_repeat('d', 256), 'kind' => 'prefilter', 'script' => 'keep;'], $this->csrf())
            ->assertStatus(422)
            ->assertJsonPath('errors.description.0.code', 'tooLong');

        $id = $this->mailcow()->sieveFilterRow('anna@example.org', 'Kept', 'postfilter');
        $this->patchMerge(self::URL.'/'.$id, ['script' => 'if true {'], $this->csrf())
            ->assertStatus(422)
            ->assertJsonPath('errors.script.0.code', 'sieveSyntax');
        $this->assertSame('keep;', DB::table('sieve_filters')->where('id', $id)->value('script_data'));
        $this->assertSame(2, DB::table('sieve_filters')->count());
        $this->assertSame(['failure', 'failure', 'success', 'failure'], array_column($this->auditRecords('sieveFilterCreation'), 'outcome'));
    }

    public function test_invalid_members_are_refused(): void
    {
        $this->withoutRequestValidation();
        $id = $this->mailcow()->sieveFilterRow('anna@example.org', 'Kept', 'postfilter');

        $this->postJson(self::URL, ['description' => '', 'kind' => 'other', 'script' => '', 'active' => 'yes', 'mailbox' => 'bob@example.org'], $this->csrf())
            ->assertStatus(422)
            ->assertJsonPath('errors.description.0.code', 'tooShort')
            ->assertJsonPath('errors.kind.0.code', 'notInList')
            ->assertJsonPath('errors.script.0.code', 'tooShort')
            ->assertJsonPath('errors.active.0.code', 'invalidType')
            ->assertJsonPath('errors.mailbox.0.code', 'immutable');
        $this->patchMerge(self::URL.'/'.$id, [], $this->csrf())->assertStatus(422)->assertJsonPath('errors.body.0.code', 'required');
        $this->patchMerge(self::URL.'/'.$id, ['mailbox' => 'bob@example.org'], $this->csrf())->assertStatus(422)->assertJsonPath('errors.mailbox.0.code', 'immutable');
    }

    public function test_reading_tags_and_deleting(): void
    {
        $id = $this->mailcow()->sieveFilterRow('anna@example.org', 'Kept', 'postfilter', self::SCRIPT);

        $tag = (string) $this->getJson(self::URL.'/'.$id)->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.kind', 'postfilter')
            ->assertHeader('ETag')
            ->json('data.etag');
        $this->patchMerge(self::URL.'/'.$id, ['description' => 'X'], $this->csrf() + ['If-Match' => '"stale"'])->assertStatus(412);
        $this->deleteJson(self::URL.'/'.$id, [], $this->csrf() + ['If-Match' => '"stale"'])->assertStatus(412);

        $newTag = (string) $this->patchMerge(self::URL.'/'.$id, ['description' => 'X'], $this->csrf() + ['If-Match' => $tag])->assertOk()->json('data.etag');
        $this->assertNotSame($tag, $newTag);

        $this->deleteJson(self::URL.'/'.$id, [], $this->csrf() + ['If-Match' => $newTag])->assertNoContent();
        $this->assertSame(0, DB::table('sieve_filters')->count());
        $this->getJson(self::URL.'/'.$id)->assertNotFound();
        $this->deleteJson(self::URL.'/'.$id, [], $this->csrf())->assertNotFound();
        $this->assertSame(['sieveFilterId' => $id, 'removed' => ['description' => 'X', 'kind' => 'postfilter', 'active' => true, 'script' => ['size' => strlen(self::SCRIPT)]]], array_values(array_filter($this->auditRecords('sieveFilterDeletion'), fn (object $r): bool => $r->outcome === 'success'))[0]->details);
    }

    public function test_a_filter_of_another_mailbox_is_not_found_under_this_one(): void
    {
        $this->mailcow()->mailbox('bob@example.org');
        $foreign = $this->mailcow()->sieveFilterRow('bob@example.org', 'Bob', 'prefilter');

        $this->getJson(self::URL.'/'.$foreign)->assertNotFound();
        $this->patchMerge(self::URL.'/'.$foreign, ['active' => false], $this->csrf())->assertNotFound();
        $this->deleteJson(self::URL.'/'.$foreign, [], $this->csrf())->assertNotFound();
        $this->assertSame('active', DB::table('sieve_filters')->where('id', $foreign)->value('script_name'));
    }

    public function test_an_activation_waits_for_the_mailboxs_lock(): void
    {
        $old = $this->mailcow()->sieveFilterRow('anna@example.org', 'Old', 'prefilter');
        config(['database.connections.lockcheck' => config('database.connections.mysql')]);
        $other = DB::connection('lockcheck');
        $name = SieveFilterService::lockName('anna@example.org');
        $this->assertSame('restv2_sieve_'.sha1('anna@example.org'), $name);
        $other->select('SELECT GET_LOCK(?, 0)', [$name]);

        try {
            // A concurrent activation of the same mailbox holds the lock: this one stores nothing.
            $this->postJson(self::URL, ['description' => 'New', 'kind' => 'prefilter', 'script' => 'keep;'], $this->csrf())->assertStatus(500);
        } finally {
            $other->select('SELECT RELEASE_LOCK(?)', [$name]);
            DB::purge('lockcheck');
        }

        $this->assertSame([$old => 'active'], $this->states());
    }
}
