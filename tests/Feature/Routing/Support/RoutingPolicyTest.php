<?php

namespace Tests\Feature\Routing\Support;

use App\Auth\AccountKind;
use App\Auth\ApiKeyReference;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\PermissionFlagRequiredException;
use App\Exceptions\ProblemException;
use App\Policies\RoutingPolicy;
use App\Services\Routing\BccScope;
use App\Support\Http\ProblemType;
use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature 009 T015 (FR-001–FR-006, FR-029): the role and flag decisions of the FR-005 table, in the
 * order role (`403 forbidden`), flag (`403 permission-flag-required` naming the flag), and the BCC
 * scope of a domain administrator: their domains (active assignment, existing domain) and those
 * domains' alias domains, evaluated from the current data on every call.
 */
class RoutingPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('example.com');
        $f->domain('foreign.example');
        $f->aliasDomain('example.net', 'example.org');
        $f->aliasDomain('foreign-alias.example', 'foreign.example');
        $f->domainAdmin('none', ['example.org', 'example.com'], ['domain_relayhost' => 0, 'mailbox_relayhost' => 0, 'bcc_maps' => 0]);
        $f->domainAdmin('domainflag', ['example.org'], ['domain_relayhost' => 1, 'mailbox_relayhost' => 0, 'bcc_maps' => 0]);
        $f->domainAdmin('mailboxflag', ['example.org'], ['domain_relayhost' => 0, 'mailbox_relayhost' => 1, 'bcc_maps' => 0]);
        $f->domainAdmin('bccflag', ['example.org'], ['domain_relayhost' => 0, 'mailbox_relayhost' => 0, 'bcc_maps' => 1]);
    }

    /**
     * ability => expected decision for admin, domain admin without flags, with domain_relayhost,
     * with mailbox_relayhost, with bcc_maps, and a mailbox user. `ok`, `forbidden` or a list of
     * required flags (and their match).
     *
     * @return array<string, array{string, array<string, string|array{list<string>, string}>}>
     */
    public static function decisions(): array
    {
        $flag = fn (array $flags, string $match = 'all'): array => [$flags, $match];
        $adminOnly = ['admin' => 'ok', 'none' => 'forbidden', 'domainflag' => 'forbidden', 'mailboxflag' => 'forbidden', 'bccflag' => 'forbidden', 'mailbox' => 'forbidden'];
        $both = ['admin' => 'ok', 'none' => 'ok', 'domainflag' => 'ok', 'mailboxflag' => 'ok', 'bccflag' => 'ok', 'mailbox' => 'forbidden'];

        return [
            'manage relayhosts, transports, tests, recipient and TLS policy maps, forwarding hosts' => ['manageRouting', $adminOnly],
            'relayhost choices' => ['viewRelayhostChoices', ['admin' => 'ok', 'none' => $flag(['domainRelayhost', 'mailboxRelayhost'], 'any'), 'domainflag' => 'ok', 'mailboxflag' => 'ok', 'bccflag' => $flag(['domainRelayhost', 'mailboxRelayhost'], 'any'), 'mailbox' => 'forbidden']],
            'read assignments' => ['viewRelayhostAssignment', $both],
            'change a domain assignment' => ['changeDomainRelayhostAssignment', ['admin' => 'ok', 'none' => $flag(['domainRelayhost']), 'domainflag' => 'ok', 'mailboxflag' => $flag(['domainRelayhost']), 'bccflag' => $flag(['domainRelayhost']), 'mailbox' => 'forbidden']],
            'change a mailbox assignment' => ['changeMailboxRelayhostAssignment', ['admin' => 'ok', 'none' => $flag(['mailboxRelayhost']), 'domainflag' => $flag(['mailboxRelayhost']), 'mailboxflag' => 'ok', 'bccflag' => $flag(['mailboxRelayhost']), 'mailbox' => 'forbidden']],
            'read BCC maps and choices' => ['viewBccMaps', $both],
            'write BCC maps' => ['changeBccMaps', ['admin' => 'ok', 'none' => $flag(['bccMaps']), 'domainflag' => $flag(['bccMaps']), 'mailboxflag' => $flag(['bccMaps']), 'bccflag' => 'ok', 'mailbox' => 'forbidden']],
            'read the routing capabilities' => ['viewRoutingCapabilities', $both],
        ];
    }

    /**
     * @param  array<string, string|array{list<string>, string}>  $expected
     */
    #[DataProvider('decisions')]
    public function test_the_permission_matrix(string $ability, array $expected): void
    {
        foreach ($expected as $caller => $decision) {
            $principal = match ($caller) {
                'admin' => self::principal('admin', Role::Admin),
                'mailbox' => self::principal('user@example.org', Role::MailboxUser),
                default => self::principal($caller, Role::DomainAdmin),
            };

            $this->assertDecision($decision, fn () => app(RoutingPolicy::class)->authorize($principal, $ability), "{$ability} as {$caller}");
        }
    }

    public function test_a_key_principal_acts_as_its_owner(): void
    {
        $key = new ApiKeyReference('01HZZZZZZZZZZZZZZZZZZZZZZZ', 'k', 'mcv2_abcdefg', 'readWrite');
        $owner = new Principal('domainflag', Role::DomainAdmin, AccountKind::Admin, AuthMethod::ApiKey, null, $key);

        app(RoutingPolicy::class)->authorize($owner, 'changeDomainRelayhostAssignment');
        $this->assertDecision([['bccMaps'], 'all'], fn () => app(RoutingPolicy::class)->authorize($owner, 'changeBccMaps'), 'key');
    }

    public function test_a_flag_change_applies_to_the_next_call(): void
    {
        $principal = self::principal('none', Role::DomainAdmin);
        $this->assertDecision([['bccMaps'], 'all'], fn () => app(RoutingPolicy::class)->authorize($principal, 'changeBccMaps'), 'before');

        DB::table('da_acl')->where('username', 'none')->update(['bcc_maps' => 1]);

        app(RoutingPolicy::class)->authorize($principal, 'changeBccMaps');
        $this->addToAssertionCount(1);
    }

    public function test_the_bcc_scope_of_a_domain_administrator(): void
    {
        $scope = app(BccScope::class);
        $none = self::principal('none', Role::DomainAdmin);

        $this->assertSame(['example.com', 'example.net', 'example.org'], $scope->domains($none));
        $this->assertTrue($scope->contains($none, 'example.net'));
        $this->assertFalse($scope->contains($none, 'foreign.example'));
        $this->assertFalse($scope->contains($none, 'foreign-alias.example'));
        $this->assertFalse($scope->contains($none, 'gone.example'));

        $f = $this->mailcow();
        $ids = [
            $f->bccMap('@example.org', 'a@backup.net', 'example.org'),
            $f->bccMap('@example.net', 'a@backup.net', 'example.net'),
            $f->bccMap('x@example.com', 'a@backup.net', 'example.com', 'sender'),
            $f->bccMap('@foreign.example', 'a@backup.net', 'foreign.example'),
            $f->bccMap('@foreign-alias.example', 'a@backup.net', 'foreign-alias.example'),
            $f->bccMap('@gone.example', 'a@backup.net', 'gone.example'),
        ];

        $visible = fn (Principal $p): array => $scope->restrict(DB::table('bcc_maps'), $p)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $this->assertSame(array_slice($ids, 0, 3), $visible($none));
        $this->assertSame($ids, $visible(self::principal('admin', Role::Admin)));
        $this->assertSame([], $visible(self::principal('user@example.org', Role::MailboxUser)));
    }

    public function test_an_inactive_assignment_removes_the_scope_on_the_next_call(): void
    {
        $scope = app(BccScope::class);
        $none = self::principal('none', Role::DomainAdmin);
        $this->assertTrue($scope->contains($none, 'example.com'));

        DB::table('domain_admins')->where('username', 'none')->where('domain', 'example.com')->update(['active' => 0]);

        $this->assertFalse($scope->contains($none, 'example.com'));
        $this->assertSame(['example.net', 'example.org'], $scope->domains($none));
    }

    /**
     * @param  string|array{list<string>, string}  $decision
     */
    private function assertDecision(string|array $decision, Closure $call, string $label): void
    {
        try {
            $call();

            $this->assertSame('ok', $decision, "{$label}: allowed, expected a refusal");
        } catch (PermissionFlagRequiredException $e) {
            $this->assertIsArray($decision, "{$label}: flag refusal, expected {$this->describe($decision)}");
            $this->assertSame(['requiredFlags' => $decision[0], 'requiredFlagsMatch' => $decision[1]], $e->extensions, $label);
        } catch (ProblemException $e) {
            $this->assertSame('forbidden', $decision, "{$label}: {$e->type->value}");
            $this->assertSame(ProblemType::Forbidden, $e->type, $label);
        }
    }

    /**
     * @param  string|array{list<string>, string}  $decision
     */
    private function describe(string|array $decision): string
    {
        return is_string($decision) ? $decision : implode(',', $decision[0]);
    }

    private static function principal(string $username, Role $role): Principal
    {
        return new Principal($username, $role, $role === Role::MailboxUser ? AccountKind::Mailbox : AccountKind::Admin, AuthMethod::Password);
    }
}
