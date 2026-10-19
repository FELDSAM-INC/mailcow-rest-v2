<?php

namespace Tests\Feature\GlobalFilters;

use App\Audit\AuditEventType;
use App\Clients\DockerApi\DockerCallResult;
use App\Services\Audit\AuditLogger;
use Tests\Support\Audit\FlakyAuditLogger;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\Support\Settings\UsesSharedFiles;
use Tests\TestCase;

/**
 * Feature 013 T059 (FR-062 to FR-065, FR-093, SC-006, AS-4.1 to AS-4.6): the global Sieve pre- and
 * post-filter, checked with legacy's parser, written in place to the shared files Dovecot compiles
 * at start, and followed by a Dovecot restart through mailcow's container service unless deferred;
 * an invalid script or a missing file never causes a restart.
 */
class GlobalSieveFilterTest extends TestCase
{
    use SettingsTestHelpers;
    use UsesSharedFiles;

    private const URI = '/api/v2/settings/global-sieve-filters';

    private const VALID = "require \"fileinto\";\r\nif header :contains \"X-Spam-Flag\" \"YES\" {\r\n  fileinto \"Junk\";\r\n}\r\n";

    public function test_both_filters_are_listed_pre_filter_first_with_their_tags(): void
    {
        $this->asAdmin();

        $response = $this->getJson(self::URI)->assertOk();

        $this->assertSame(['prefilter', 'postfilter'], array_column((array) $response->json('data'), 'id'));
        $response->assertJsonPath('data.0.script', $this->files->sieve('before'))
            ->assertJsonPath('data.1.script', $this->files->sieve('after'))
            ->assertJsonPath('meta', ['total' => 2, 'limit' => 25, 'offset' => 0]);
        $single = $this->getJson(self::URI.'/postfilter')->assertOk();
        $single->assertJsonPath('data.etag', $response->json('data.1.etag'))->assertHeader('ETag', (string) $response->json('data.1.etag'));
        $this->getJson(self::URI.'?limit=1&offset=1')->assertOk()->assertJsonPath('data.0.id', 'postfilter');
    }

    public function test_a_valid_script_is_written_in_place_and_dovecot_restarted(): void
    {
        $this->asAdmin();
        $path = $this->files->sievePath('before');
        clearstatcache();
        $inode = fileinode($path);

        $response = $this->send('PUT', self::URI.'/prefilter', ['script' => self::VALID])->assertOk();

        $expected = str_replace("\r\n", "\n", self::VALID);
        $this->assertSame($expected, $this->files->sieve('before'));
        clearstatcache();
        $this->assertSame($inode, fileinode($path));
        $this->dockerApi()->assertRestarted('dovecot-mailcow', 1);
        $response->assertJsonPath('data.id', 'prefilter')
            ->assertJsonPath('data.script', $expected)
            ->assertJsonPath('meta.dovecotRestart', ['outcome' => 'done', 'detail' => null])
            ->assertHeader('ETag', (string) $response->json('data.etag'));

        $record = $this->successesOf('globalSieveFilterReplacement');
        $this->assertCount(1, $record);
        $this->assertSame(['globalSieveFilter', 'prefilter'], [$record[0]->target_type, $record[0]->target_id]);
        $this->assertSame('done', $record[0]->details['dovecotRestart']);
        $this->assertSame(strlen($expected), $record[0]->details['newBytes']);
    }

    public function test_a_lone_carriage_return_is_normalised_too(): void
    {
        $this->asAdmin();

        $this->send('PUT', self::URI.'/postfilter', ['script' => "# a\rkeep;\r"])->assertOk();

        $this->assertSame("# a\nkeep;\n", $this->files->sieve('after'));
    }

    public function test_a_syntax_error_is_refused_with_its_line_and_nothing_restarts(): void
    {
        $this->asAdmin();
        $before = $this->files->sieve('before');

        $response = $this->send('PUT', self::URI.'/prefilter', ['script' => "require \"fileinto\";\nif true {\n  fileinto \"Junk\"\n}\n"])->assertStatus(422);

        $response->assertJsonPath('errors.script.0.code', 'sieveSyntax');
        $this->assertStringStartsWith('line ', (string) $response->json('errors.script.0.params.message'));
        $this->assertIsInt($response->json('errors.script.0.params.line'));
        $this->assertSame($before, $this->files->sieve('before'));
        $this->dockerApi()->assertNoAction();
    }

    public function test_a_deferred_restart_writes_without_restarting(): void
    {
        $this->asAdmin();

        $this->send('PUT', self::URI.'/postfilter', ['script' => "keep;\n", 'deferRestart' => true])->assertOk()
            ->assertJsonPath('meta.dovecotRestart', ['outcome' => 'deferred', 'detail' => null]);

        $this->assertSame("keep;\n", $this->files->sieve('after'));
        $this->dockerApi()->assertNoAction();
        $this->assertSame('deferred', $this->successesOf('globalSieveFilterReplacement')[0]->details['dovecotRestart']);
    }

