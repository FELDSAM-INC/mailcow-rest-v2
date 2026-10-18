<?php

namespace Tests\Unit\SyncJobs;

use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Config\MailcowConfig;
use App\Services\SyncJobs\CustomParametersValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/legacy/syncjob_validation.php';

/**
 * Feature 008 T031 (research R7, FR-006, FR-019, SC-004): custom imapsync parameters are checked
 * exactly as legacy's mailbox('add', 'syncjob') checks them, against the upstream allow-list, with
 * one hardening: `showpasswords` is always refused. Every bad option is reported, in order and
 * once. The server setting that restricts custom parameters to administrators refuses any changed
 * value of another role with `customParamsNotPermitted`.
 */
class CustomParametersValidatorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function corpus(): array
    {
        $values = [
            '', ' ', '0', '--maxsize=1000', '--maxsize=1000 --nofoldersizesatend', '--MaxSize=1', 'maxsize=5',
            '--maxsize 1000', '--maxsize =5', '--folder=a-b', '--=5', '-0', ' --maxsize=1', '--maxsize=1 ',
            '--showpasswords', '--SHOWPASSWORDS', '--showpasswords=1', '--maxsize=1 --showpasswords',
            '--skipmess=x', '--pipemess=cat', '--regexmess=s/a/b/', '--regexflag=s/a/b/', '--regextrans2=s/a/b/',
            '--maxlinelengthcmd=x', '--delete2foldersonly=x', '--delete2foldersbutnot=x', '--bogus',
            '--bogus=1 --maxsize 5', '--debug', '--dry', '--justlogin', '--minage=1 --maxage=2', '--include=INBOX',
            '--folder=INBOX --folder=Sent', '--useheader=Message-Id', '--exclude=Spam', '--sep1=/', '--prefix1=INBOX.',
            '---maxsize=1', '--maxsize=1--minage=2', '--nofoldersizesatend --nolog', 'nolog', '--NoLog',
            '--tls2 --ssl2', '--authmech1=PLAIN', '-- maxsize', '--max size=1', "--maxsize\t1", '--0', '--00',
            '--bogus --bogus', '--subfolder1=Archive', '--search=SENTSINCE 1-Jan-2020',
        ];

        return array_combine(array_map(fn (string $v): string => json_encode($v) ?: $v, $values), array_map(fn (string $v): array => [$v], $values));
    }

    private static function admin(): Principal
    {
        return new Principal('admin', Role::Admin, Role::Admin->accountKind(), AuthMethod::Password);
    }

    private static function user(): Principal
    {
        return new Principal('alice@example.org', Role::MailboxUser, Role::MailboxUser->accountKind(), AuthMethod::Password);
    }

    private static function domainAdmin(): Principal
    {
        return new Principal('reseller', Role::DomainAdmin, Role::DomainAdmin->accountKind(), AuthMethod::Password);
    }

    private function validator(): CustomParametersValidator
    {
        return $this->app->make(CustomParametersValidator::class);
    }

    #[DataProvider('corpus')]
    public function test_the_decision_equals_legacy_except_that_showpasswords_is_refused(string $value): void
    {
        $whitelist = $this->app->make(MailcowConfig::class)->imapsyncOptions()['whitelist'];
        // The add path trims the value before its check.
        $legacy = legacy_syncjob_custom_params_ok(empty(trim($value)) ? '' : trim($value), $whitelist);
        $showsPasswords = preg_match('/(^|-)showpasswords(=|\s|$)/i', trim($value)) === 1;

        $violations = $this->validator()->violations($value, null, self::admin(), true);

        $this->assertSame($legacy && ! $showsPasswords, $violations === [], json_encode($violations) ?: '');

        foreach ($violations as $violation) {
            $this->assertContains($violation['code'], ['optionNotAllowed', 'optionContainsSpace']);
        }
    }

    public function test_every_bad_option_is_named_in_order_and_once(): void
    {
        $codes = fn (string $value): array => array_map(
            fn (array $v): array => [$v['code'], $v['params']],
            $this->validator()->violations($value, null, self::admin(), true),
        );

        $this->assertSame([['optionNotAllowed', ['option' => 'bogus']], ['optionContainsSpace', ['token' => 'maxsize 5']]], $codes('--bogus=1 --maxsize 5'));
        $this->assertSame([['optionNotAllowed', ['option' => 'bogus']]], $codes('--bogus --bogus'));
        $this->assertSame([['optionNotAllowed', ['option' => 'showpasswords']]], $codes('--showpasswords'));
        $this->assertSame([['optionNotAllowed', ['option' => 'SHOWPASSWORDS']]], $codes('--SHOWPASSWORDS'));
        $this->assertSame([['optionNotAllowed', ['option' => '']]], $codes('--=5'));
        $this->assertSame([['optionNotAllowed', ['option' => 'b']]], $codes('--folder=a-b'));
        $this->assertSame([], $codes('--maxsize=1 --minage=2'));
    }

    public function test_showpasswords_is_refused_even_when_the_allow_list_contains_it(): void
    {
        config(['restv2.vars_overrides.IMAPSYNC_OPTIONS' => '{"whitelist":["maxsize","ShowPasswords"],"blacklist":[]}']);
        $this->app->forgetInstance(MailcowConfig::class);

        $this->assertSame([], $this->validator()->violations('--maxsize=1', null, self::admin(), true));
        $this->assertSame('optionNotAllowed', $this->validator()->violations('--showpasswords', null, self::admin(), true)[0]['code'] ?? null);
        $this->assertSame(['maxsize'], $this->validator()->allowedOptions());
        $this->assertSame('optionNotAllowed', $this->validator()->violations('--minage=1', null, self::admin(), true)[0]['code'] ?? null);
    }

    public function test_the_restriction_refuses_other_roles_any_value_at_creation(): void
    {
        config(['restv2.sync_jobs.custom_params_admin_only' => true]);

        foreach ([self::user(), self::domainAdmin()] as $caller) {
            $this->assertSame([['customParamsNotPermitted', []]], array_map(
                fn (array $v): array => [$v['code'], $v['params']],
                $this->validator()->violations('--maxsize=1', null, $caller, true),
            ));
            $this->assertSame([], $this->validator()->violations('', null, $caller, true));
            $this->assertSame([], $this->validator()->violations('  ', null, $caller, true));
        }

        $this->assertSame([], $this->validator()->violations('--maxsize=1', null, self::admin(), true));
        $this->assertSame('optionNotAllowed', $this->validator()->violations('--bogus', null, self::admin(), true)[0]['code'] ?? null);
        $this->assertFalse($this->validator()->allowedFor(self::user()));
        $this->assertTrue($this->validator()->allowedFor(self::admin()));
    }

    public function test_on_edit_an_unchanged_value_is_accepted_and_a_changed_one_is_refused_under_the_restriction(): void
    {
        config(['restv2.sync_jobs.custom_params_admin_only' => true]);
        $stored = '--maxsize=1000';

        $this->assertSame([], $this->validator()->violations('--maxsize=1000', $stored, self::user(), false));
        $this->assertSame([], $this->validator()->violations(' --maxsize=1000 ', $stored, self::user(), false));
        $this->assertSame('customParamsNotPermitted', $this->validator()->violations('--maxsize=2000', $stored, self::user(), false)[0]['code'] ?? null);
        $this->assertSame('customParamsNotPermitted', $this->validator()->violations('', $stored, self::user(), false)[0]['code'] ?? null);
        $this->assertSame([], $this->validator()->violations('--maxsize=2000', $stored, self::admin(), false));
    }

    public function test_on_edit_an_unchanged_value_is_accepted_even_if_it_predates_the_allow_list(): void
    {
        $this->assertSame([], $this->validator()->violations('--removed-option', '--removed-option', self::user(), false));
        $this->assertSame('optionNotAllowed', $this->validator()->violations('--removed-option --dry', '--removed-option', self::user(), false)[0]['code'] ?? null);
        $this->assertTrue($this->validator()->allowedFor(self::user()));
    }
}
