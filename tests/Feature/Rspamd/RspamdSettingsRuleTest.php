<?php

namespace Tests\Feature\Rspamd;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T047 (FR-050 to FR-055, FR-093, SC-006, AS-3.2, AS-3.3, AS-3.8): administrators list,
 * create, read, change and delete the rules of mailcow's Rspamd settings map (`settingsmap`), with
 * structurally checked content and a validate-only option; Rspamd receives active rules through
 * mailcow's existing settings map.
 */
class RspamdSettingsRuleTest extends TestCase
{
    use SettingsTestHelpers;

    private const URI = '/api/v2/settings/rspamd-rules';

    private static function preset(int $number): string
    {
        return (string) Yaml::parseFile(__DIR__."/../../fixtures/mailcow-inc/presets/rspamd/preset_{$number}.yml")['content'];
    }

    private static function disableForExampleOrg(): string
    {
        return str_replace('develcow.de', 'example.org', self::preset(4));
    }

    public function test_a_rule_from_a_preset_is_created_active_and_stored_trimmed(): void
    {
        $this->asAdmin();
        $content = "\n  ".self::disableForExampleOrg()."  \n";

        $response = $this->send('POST', self::URI, ['description' => 'Disable Rspamd for example.org', 'content' => $content, 'active' => true])->assertCreated();

        $id = (int) $response->json('data.id');
        $response->assertHeader('Location', self::URI.'/'.$id)
            ->assertHeader('ETag', (string) $response->json('data.etag'))
            ->assertJsonPath('data.description', 'Disable Rspamd for example.org')
            ->assertJsonPath('data.content', trim($content))
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.modifiedAt', null);
        $row = DB::table('settingsmap')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame([trim($content), 'Disable Rspamd for example.org', 1], [$row->content, $row->desc, (int) $row->active]);
        $this->getJson(self::URI.'/'.$id)->assertOk()->assertJsonPath('data.etag', $response->json('data.etag'));
    }

    public function test_a_rule_created_without_an_active_state_is_inactive(): void
    {
        $this->asAdmin();

        $id = (int) $this->send('POST', self::URI, ['description' => 'Draft', 'content' => self::preset(1)])->assertCreated()->json('data.id');

        $this->assertSame(0, (int) DB::table('settingsmap')->where('id', $id)->value('active'));
    }

    public function test_unbalanced_content_is_refused_and_no_row_is_written(): void
    {
        $this->asAdmin();

        $this->send('POST', self::URI, ['description' => 'Broken', 'content' => "rcpt = \"x\";\napply \"default\" {\n"])->assertStatus(422)
            ->assertJsonPath('errors.content.0.code', 'unbalancedStructure')
            ->assertJsonPath('errors.content.0.params', ['line' => 2, 'construct' => 'unclosedBrace']);

        $this->assertSame(0, DB::table('settingsmap')->count());
        $this->assertCount(1, $this->refusalsOf('rspamdSettingsRuleCreation'));
    }

    public function test_validate_only_checks_without_storing_or_auditing(): void
    {
        $this->asAdmin();

        $this->send('POST', self::URI.'?validateOnly=true', ['description' => 'Check', 'content' => self::preset(3)])->assertOk()->assertExactJson(['data' => ['valid' => true]]);
        $this->send('POST', self::URI.'?validateOnly=true', ['description' => 'Check', 'content' => 'apply {'])->assertStatus(422)->assertJsonPath('errors.content.0.code', 'unbalancedStructure');

        $this->assertSame(0, DB::table('settingsmap')->count());
        $this->assertSame([], $this->auditOf('rspamdSettingsRuleCreation'));
    }