    public function test_a_failed_restart_keeps_the_script_and_is_reported(): void
    {
        $this->asAdmin();
        $this->dockerApi()->respondTo('restart', DockerCallResult::failed('container dovecot-mailcow is restarting'));

        $this->send('PUT', self::URI.'/prefilter', ['script' => "keep;\n"])->assertOk()
            ->assertJsonPath('meta.dovecotRestart.outcome', 'failed')
            ->assertJsonPath('meta.dovecotRestart.detail', 'container dovecot-mailcow is restarting');

        $this->assertSame("keep;\n", $this->files->sieve('before'));
        $details = $this->successesOf('globalSieveFilterReplacement')[0]->details;
        $this->assertSame(['failed', 'container dovecot-mailcow is restarting'], [$details['dovecotRestart'], $details['restartDetail']]);
    }

    public function test_a_restart_without_an_answer_is_failed_and_may_still_happen(): void
    {
        $this->asAdmin();
        $this->dockerApi()->failNext('restart', 'timeout');

        $detail = (string) $this->send('PUT', self::URI.'/prefilter', ['script' => "keep;\n"])->assertOk()
            ->assertJsonPath('meta.dovecotRestart.outcome', 'failed')
            ->json('meta.dovecotRestart.detail');

        $this->assertStringContainsString('may still happen', $detail);
    }

    public function test_an_empty_or_comment_only_script_is_accepted(): void
    {
        $this->asAdmin();

        $this->send('PUT', self::URI.'/prefilter', ['script' => ''])->assertOk()->assertJsonPath('data.script', '');
        $this->send('PUT', self::URI.'/postfilter', ['script' => "# nothing\n# at all\n"])->assertOk();

        $this->assertSame('', $this->files->sieve('before'));
    }

    public function test_a_missing_file_is_refused_without_a_restart(): void
    {
        $this->asAdmin();
        $this->files->removeSieve('after');

        $this->getJson(self::URI.'/postfilter')->assertOk()->assertJsonPath('data.script', '');
        $this->send('PUT', self::URI.'/postfilter', ['script' => "keep;\n"])->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/global-sieve-filter-unavailable');

        $this->assertFileDoesNotExist($this->files->sievePath('after'));
        $this->dockerApi()->assertNoAction();
        $this->assertSame(['fileUnavailable'], array_column($this->refusalsOf('globalSieveFilterReplacement'), 'failure_reason'));
    }

    public function test_validate_only_checks_without_writing_restarting_or_auditing(): void
    {
        $this->asAdmin();
        $before = $this->files->sieve('before');

        $this->send('PUT', self::URI.'/prefilter?validateOnly=true', ['script' => "keep;\n"])->assertOk()->assertExactJson(['data' => ['valid' => true]]);
        $this->send('PUT', self::URI.'/prefilter?validateOnly=true', ['script' => 'if true { keep }'])->assertStatus(422)->assertJsonPath('errors.script.0.code', 'sieveSyntax');

        $this->assertSame($before, $this->files->sieve('before'));
        $this->dockerApi()->assertNoAction();
        $this->assertSame([], $this->auditOf('globalSieveFilterReplacement'));
    }

    public function test_an_outdated_tag_and_an_oversized_script_are_refused(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson(self::URI.'/prefilter')->json('data.etag');
        $this->files->writeSieve('before', "# changed by legacy\n");

        $this->send('PUT', self::URI.'/prefilter', ['script' => "keep;\n"], ['If-Match' => $etag])->assertStatus(412);
        $this->send('PUT', self::URI.'/prefilter', ['script' => '#'.str_repeat('x', 1048576)])->assertStatus(422)
            ->assertJsonPath('errors.script.0.params', ['max' => 1048576, 'unit' => 'bytes']);

        $this->assertSame("# changed by legacy\n", $this->files->sieve('before'));
        $this->dockerApi()->assertNoAction();
    }

    public function test_a_failed_audit_record_writes_the_old_script_back(): void
    {
        $this->asAdmin();
        $before = $this->files->sieve('before');
        $this->app->instance(AuditLogger::class, new FlakyAuditLogger(failingTypes: [AuditEventType::GlobalSieveFilterReplacement]));

        $this->send('PUT', self::URI.'/prefilter', ['script' => "keep;\n", 'deferRestart' => true])->assertStatus(500);

        $this->assertSame($before, $this->files->sieve('before'));
    }

    public function test_other_roles_are_forbidden(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $this->getJson(self::URI)->assertForbidden();
        $this->send('PUT', self::URI.'/prefilter', ['script' => "keep;\n"])->assertForbidden();
        $this->dockerApi()->assertNoAction();
    }
}
