<?php

namespace Tests\Feature\Groupware;

use App\Services\Groupware\SogoDirectory;
use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T083 (research R9, SC-002): after every alias, resource and external sender address
 * change, the incremental groupware updates leave `_sogo_static_view` exactly as legacy's full
 * rebuild (002's SogoDirectory::rebuild()) would on the same data, apart from `c_password`. This
 * covers the plan-level deviation of refreshing the affected rows instead of rebuilding when a
 * destination is external.
 */
class AliasGroupwareRefreshTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('example.com');
        $f->aliasDomain('example.net', 'example.org');
        $this->mailbox('anna@example.org', name: 'Anna');
        $this->mailbox('bob@example.org');
        $this->mailbox('carl@example.com');
        $this->mailbox('dora@example.org', 0);
        $f->alias('team@example.org', 'anna@example.org,bob@example.org');
        $f->alias('hidden@example.org', 'anna@example.org', sogoVisible: 0);
        $f->resource('Room@example.org', 'Room');
        $f->senderAcl('bob@example.org', 'x@mailer.example', 1);
        $this->app->make(SogoDirectory::class)->rebuild();
        $this->asAdmin();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function viewRows(): array
    {
        return DB::table('_sogo_static_view')->orderBy('c_uid')->get()
            ->map(fn (object $row): array => array_diff_key((array) $row, ['c_password' => true]))->all();
    }

    private function assertEqualsRebuild(string $step): void
    {
        $incremental = $this->viewRows();
        $this->app->make(SogoDirectory::class)->rebuild();

        $this->assertSame($this->viewRows(), $incremental, "After {$step} the view differs from a full rebuild.");
    }

    /**
     * @return array<string, array{Closure(self): void}>
     */
    public static function changes(): array
    {
        return [
            'an alias creation' => [fn (self $t) => $t->createAlias(['address' => 'sales@example.org', 'destinations' => ['anna@example.org', 'carl@example.com']])->assertCreated()],
            'a catch-all creation' => [fn (self $t) => $t->createAlias(['address' => '@example.org', 'destinations' => ['anna@example.org']])->assertCreated()],
            'an alias with external destinations' => [fn (self $t) => $t->createAlias(['address' => 'fwd@example.org', 'destinations' => ['anna@example.org', 'someone@gmail.example', 'dora@example.org']])->assertCreated()],
            'a destination change' => [fn (self $t) => $t->patchAlias($t->idOf('team@example.org'), ['destinations' => ['carl@example.com', 'someone@gmail.example']])->assertOk()],
            'an address change' => [fn (self $t) => $t->patchAlias($t->idOf('team@example.org'), ['address' => 'staff@example.net'])->assertOk()],
            'a visibility change' => [fn (self $t) => $t->patchAlias($t->idOf('hidden@example.org'), ['groupwareVisible' => true])->assertOk()],
            'an alias deletion' => [fn (self $t) => $t->deleteAlias($t->idOf('team@example.org'))->assertNoContent()],
            'a resource creation' => [fn (self $t) => $t->postJson('/api/v2/resources', ['description' => 'Beamer', 'domain' => 'example.org', 'kind' => 'thing', 'bookingRule' => 'limited', 'bookingLimit' => 3], $t->csrf())->assertCreated()],
            'a resource change' => [fn (self $t) => $t->patchMerge('/api/v2/resources/Room@example.org', ['description' => 'Big room', 'bookingRule' => 'alwaysFree'], $t->csrf())->assertOk()],
            'a resource deactivation' => [fn (self $t) => $t->patchMerge('/api/v2/resources/Room@example.org', ['active' => false], $t->csrf())->assertOk()],
            'a resource deletion' => [fn (self $t) => $t->deleteJson('/api/v2/resources/Room@example.org', [], $t->csrf())->assertNoContent()],
            'external sender addresses' => [fn (self $t) => $t->patchMerge('/api/v2/mailboxes/anna@example.org/sender-settings', ['externalSenderAddresses' => ['news@mailer.example', '@partner.example']], $t->csrf())->assertOk()],
            'a bulk action' => [fn (self $t) => $t->postJson('/api/v2/aliases/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => (string) $t->idOf('team@example.org')], ['id' => (string) $t->idOf('hidden@example.org')]]], $t->csrf())->assertOk()],
        ];
    }

    public function idOf(string $address): int
    {
        return (int) DB::table('alias')->where('address', $address)->value('id');
    }

    /**
     * @param  Closure(self): void  $change
     */
    #[DataProvider('changes')]
    public function test_the_incremental_update_equals_a_full_rebuild(Closure $change): void
    {
        $change($this);

        $this->assertEqualsRebuild((string) $this->name());
    }
}
