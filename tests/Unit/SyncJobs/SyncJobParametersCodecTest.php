<?php

namespace Tests\Unit\SyncJobs;

use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Models\Mailcow\SchemaVersion;
use App\Services\Config\MailcowConfig;
use App\Services\SyncJobs\CustomParametersValidator;
use App\Services\SyncJobs\SyncJobParametersCodec;
use Tests\TestCase;

/** The November Python runner receives one argv element per JSON pair, without shell splitting. */
class SyncJobParametersCodecTest extends TestCase
{
    private function codec(): SyncJobParametersCodec
    {
        return $this->app->make(SyncJobParametersCodec::class);
    }

    public function test_values_keep_spaces_hyphens_and_option_like_text_in_one_argument(): void
    {
        foreach (['INBOX +debug', 'a-b', 'Message-Id', 'SENTSINCE 1-Jan-2020'] as $value) {
            $encoded = $this->codec()->encode('--folder='.$value);
            $this->assertSame([['o' => 'folder', 'v' => $value]], json_decode($encoded, true));
            $this->assertSame('--folder='.$value, SyncJobParametersCodec::display($encoded));
        }
        $json = '[{"o":"folder","v":"INBOX --showpasswords"}]';
        $this->assertSame($json, $this->codec()->encode($json));
        $this->assertSame($json, SyncJobParametersCodec::display($json));
    }

    public function test_flags_required_values_and_pattern_values_are_checked(): void
    {
        foreach (['--nolog', '--maxsize=5', '--sslargs1=SSL_verify_mode=1'] as $value) {
            $this->assertSame([], $this->codec()->violations($value), $value);
        }
        foreach (['--nolog=true', '--maxsize', '--sslargs1=SSL_verify_mode=9'] as $value) {
            $this->assertNotEmpty($this->codec()->violations($value), $value);
        }
    }

    public function test_malformed_pairs_and_alternate_argument_prefixes_are_rejected(): void
    {
        foreach (['-folder=x', '+showpasswords', '--folder x', '[{"o":"folder","v":2}]',
            '[{"o":"folder","v":"x","extra":"y"}]', '[{"o":"--folder","v":"x"}]',
            '[{"o":"folder"}]', '{"folder":"x"}', '--folder='.str_repeat('x', 1001),
            json_encode([['o' => 'folder', 'v' => "a\0b"]])] as $value) {
            $this->assertNotEmpty($this->codec()->violations($value), $value);
        }
    }

    public function test_custom_rules_cannot_enable_unsafe_options_or_abbreviations(): void
    {
        config(['restv2.vars_overrides.IMAPSYNC_OPTIONS' => '{"folder":true,"showpasswords":false,"pipemess":true,"regexmess":true}']);
        $this->app->forgetInstance(MailcowConfig::class);
        $codec = $this->codec();
        $this->assertSame(['folder' => true], $codec->rules());
        foreach (['--showpasswords', '--showpass', '--pipemess=cat', '--pipeme=cat', '--regexmess=x'] as $value) {
            $this->assertNotEmpty($codec->violations($value), $value);
            $this->assertNotEmpty($codec->violations($value, false), 'Stored unsafe option: '.$value);
        }
    }

    public function test_an_unchanged_safe_option_can_outlive_configuration_but_must_remain_structurally_valid(): void
    {
        $this->assertSame([], $this->codec()->violations('--oldoption=value', false));
        $this->assertNotEmpty($this->codec()->violations('--oldoption=value'));
        $this->assertNotEmpty($this->codec()->violations('[{"o":"oldoption","v":true}]', false));
    }

    public function test_november_parameters_keep_administrator_restrictions_and_block_unsafe_unchanged_options(): void
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            $this->markTestSkipped('November validator integration.');
        }
        config(['restv2.sync_jobs.custom_params_admin_only' => true]);
        $validator = $this->app->make(CustomParametersValidator::class);
        foreach ([Role::MailboxUser, Role::DomainAdmin] as $role) {
            $actor = new Principal('user', $role, $role->accountKind(), AuthMethod::Password);
            $this->assertSame('customParamsNotPermitted', $validator->violations('--maxsize=5', null, $actor, true)[0]['code']);
            $this->assertSame([], $validator->violations('--maxsize=5', '--maxsize=5', $actor, false));
            $this->assertNotEmpty($validator->violations('--showpasswords', '--showpasswords', $actor, false));
        }
    }
}
