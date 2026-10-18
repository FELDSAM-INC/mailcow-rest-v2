<?php

namespace Tests\Feature\DomainTemplates;

use Illuminate\Support\Facades\DB;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T077 (research D22): templates stored by legacy are read tolerantly, edits write the
 * legacy format, and legacy's add form reads templates written by the API.
 */
class DomainTemplateLegacyRowsTest extends TestCase
{
    use DomainTestHelpers;

    public function test_legacy_rows_are_read_with_the_defaults(): void
    {
        $id = $this->mailcow()->domainTemplate('Legacy', [
            'tags' => '["gold"]',
            'max_num_mboxes_for_domain' => '7',
            'rl_value' => '',
            'key_size' => 512,
        ]);
        $other = $this->mailcow()->domainTemplate('Legacy2', ['rl_value' => '10', 'rl_frame' => 'd']);
        $this->asAdmin();

        $data = $this->getJson('/api/v2/domain-templates/'.$id)->assertOk()->json('data');
        $this->assertSame(['gold'], $data['tags']);
        $this->assertSame(7, $data['maxMailboxes']);
        $this->assertSame(400, $data['maxAliases']);
        $this->assertNull($data['rateLimit']);
        $this->assertSame(512, $data['dkimKeySize']);
        $this->assertSame(['value' => 10, 'frame' => 'day'], $this->getJson('/api/v2/domain-templates/'.$other)->json('data.rateLimit'));
        $this->getJson('/api/v2/domain-templates')->assertOk();
    }

    public function test_an_edit_writes_the_normalised_legacy_format(): void
    {
        $id = $this->mailcow()->domainTemplate('Legacy', ['tags' => '["gold"]', 'max_num_mboxes_for_domain' => '7']);
        $this->asAdmin();

        $this->patchMerge('/api/v2/domain-templates/'.$id, ['maxAliases' => 3], $this->csrf())->assertOk();

        $stored = json_decode((string) DB::table('templates')->where('id', $id)->value('attributes'), true);
        $this->assertSame(['gold'], $stored['tags']);
        $this->assertSame(7, $stored['max_num_mboxes_for_domain']);
        $this->assertSame(3, $stored['max_num_aliases_for_domain']);
        $this->assertSame('', $stored['rl_value']);
    }

    public function test_legacys_add_form_reads_api_templates(): void
    {
        $this->asAdmin();
        $id = (int) $this->postJson('/api/v2/domain-templates', ['name' => 'Api', 'maxMailboxQuota' => 2048 * self::MIB, 'rateLimit' => ['value' => 3, 'frame' => 'hour']], $this->csrf())
            ->assertCreated()->json('data.id');

        // What legacy's add-domain form computes from the template (functions.mailbox.inc.php 514).
        $row = json_decode((string) DB::table('templates')->where('id', $id)->value('attributes'), true);
        $this->assertSame(2048, $row['def_quota_for_mbox'] / 1024 ** 2);
        $this->assertSame(3, $row['rl_value']);
        $this->assertSame('h', $row['rl_frame']);
        $this->assertIsArray($row['tags']);
    }
}
