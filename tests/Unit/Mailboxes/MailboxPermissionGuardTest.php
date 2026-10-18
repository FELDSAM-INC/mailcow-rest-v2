<?php

namespace Tests\Unit\Mailboxes;

use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Services\Mailboxes\MailboxPermissionGuard;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature 003 T014 (FR-006, FR-035, research R8): a domain administrator may choose a gated mailbox
 * setting only with its permission flag; a value equal to the baseline is no choice, except an
 * unlimited quota, which is one unless the mailbox already has it. Every refused member is named
 * with its flag; administrators are never refused.
 */
class MailboxPermissionGuardTest extends TestCase
{
    private const BASELINE = [
        'quota' => 1073741824,
        'webmailRedirect' => true,
        'rateLimit' => null,
        'protocolAccess' => ['imap' => true, 'pop3' => true, 'smtp' => true, 'sieve' => true, 'activeSync' => true, 'dav' => true],
        'userPermissions' => ['syncjobs' => false, 'quarantine' => true, 'appPasswds' => true, 'pushover' => true, 'spamPolicy' => true, 'spamScore' => true],
    ];

    private const FLAGS = ['unlimited_quota', 'protocol_access', 'sogo_access', 'ratelimit', 'syncjobs', 'quarantine', 'app_passwds', 'pushover', 'spam_policy'];

    /**
     * A domain administrator holding every flag except the given ones.
     */
    private function domainAdmin(string ...$lacking): Principal
    {
        $username = 'da'.substr(md5(implode(',', $lacking)), 0, 8);
        $this->mailcow()->ensureDomain('example.org');

        if (! DB::table('admin')->where('username', $username)->exists()) {
            $this->mailcow()->domainAdmin($username, ['example.org'], array_fill_keys(array_diff(self::FLAGS, $lacking), 1) + array_fill_keys($lacking, 0));
        }

        return new Principal($username, Role::DomainAdmin, AccountKind::Admin, AuthMethod::Password);
    }

    /**
     * @param  array<string, mixed>  $members
     * @param  array<string, mixed>  $baseline
     * @return array{attributes: list<string>, requiredFlags: array<string, string>}|null
     */
    private function refusal(Principal $principal, array $members, array $baseline = self::BASELINE, bool $creation = false): ?array
    {
        try {
            $this->app->make(MailboxPermissionGuard::class)->check($principal, $members, $baseline, $creation);

            return null;
        } catch (ProblemException $e) {
            $this->assertSame(ProblemType::AttributeNotPermitted, $e->type);

            return ['attributes' => $e->extensions['attributes'], 'requiredFlags' => $e->extensions['requiredFlags']];
        }
    }

    public function test_values_equal_to_the_baseline_are_accepted(): void
    {
        $principal = $this->domainAdmin(...self::FLAGS);

        $this->assertNull($this->refusal($principal, [
            'quota' => 1073741824,
            'webmailRedirect' => true,
            'protocolAccess' => ['pop3' => true],
            'userPermissions' => ['syncjobs' => false, 'spamScore' => false],
        ]));
    }

    public function test_each_gated_member_needs_its_flag(): void
    {
        $cases = [
            ['protocol_access', ['protocolAccess' => ['pop3' => false]], 'protocolAccess.pop3', 'protocolAccess', false],
            ['sogo_access', ['webmailRedirect' => false], 'webmailRedirect', 'sogoAccess', false],
            ['ratelimit', ['rateLimit' => ['value' => 10, 'frame' => 'hour']], 'rateLimit', 'ratelimit', true],
            ['syncjobs', ['userPermissions' => ['syncjobs' => true]], 'userPermissions.syncjobs', 'syncjobs', false],
            ['quarantine', ['userPermissions' => ['quarantine' => false]], 'userPermissions.quarantine', 'quarantine', false],
            ['app_passwds', ['userPermissions' => ['appPasswds' => false]], 'userPermissions.appPasswds', 'appPasswds', false],
            ['pushover', ['userPermissions' => ['pushover' => false]], 'userPermissions.pushover', 'pushover', false],
            ['spam_policy', ['userPermissions' => ['spamPolicy' => false]], 'userPermissions.spamPolicy', 'spamPolicy', false],
        ];

        foreach ($cases as [$flag, $members, $path, $required, $creation]) {
            $this->assertSame(
                ['attributes' => [$path], 'requiredFlags' => [$path => $required]],
                $this->refusal($this->domainAdmin($flag), $members, self::BASELINE, $creation),
                $flag,
            );
            $this->assertNull($this->refusal($this->domainAdmin(), $members, self::BASELINE, $creation), "{$flag} granted");
        }
    }

    public function test_an_unlimited_quota_needs_the_flag_unless_the_mailbox_has_it(): void
    {
        $principal = $this->domainAdmin('unlimited_quota');

        $this->assertSame(['attributes' => ['quota'], 'requiredFlags' => ['quota' => 'unlimitedQuota']], $this->refusal($principal, ['quota' => 0]));
        $this->assertNull($this->refusal($principal, ['quota' => 0], ['quota' => 0] + self::BASELINE));
        $this->assertNull($this->refusal($principal, ['quota' => 2147483648]));
    }

    public function test_an_unlimited_quota_from_the_template_needs_the_flag_at_creation(): void
    {
        $principal = $this->domainAdmin('unlimited_quota');

        $this->assertSame(['attributes' => ['quota'], 'requiredFlags' => ['quota' => 'unlimitedQuota']], $this->refusal($principal, [], ['quota' => 0] + self::BASELINE, true));
        $this->assertNull($this->refusal($principal, ['quota' => 1048576], ['quota' => 0] + self::BASELINE, true));
    }

    public function test_every_refusal_is_reported_together(): void
    {
        $refusal = $this->refusal($this->domainAdmin('protocol_access', 'unlimited_quota', 'syncjobs'), [
            'quota' => 0,
            'protocolAccess' => ['pop3' => false, 'imap' => false, 'dav' => true],
            'userPermissions' => ['syncjobs' => true],
        ]);

        $this->assertNotNull($refusal);
        $this->assertSame(['protocolAccess.imap', 'protocolAccess.pop3', 'quota', 'userPermissions.syncjobs'], $refusal['attributes']);
        $this->assertSame([
            'protocolAccess.imap' => 'protocolAccess',
            'protocolAccess.pop3' => 'protocolAccess',
            'quota' => 'unlimitedQuota',
            'userPermissions.syncjobs' => 'syncjobs',
        ], $refusal['requiredFlags']);
    }

    public function test_an_administrator_is_never_refused(): void
    {
        $admin = new Principal('admin', Role::Admin, AccountKind::Admin, AuthMethod::Password);

        $this->assertNull($this->refusal($admin, ['quota' => 0, 'protocolAccess' => ['pop3' => false], 'webmailRedirect' => false, 'userPermissions' => ['syncjobs' => true]]));
    }
}
