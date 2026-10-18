<?php

namespace Tests\Feature\SyncJobs;

use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Models\Mailcow\SyncJob;
use App\Services\SyncJobs\SyncJobScope;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature 008 T010 (research R2, FR-002): the scope of sync jobs is legacy's
 * hasMailboxObjectAccess() on the target mailbox: every job for an administrator, the jobs of
 * mailboxes in actively assigned domains for a domain administrator, the own mailbox's jobs for a
 * mailbox user. A job whose target mailbox no longer exists is invisible to everybody.
 */
class SyncJobScopeTest extends TestCase
{
    /** @var array<string, int> */
    private array $jobs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->mailboxWithDomain('alice@example.org');
        $f->mailboxWithDomain('bob@example.org');
        $f->mailboxWithDomain('carol@example.net');
        $f->mailboxWithDomain('dave@example.com');
        $f->resource('room@example.org', 'Room');
        $f->domainAdmin('reseller', ['example.org']);
        DB::table('domain_admins')->insert(['username' => 'reseller', 'domain' => 'example.net', 'active' => 0]);
        $f->domainAdmin('nobody', []);

        $this->jobs = [
            'alice' => $f->syncJob('alice@example.org'),
            'bob' => $f->syncJob('bob@example.org'),
            'carol' => $f->syncJob('carol@example.net'),
            'dave' => $f->syncJob('dave@example.com'),
            'room' => $f->syncJob('room@example.org'),
            'orphan' => $f->syncJob('gone@example.org'),
        ];
    }

    private static function principal(string $username, Role $role): Principal
    {
        return new Principal($username, $role, $role->accountKind(), AuthMethod::Password);
    }

    /**
     * @return list<int>
     */
    private function visible(Principal $principal): array
    {
        $scope = $this->app->make(SyncJobScope::class);

        return $scope->apply(SyncJob::query(), $principal)->orderBy('imapsync.id')->pluck('imapsync.id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    public function test_an_administrator_sees_every_job_except_the_orphan(): void
    {
        $expected = [$this->jobs['alice'], $this->jobs['bob'], $this->jobs['carol'], $this->jobs['dave'], $this->jobs['room']];

        $this->assertSame($expected, $this->visible(self::principal('admin', Role::Admin)));
    }

    public function test_a_domain_administrator_sees_only_the_actively_assigned_domains(): void
    {
        $this->assertSame(
            [$this->jobs['alice'], $this->jobs['bob'], $this->jobs['room']],
            $this->visible(self::principal('reseller', Role::DomainAdmin)),
        );
        $this->assertSame([], $this->visible(self::principal('nobody', Role::DomainAdmin)));
    }

    public function test_a_mailbox_user_sees_only_their_own_jobs(): void
    {
        $this->assertSame([$this->jobs['alice']], $this->visible(self::principal('alice@example.org', Role::MailboxUser)));
    }

    public function test_find_in_scope_answers_null_outside_the_scope(): void
    {
        $scope = $this->app->make(SyncJobScope::class);
        $reseller = self::principal('reseller', Role::DomainAdmin);

        $this->assertSame($this->jobs['alice'], $scope->findInScope($this->jobs['alice'], $reseller)?->id);
        $this->assertNull($scope->findInScope($this->jobs['carol'], $reseller));
        $this->assertNull($scope->findInScope($this->jobs['orphan'], self::principal('admin', Role::Admin)));
        $this->assertNull($scope->findInScope(999999, self::principal('admin', Role::Admin)));
        $this->assertNull($scope->findInScope($this->jobs['bob'], self::principal('alice@example.org', Role::MailboxUser)));
        $this->assertSame($this->jobs['bob'], DB::transaction(fn () => $scope->findInScopeForUpdate($this->jobs['bob'], $reseller)?->id));
    }
}