    public function test_invalid_creations_are_refused_by_field(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();

        $this->send('POST', self::URI, ['description' => '', 'content' => '   '])->assertStatus(422)
            ->assertJsonPath('errors.description.0.code', 'required')
            ->assertJsonPath('errors.content.0.code', 'required');
        $this->send('POST', self::URI, ['description' => str_repeat('d', 256), 'content' => 'x = 1;'])->assertStatus(422)->assertJsonPath('errors.description.0.code', 'tooLong');
        $this->send('POST', self::URI, ['description' => 'Big', 'content' => str_repeat('#', 1048577)])->assertStatus(422)
            ->assertJsonPath('errors.content.0.params', ['max' => 1048576, 'unit' => 'bytes']);
        $this->send('POST', self::URI, ['description' => 'x', 'content' => 'x = 1;', 'active' => 'yes'])->assertStatus(422)->assertJsonPath('errors.active.0.code', 'invalidType');

        $this->assertSame(0, DB::table('settingsmap')->count());
    }

    public function test_the_list_pages_sorts_filters_and_searches(): void
    {
        $this->asAdmin();
        $a = $this->mailcow()->settingsRule('Bravo', 'rcpt = "/.*@alpha.example$/";', 1);
        $b = $this->mailcow()->settingsRule('Alpha', 'from = "/.*/";', 0);
        $c = $this->mailcow()->settingsRule('Charlie', 'rcpt = "/.*@charlie.example$/";', 1);

        $list = $this->getJson(self::URI)->assertOk();
        $this->assertSame([$a, $b, $c], array_column((array) $list->json('data'), 'id'));
        $list->assertJsonPath('meta', ['total' => 3, 'limit' => 25, 'offset' => 0]);

        $this->assertSame([$b, $a, $c], array_column((array) $this->getJson(self::URI.'?sort=description')->json('data'), 'id'));
        $this->assertSame([$c, $b, $a], array_column((array) $this->getJson(self::URI.'?sort=id&order=desc')->json('data'), 'id'));
        $this->assertSame([$b, $a, $c], array_column((array) $this->getJson(self::URI.'?sort=active')->json('data'), 'id'));
        $this->assertSame([$a, $c], array_column((array) $this->getJson(self::URI.'?active=true')->json('data'), 'id'));
        $this->assertSame([$b], array_column((array) $this->getJson(self::URI.'?active=false')->json('data'), 'id'));
        $this->assertSame([$c], array_column((array) $this->getJson(self::URI.'?q=CHARLIE')->json('data'), 'id'));
        $this->assertSame([$a], array_column((array) $this->getJson(self::URI.'?q=alpha.example')->json('data'), 'id'), 'q searches the content too.');
        $this->getJson(self::URI.'?limit=1&offset=1')->assertOk()->assertJsonPath('data.0.id', $b)->assertJsonPath('meta.total', 3);
        $this->getJson(self::URI.'?sort=createdAt')->assertOk();
        $this->getJson(self::URI.'?sort=modifiedAt')->assertOk();

        foreach ((array) $list->json('data') as $entry) {
            $this->assertSame($entry['etag'], $this->getJson(self::URI.'/'.$entry['id'])->json('data.etag'));
        }
    }

    public function test_a_change_keeps_unsent_attributes_and_an_empty_value_is_refused(): void
    {
        $this->asAdmin();
        $id = $this->mailcow()->settingsRule('Rule', 'x = 1;', 0);

        $this->send('PATCH', self::URI.'/'.$id, ['active' => true, 'content' => "  y = 2;\n"])->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.content', 'y = 2;')
            ->assertJsonPath('data.description', 'Rule');
        $this->assertNotNull(DB::table('settingsmap')->where('id', $id)->value('modified'));
    }

    /**
     * Legacy keeps the old value for an empty description; this API refuses it.
     */
    public function test_an_invalid_change_is_refused(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();
        $id = $this->mailcow()->settingsRule('Rule', 'x = 1;', 0);

        $this->send('PATCH', self::URI.'/'.$id, ['description' => ''])->assertStatus(422)->assertJsonPath('errors.description.0.code', 'required');
        $this->send('PATCH', self::URI.'/'.$id, ['content' => 'apply {'])->assertStatus(422)->assertJsonPath('errors.content.0.code', 'unbalancedStructure');
        $this->send('PATCH', self::URI.'/'.$id, [])->assertStatus(422);

        $this->assertSame('Rule', DB::table('settingsmap')->where('id', $id)->value('desc'));
    }

