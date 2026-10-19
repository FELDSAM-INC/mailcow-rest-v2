<?php

namespace Tests\Feature\Rspamd;

use App\Services\Config\MailcowConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\Support\Settings\UsesSharedFiles;
use Tests\TestCase;

/**
 * Feature 013 T048 (FR-056 to FR-059, FR-093, SC-006, AS-3.4 to AS-3.6, gap G17): the global regex
 * maps that the operator's configuration makes editable, read from and written to the map files
 * Rspamd reads, in place, trimmed and ending with a line break, touched again afterwards; every line
 * checked on the server. The list returns summaries; the content comes from the single read.
 */
class RspamdRegexMapTest extends TestCase
{
    use SettingsTestHelpers;
    use UsesSharedFiles;

    private const URI = '/api/v2/settings/rspamd-regex-maps';

    private const MAP = 'global_smtp_from_blacklist.map';

    public function test_the_list_returns_the_configured_maps_in_order_as_summaries(): void
    {
        $this->asAdmin();
        $this->files->writeMap('bad_words.map', "/x/i\n");

        $response = $this->getJson(self::URI)->assertOk();

        $data = (array) $response->json('data');
        $this->assertCount(13, $data);
        $this->assertSame('global_mime_from_blacklist.map', $data[0]['id']);
        $this->assertSame('Header-From: Denylist', $data[0]['name']);
        $this->assertSame('monitoring_nolog.map', $data[12]['id']);
        $this->assertSame(['id', 'name', 'available', 'sizeBytes', 'etag'], array_keys($data[7]));
        $this->assertSame(['bad_words.map', true, 5], [$data[7]['id'], $data[7]['available'], $data[7]['sizeBytes']]);
        $response->assertJsonPath('meta', ['total' => 13, 'limit' => 25, 'offset' => 0]);
        $this->assertSame($data[7]['etag'], $this->getJson(self::URI.'/bad_words.map')->json('data.etag'));
    }

