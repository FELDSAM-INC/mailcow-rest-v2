<?php

namespace Tests\Feature\Rspamd;

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T050 (FR-061, AS-3.1, AS-3.7): the presets mailcow ships, read from the mounted
 * `data/web/inc/presets`, each with its identifier, translation key, English headline and content;
 * `rspamd` presets for administrators, `sieve` presets for administrators and domain administrators.
 */
class PresetTest extends TestCase
{
    use SettingsTestHelpers;

    public function test_administrators_list_the_rspamd_presets(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/presets/rspamd')->assertOk();

        $this->assertSame(['preset_1', 'preset_3', 'preset_4'], array_column((array) $response->json('data'), 'id'));
        $response->assertJsonPath('meta', ['total' => 3, 'limit' => 25, 'offset' => 0])
            ->assertJsonPath('data.2.kind', 'rspamd')
            ->assertJsonPath('data.2.translationKey', 'admin.rsettings_preset_4')
            ->assertJsonPath('data.2.headline', 'Disable Rspamd for a domain')
            ->assertJsonPath('data.2.content', Yaml::parseFile(base_path('tests/fixtures/mailcow-inc/presets/rspamd/preset_4.yml'))['content']);
    }

    public function test_the_sieve_presets_are_sorted_naturally_paged_and_searchable(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/presets/sieve')->assertOk();

        $this->assertSame(['sieve_1', 'sieve_2', 'sieve_3', 'sieve_4', 'sieve_5', 'sieve_6', 'sieve_7', 'sieve_8'], array_column((array) $response->json('data'), 'id'));
        $response->assertJsonPath('data.0.translationKey', 'mailbox.sieve_preset_1')
            ->assertJsonPath('data.0.headline', 'Discard mail with probable dangerous file types');
        $this->assertSame(['sieve_8', 'sieve_7'], array_column((array) $this->getJson('/api/v2/presets/sieve?order=desc&limit=2')->json('data'), 'id'));
        $this->assertSame(['sieve_5'], array_column((array) $this->getJson('/api/v2/presets/sieve?q=VACATION')->json('data'), 'id'));
        $this->assertSame(['sieve_1'], array_column((array) $this->getJson('/api/v2/presets/sieve?q=moo')->json('data'), 'id'), 'q searches the content too.');
    }

    public function test_natural_order_puts_ten_after_two_and_literal_headlines_have_no_key(): void
    {
        $this->asAdmin();
        $dir = storage_path('framework/testing/mailcow-inc-presets');
        @mkdir($dir.'/presets/sieve', 0755, true);
        copy(base_path('tests/fixtures/mailcow-inc/vars.inc.php'), $dir.'/vars.inc.php');
        file_put_contents($dir.'/presets/sieve/sieve_10.yml', "headline: My own preset\ncontent: |\n  keep;\n");
        file_put_contents($dir.'/presets/sieve/sieve_2.yml', "headline: lang.sieve_preset_2\ncontent: |\n  keep;\n");
        config(['restv2.mailcow_inc_dir' => $dir]);

        try {
            $response = $this->getJson('/api/v2/presets/sieve')->assertOk();
            $this->assertSame(['sieve_2', 'sieve_10'], array_column((array) $response->json('data'), 'id'));
            $response->assertJsonPath('data.1.translationKey', null)->assertJsonPath('data.1.headline', 'My own preset');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_domain_administrators_read_sieve_presets_only(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $this->getJson('/api/v2/presets/sieve')->assertOk()->assertJsonCount(8, 'data');
        $this->getJson('/api/v2/presets/rspamd')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }

    public function test_mailbox_users_are_forbidden_and_invalid_kinds_are_not_found(): void
    {
        $this->asMailboxUser('user@example.org');
        $this->getJson('/api/v2/presets/sieve')->assertForbidden();

        $this->forgetCookies();
        $this->asAdmin();
        $this->withoutRequestValidation();
        $this->getJson('/api/v2/presets/unknown')->assertNotFound();
    }
}
