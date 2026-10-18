<?php

namespace Tests\Feature\Resources;

use App\Services\Groupware\SogoDirectory;
use Illuminate\Support\Facades\DB;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T068 (FR-058, FR-059, US6, US7): applyResourceBulkAction.
 */
class ResourceBulkActionTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        foreach (['A', 'B', 'C'] as $name) {
            $f->resource("{$name}@example.org", "Room {$name}");
        }
        $f->resource('X@foreign.example', 'Room X');
        $this->app->make(SogoDirectory::class)->rebuild();
    }

    public function test_deactivated_resources_disappear_from_the_groupware(): void
    {
        $this->asDomainAdmin(['example.org']);
        $flushes = $this->memcached()->flushCount();

        $data = $this->postJson('/api/v2/resources/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => 'a@example.org'], ['id' => 'B@example.org'], ['id' => 'X@foreign.example']]], $this->csrf())->assertOk()->json('data');

        $this->assertSame(['done', 'done', 'notFound'], array_column($data['results'], 'outcome'));
        $this->assertNull($this->viewRow('A@example.org'));
        $this->assertNull($this->viewRow('B@example.org'));
        $this->assertNotNull($this->viewRow('C@example.org'));
        $this->assertSame($flushes + 1, $this->memcached()->flushCount());
        $this->assertSame($this->getJson('/api/v2/resources/A@example.org')->headers->get('ETag'), $data['results'][0]['version']);
    }

    public function test_activation_deletion_versions_and_the_limit(): void
    {
        DB::table('mailbox')->where('username', 'A@example.org')->update(['active' => 0]);
        $this->asAdmin();
        $old = (string) $this->getJson('/api/v2/resources/C@example.org')->headers->get('ETag');
        DB::table('mailbox')->where('username', 'C@example.org')->update(['name' => 'Renamed in the legacy UI']);

        $this->postJson('/api/v2/resources/bulk-actions', ['action' => 'activate', 'targets' => [['id' => 'A@example.org']]], $this->csrf())->assertOk();
        $this->assertSame(1, (int) $this->mailboxRow('A@example.org')?->active);

        $data = $this->postJson('/api/v2/resources/bulk-actions', ['action' => 'delete', 'targets' => [['id' => 'A@example.org'], ['id' => 'C@example.org', 'version' => $old]]], $this->csrf())->assertOk()->json('data');
        $this->assertSame(['done', 'outdated'], array_column($data['results'], 'outcome'));
        $this->assertNull($this->mailboxRow('A@example.org'));
        $this->assertNotNull($this->mailboxRow('C@example.org'));
    }

    public function test_more_than_100_targets_are_invalid(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->postJson('/api/v2/resources/bulk-actions', ['action' => 'delete', 'targets' => array_map(fn (int $i): array => ['id' => "r{$i}@example.org"], range(1, 101))], $this->csrf())
            ->assertStatus(422)->assertJsonPath('errors.targets.0.code', 'tooMany');
    }
}