    public function test_the_list_sorts_pages_and_searches_the_name(): void
    {
        $this->asAdmin();

        $byName = array_column((array) $this->getJson(self::URI.'?sort=name')->json('data'), 'name');
        $sorted = $byName;
        sort($sorted, SORT_STRING | SORT_FLAG_CASE);
        $this->assertSame($sorted, $byName);
        $this->assertSame('monitoring_nolog.map', $this->getJson(self::URI.'?sort=id&order=desc')->json('data.0.id'));
        $this->assertSame(['Recipient Denylist', 'Recipient Allowlist'], array_column((array) $this->getJson(self::URI.'?q=recipient')->json('data'), 'name'));
        $this->getJson(self::URI.'?limit=2&offset=12')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 13);
    }

    public function test_a_single_map_returns_its_content(): void
    {
        $this->asAdmin();

        $response = $this->getJson(self::URI.'/bad_words.map')->assertOk();

        $response->assertJsonPath('data.id', 'bad_words.map')
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.content', $this->files->mapContent('bad_words.map'))
            ->assertHeader('ETag', (string) $response->json('data.etag'));
    }

    public function test_a_map_missing_on_the_server_is_listed_as_unavailable_and_cannot_be_replaced(): void
    {
        $this->asAdmin();
        $this->files->removeMap(self::MAP);

        $this->getJson(self::URI.'/'.self::MAP)->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.content', '');
        $this->send('PUT', self::URI.'/'.self::MAP, ['content' => '/x/'])->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/regex-map-unavailable');
        $this->send('PUT', self::URI.'/'.self::MAP.'?validateOnly=true', ['content' => '/x/'])->assertStatus(409);

        $this->assertFileDoesNotExist($this->files->mapPath(self::MAP));
        $this->assertSame(['fileUnavailable'], array_column($this->refusalsOf('rspamdRegexMapReplacement'), 'failure_reason'));
    }

    public function test_a_map_the_configuration_does_not_list_is_not_found(): void
    {
        $this->asAdmin();
        config(['restv2.vars_overrides.RSPAMD_MAPS' => '{"regex": {"Only": "bad_words.map"}}']);

        $this->getJson(self::URI.'/'.self::MAP)->assertNotFound();
        $this->send('PUT', self::URI.'/'.self::MAP, ['content' => '/x/'])->assertNotFound();
        $this->send('PUT', self::URI.'/ip_wl.map', ['content' => '/x/'])->assertNotFound();
        $this->getJson(self::URI)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_replacement_is_written_in_place_trimmed_and_touched_again(): void
    {
        $this->asAdmin();
        $inode = $this->files->mapInode(self::MAP);
        touch($this->files->mapPath(self::MAP), time() - 100);
        $old = $this->files->mapContent(self::MAP);

        $response = $this->send('PUT', self::URI.'/'.self::MAP, ['content' => "# comment\n/.*@spam\\.example$/i\n\n"])->assertOk();

        $this->assertSame("# comment\n/.*@spam\\.example$/i\n", $this->files->mapContent(self::MAP));
        $this->assertSame($inode, $this->files->mapInode(self::MAP));
        $this->assertGreaterThanOrEqual(time(), $this->files->mapMtime(self::MAP));
        $this->assertNotSame([], $this->sleeper()->slept, 'The map is touched again after a wait, as legacy does.');
        $response->assertJsonPath('data.content', "# comment\n/.*@spam\\.example$/i\n")
            ->assertJsonPath('data.available', true)
            ->assertHeader('ETag', (string) $response->json('data.etag'));
        $this->assertSame($response->json('data.etag'), $this->getJson(self::URI.'/'.self::MAP)->json('data.etag'));

        $record = $this->successesOf('rspamdRegexMapReplacement');
        $this->assertCount(1, $record);
        $this->assertSame(['rspamdRegexMap', self::MAP, 'Envelope Sender Denylist'], [$record[0]->target_type, $record[0]->target_id, $record[0]->target_name]);
        $this->assertSame(['oldBytes' => strlen($old), 'newBytes' => 31], $record[0]->details);
    }

    public function test_an_invalid_line_is_refused_with_its_number_and_the_file_is_untouched(): void
    {
        $this->asAdmin();
        touch($this->files->mapPath(self::MAP), time() - 100);
        $before = [$this->files->mapContent(self::MAP), $this->files->mapMtime(self::MAP)];

        $this->send('PUT', self::URI.'/'.self::MAP, ['content' => "# ok\n/[unclosed/i"])->assertStatus(422)
            ->assertJsonPath('errors.content.0.code', 'invalidRegexLine')
            ->assertJsonPath('errors.content.0.params', ['line' => 2, 'reason' => 'doesNotCompile']);
        $this->send('PUT', self::URI.'/'.self::MAP, ['content' => 'spam.example'])->assertStatus(422)
            ->assertJsonPath('errors.content.0.params.reason', 'notSlashDelimited');

        $this->assertSame($before, [$this->files->mapContent(self::MAP), $this->files->mapMtime(self::MAP)]);
    }

    public function test_validate_only_checks_without_writing_or_auditing(): void
    {
        $this->asAdmin();
        $before = $this->files->mapContent(self::MAP);

        $this->send('PUT', self::URI.'/'.self::MAP.'?validateOnly=true', ['content' => '/ok/i'])->assertOk()->assertExactJson(['data' => ['valid' => true]]);
        $this->send('PUT', self::URI.'/'.self::MAP.'?validateOnly=true', ['content' => '/x/im'])->assertStatus(422)->assertJsonPath('errors.content.0.params.reason', 'tooManyFlags');

        $this->assertSame($before, $this->files->mapContent(self::MAP));
        $this->assertSame([], $this->auditOf('rspamdRegexMapReplacement'));
    }

    public function test_a_map_above_one_mebibyte_is_too_long(): void
    {
        $this->asAdmin();

        $this->send('PUT', self::URI.'/'.self::MAP, ['content' => str_repeat('#', 1048577)])->assertStatus(422)
            ->assertJsonPath('errors.content.0.params', ['max' => 1048576, 'unit' => 'bytes']);
    }

    public function test_a_tag_outdated_by_a_legacy_write_is_refused(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson(self::URI.'/'.self::MAP)->json('data.etag');
        $this->files->writeMap(self::MAP, "/legacy/\n");

        $this->send('PUT', self::URI.'/'.self::MAP, ['content' => '/mine/'], ['If-Match' => $etag])->assertStatus(412);

        $this->assertSame("/legacy/\n", $this->files->mapContent(self::MAP));
    }

    public function test_a_local_override_without_a_map_hides_it(): void
    {
        $this->asAdmin();
        $dir = storage_path('framework/testing/mailcow-inc-maps-'.Str::random(8));
        File::ensureDirectoryExists($dir);
        File::copy(base_path('tests/fixtures/mailcow-inc/vars.inc.php'), $dir.'/vars.inc.php');
        file_put_contents($dir.'/vars.local.inc.php', "<?php\nunset(\$RSPAMD_MAPS['regex']['Monitoring Hosts']);\n");
        config(['restv2.mailcow_inc_dir' => $dir]);
        $this->app->forgetInstance(MailcowConfig::class);

        try {
            $this->getJson(self::URI)->assertOk()->assertJsonCount(12, 'data');
            $this->getJson(self::URI.'/monitoring_nolog.map')->assertNotFound();
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
