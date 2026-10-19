<?php

namespace Tests\Unit\Quarantine;

use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Policies\QuarantinePolicy;
use App\Policies\RateLimitPolicy;
use App\Policies\SpamPolicyPolicy;
use App\Support\Http\ProblemType;
use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature 006 T016 (research R15, FR-001 to FR-006, FR-069): who may use which operation of the
 * quarantine, the spam policy and the rate limits, from mailcow's current flags. Role refusals are
 * `forbidden`; a missing flag is `permission-flag-required` naming it as in `PermissionFlags`; a
 * mailbox user's notification change without its flag is `attribute-not-permitted`.
 */
class SpamFilterAccessTest extends TestCase
{
    private function admin(): Principal
    {
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');

        return new Principal('admin', Role::Admin, Role::Admin->accountKind(), AuthMethod::Password);
    }

    /**
     * @param  array<string, int>  $daAcl
     */
    private function domainAdmin(array $daAcl = []): Principal
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->domainAdmin('reseller', ['example.org'], $daAcl, scheme: 'SSHA256');

        return new Principal('reseller', Role::DomainAdmin, Role::DomainAdmin->accountKind(), AuthMethod::Password);
    }

    /**
     * @param  array<string, int>  $userAcl
     */
    private function mailboxUser(array $userAcl = []): Principal
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->mailbox('alice@example.org', scheme: 'SSHA256', userAcl: $userAcl);

        return new Principal('alice@example.org', Role::MailboxUser, Role::MailboxUser->accountKind(), AuthMethod::Password);
    }

    private function refusal(Closure $check): ?ProblemException
    {
        try {
            $check();
        } catch (ProblemException $e) {
            return $e;
        }

        return null;
    }

    /**
     * @param  list<string>  $flags
     */
    private function assertFlagRequired(array $flags, Closure $check): void
    {
        $e = $this->refusal($check);
        $this->assertNotNull($e, 'Expected permission-flag-required.');
        $this->assertSame(ProblemType::PermissionFlagRequired, $e->type);
        $this->assertSame($flags, $e->extensions['requiredFlags']);
    }

    private function assertForbidden(Closure $check): void
    {
        $e = $this->refusal($check);
        $this->assertNotNull($e, 'Expected forbidden.');
        $this->assertSame(ProblemType::Forbidden, $e->type);
    }

    private function assertAllowed(Closure $check): void
    {
        $e = $this->refusal($check);
        $this->assertNull($e, 'Unexpected refusal: '.$e?->type->value);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function everyAbility(): array
    {
        $abilities = [];

        foreach ([QuarantinePolicy::class => QuarantinePolicy::ABILITIES, SpamPolicyPolicy::class => SpamPolicyPolicy::ABILITIES, RateLimitPolicy::class => RateLimitPolicy::ABILITIES] as $policy => $list) {
            foreach ($list as $ability) {
                $abilities[class_basename($policy).' '.$ability] = [$policy, $ability];
            }
        }

        return $abilities;
    }

    #[DataProvider('everyAbility')]
    public function test_an_admin_holds_every_ability(string $policy, string $ability): void
    {
        $admin = $this->admin();
        /** @var QuarantinePolicy|SpamPolicyPolicy|RateLimitPolicy $instance */
        $instance = $this->app->make($policy);

        $this->assertAllowed(fn () => $instance->authorize($admin, $ability));
    }

    public function test_a_domain_admin_uses_its_own_quarantine_spam_policy_and_ratelimit_flags(): void
    {
        $da = $this->domainAdmin(['quarantine' => 0, 'spam_policy' => 0, 'ratelimit' => 0]);
        $q = $this->app->make(QuarantinePolicy::class);
        $s = $this->app->make(SpamPolicyPolicy::class);
        $r = $this->app->make(RateLimitPolicy::class);

        $this->assertAllowed(fn () => $q->authorize($da, QuarantinePolicy::VIEW_ITEMS));
        $this->assertAllowed(fn () => $q->authorize($da, QuarantinePolicy::VIEW_ATTACHMENTS));
        $this->assertFlagRequired(['quarantine'], fn () => $q->authorize($da, QuarantinePolicy::ACT));
        $this->assertFlagRequired(['quarantine'], fn () => $q->authorize($da, QuarantinePolicy::DOWNLOAD_MESSAGE));
        $this->assertAllowed(fn () => $s->authorize($da, SpamPolicyPolicy::VIEW_DOMAIN_ENTRIES));
        $this->assertFlagRequired(['spamPolicy'], fn () => $s->authorize($da, SpamPolicyPolicy::CHANGE_DOMAIN_ENTRIES));
        $this->assertFlagRequired(['spamPolicy'], fn () => $s->authorize($da, SpamPolicyPolicy::CHANGE_MAILBOX_ENTRIES));
        $this->assertAllowed(fn () => $r->authorize($da, RateLimitPolicy::VIEW));
        $this->assertFlagRequired(['ratelimit'], fn () => $r->authorize($da, RateLimitPolicy::CHANGE));
    }

    public function test_a_domain_admin_with_the_flags_may_act(): void
    {
        $da = $this->domainAdmin(['quarantine' => 1, 'spam_policy' => 1, 'ratelimit' => 1]);

        $this->assertAllowed(fn () => $this->app->make(QuarantinePolicy::class)->authorize($da, QuarantinePolicy::ACT));
        $this->assertAllowed(fn () => $this->app->make(QuarantinePolicy::class)->authorize($da, QuarantinePolicy::DOWNLOAD_MESSAGE));
        $this->assertAllowed(fn () => $this->app->make(SpamPolicyPolicy::class)->authorize($da, SpamPolicyPolicy::CHANGE_DOMAIN_ENTRIES));
        $this->assertAllowed(fn () => $this->app->make(RateLimitPolicy::class)->authorize($da, RateLimitPolicy::CHANGE));
    }

    public function test_a_domain_admin_always_holds_the_user_only_flags(): void
    {
        $da = $this->domainAdmin(['quarantine' => 0]);
        $q = $this->app->make(QuarantinePolicy::class);

        $this->assertTrue($q->allows($da, QuarantinePolicy::VIEW_ATTACHMENTS));
        $this->assertAllowed(fn () => $q->authorizeNotificationChange($da, ['frequency', 'category']));
        $this->assertAllowed(fn () => $this->app->make(SpamPolicyPolicy::class)->authorize($da, SpamPolicyPolicy::CHANGE_SCORE));
    }

    public function test_a_mailbox_user_uses_its_user_acl_row(): void
    {
        $user = $this->mailboxUser(['quarantine' => 0, 'quarantine_attachments' => 0, 'spam_score' => 0, 'spam_policy' => 0]);
        $q = $this->app->make(QuarantinePolicy::class);
        $s = $this->app->make(SpamPolicyPolicy::class);

        $this->assertAllowed(fn () => $q->authorize($user, QuarantinePolicy::VIEW_ITEMS));
        $this->assertFlagRequired(['quarantine'], fn () => $q->authorize($user, QuarantinePolicy::ACT));
        $this->assertFlagRequired(['quarantineAttachments'], fn () => $q->authorize($user, QuarantinePolicy::VIEW_ATTACHMENTS));
        $this->assertFlagRequired(['spamScore'], fn () => $s->authorize($user, SpamPolicyPolicy::CHANGE_SCORE));
        $this->assertFlagRequired(['spamPolicy'], fn () => $s->authorize($user, SpamPolicyPolicy::CHANGE_MAILBOX_ENTRIES));
        $this->assertAllowed(fn () => $s->authorize($user, SpamPolicyPolicy::VIEW_SCORE));
        $this->assertAllowed(fn () => $s->authorize($user, SpamPolicyPolicy::VIEW_MAILBOX_ENTRIES));
    }

    public function test_the_whole_message_download_names_the_first_missing_flag(): void
    {
        $q = $this->app->make(QuarantinePolicy::class);
        $none = $this->mailboxUser(['quarantine' => 0, 'quarantine_attachments' => 0]);

        $this->assertFlagRequired(['quarantine'], fn () => $q->authorize($none, QuarantinePolicy::DOWNLOAD_MESSAGE));

        DB::table('user_acl')->where('username', 'alice@example.org')->update(['quarantine' => 1]);
        $this->assertFlagRequired(['quarantineAttachments'], fn () => $q->authorize($none, QuarantinePolicy::DOWNLOAD_MESSAGE));

        DB::table('user_acl')->where('username', 'alice@example.org')->update(['quarantine_attachments' => 1]);
        $this->assertAllowed(fn () => $q->authorize($none, QuarantinePolicy::DOWNLOAD_MESSAGE));
    }

    public function test_a_mailbox_users_notification_change_names_the_attributes_and_flags(): void
    {
        $user = $this->mailboxUser(['quarantine_notification' => 1, 'quarantine_category' => 0]);
        $q = $this->app->make(QuarantinePolicy::class);

        $this->assertAllowed(fn () => $q->authorizeNotificationChange($user, ['frequency']));
        $this->assertAllowed(fn () => $q->authorizeNotificationChange($user, []));
        $e = $this->refusal(fn () => $q->authorizeNotificationChange($user, ['frequency', 'category']));
        $this->assertNotNull($e);
        $this->assertSame(ProblemType::AttributeNotPermitted, $e->type);
        $this->assertSame(['category'], $e->extensions['attributes']);
        $this->assertSame(['category' => 'quarantineCategory'], $e->extensions['requiredFlags']);
    }

    public function test_role_refusals_are_forbidden_not_flag_refusals(): void
    {
        $user = $this->mailboxUser(['quarantine' => 1, 'spam_policy' => 1]);
        $da = $this->domainAdmin(['quarantine' => 1, 'ratelimit' => 1]);
        $q = $this->app->make(QuarantinePolicy::class);
        $s = $this->app->make(SpamPolicyPolicy::class);
        $r = $this->app->make(RateLimitPolicy::class);

        foreach ([RateLimitPolicy::VIEW, RateLimitPolicy::CHANGE, RateLimitPolicy::RESET_BUCKET] as $ability) {
            $this->assertForbidden(fn () => $r->authorize($user, $ability));
        }

        $this->assertForbidden(fn () => $r->authorize($da, RateLimitPolicy::RESET_BUCKET));
        $this->assertForbidden(fn () => $q->authorize($da, QuarantinePolicy::CHANGE_SETTINGS));
        $this->assertForbidden(fn () => $q->authorize($user, QuarantinePolicy::CHANGE_SETTINGS));
        $this->assertForbidden(fn () => $q->authorize($user, QuarantinePolicy::PREVIEW_TEMPLATE));
        $this->assertForbidden(fn () => $q->authorize($user, QuarantinePolicy::BULK_NOTIFICATIONS));
        $this->assertForbidden(fn () => $s->authorize($user, SpamPolicyPolicy::VIEW_DOMAIN_ENTRIES));
        $this->assertForbidden(fn () => $s->authorize($user, SpamPolicyPolicy::CHANGE_DOMAIN_ENTRIES));
        $this->assertForbidden(fn () => $s->authorize($da, SpamPolicyPolicy::VIEW_STATISTICS));
        $this->assertAllowed(fn () => $q->authorize($da, QuarantinePolicy::BULK_NOTIFICATIONS));
        $this->assertAllowed(fn () => $q->authorize($user, QuarantinePolicy::VIEW_SETTINGS));
    }

    public function test_flags_are_read_from_mailcows_current_data(): void
    {
        $da = $this->domainAdmin(['quarantine' => 1]);
        $q = $this->app->make(QuarantinePolicy::class);
        $this->assertTrue($q->allows($da, QuarantinePolicy::ACT));

        DB::table('da_acl')->where('username', 'reseller')->update(['quarantine' => 0]);

        $this->assertFalse($q->allows($da, QuarantinePolicy::ACT));
    }
}