    public function test_a_change_with_validate_only_changes_nothing(): void
    {
        $this->asAdmin();
        $id = $this->mailcow()->settingsRule('Rule', 'x = 1;', 0);

        $this->send('PATCH', self::URI.'/'.$id.'?validateOnly=true', ['content' => 'y = 2;'])->assertOk()->assertExactJson(['data' => ['valid' => true]]);

        $this->assertSame('x = 1;', DB::table('settingsmap')->where('id', $id)->value('content'));
        $this->assertSame([], $this->auditOf('rspamdSettingsRuleUpdate'));
    }

    public function test_an_outdated_tag_is_refused(): void
    {
        $this->asAdmin();
        $id = $this->mailcow()->settingsRule('Rule', 'x = 1;', 0);
        $etag = (string) $this->getJson(self::URI.'/'.$id)->json('data.etag');
        DB::table('settingsmap')->where('id', $id)->update(['content' => 'changed by legacy']);

        $this->send('PATCH', self::URI.'/'.$id, ['active' => true], ['If-Match' => $etag])->assertStatus(412);
        $this->send('DELETE', self::URI.'/'.$id, null, ['If-Match' => $etag])->assertStatus(412);

        $this->assertSame(0, (int) DB::table('settingsmap')->where('id', $id)->value('active'));
    }

    public function test_a_deletion_removes_the_row_and_unknown_rules_are_not_found(): void
    {
        $this->asAdmin();
        $id = $this->mailcow()->settingsRule('Rule', 'x = 1;', 1);

        $this->send('DELETE', self::URI.'/'.$id)->assertNoContent();

        $this->assertFalse(DB::table('settingsmap')->where('id', $id)->exists());
        $this->getJson(self::URI.'/'.$id)->assertNotFound();
        $this->send('PATCH', self::URI.'/'.$id, ['active' => false])->assertNotFound();
        $this->send('DELETE', self::URI.'/'.$id)->assertNotFound();
    }

    public function test_refused_writes_leave_the_effective_settings_map_unchanged(): void
    {
        $this->asAdmin();
        $before = $this->getJson('/api/v2/settings/rspamd-settings-map')->assertOk()->json('data.content');

        $this->send('POST', self::URI, ['description' => 'Broken', 'content' => '['])->assertStatus(422);

        $this->assertSame($before, $this->getJson('/api/v2/settings/rspamd-settings-map')->json('data.content'));
        $this->assertSame(0, DB::table('settingsmap')->count());
    }

    public function test_creation_update_and_deletion_are_audited_with_content_sizes_only(): void
    {
        $this->asAdmin();

        $id = (int) $this->send('POST', self::URI, ['description' => 'Rule', 'content' => 'secret_symbol = 1;'])->assertCreated()->json('data.id');
        $this->send('PATCH', self::URI.'/'.$id, ['content' => 'other = 2;', 'description' => 'Renamed'])->assertOk();
        $this->send('DELETE', self::URI.'/'.$id)->assertNoContent();

        $creation = $this->successesOf('rspamdSettingsRuleCreation');
        $this->assertCount(1, $creation);
        $this->assertSame(['rspamdSettingsRule', (string) $id, 'Rule'], [$creation[0]->target_type, $creation[0]->target_id, $creation[0]->target_name]);
        $this->assertSame(['changed' => true, 'oldBytes' => 0, 'newBytes' => 18], $creation[0]->details['changes']['content']);
        $update = $this->successesOf('rspamdSettingsRuleUpdate')[0];
        $this->assertSame(['old' => 'Rule', 'new' => 'Renamed'], $update->details['changes']['description']);
        $this->assertSame(['changed' => true, 'oldBytes' => 18, 'newBytes' => 10], $update->details['changes']['content']);
        $this->assertCount(1, $this->successesOf('rspamdSettingsRuleDeletion'));
        $this->assertStringNotContainsString('secret_symbol', json_encode($this->auditOf('rspamdSettingsRuleCreation')) ?: '');
    }
}
