<?php

namespace Tests\Feature\Aliases;

use App\Audit\AuditEventType;
use App\Clients\Memcached\MemcachedFlusher;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Groupware\GroupwareOutcome;
use App\Services\Groupware\SogoDirectory;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T030 (FR-020–FR-026, FR-032–FR-034, US2): createAlias and its side effects.
 */
class CreateAliasTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailbox('anna@example.org');
        $this->mailbox('bob@example.org');
    }

    public function test_an_alias_is_created_with_legacys_columns_and_groupware_identities(): void
    {
        $this->asAdmin();

        $response = $this->createAlias(['address' => 'sales@example.org', 'destinations' => ['anna@example.org', 'bob@example.org']])->assertCreated();

        $id = (int) $response->json('data.id');
        $response->assertHeader('Location', "/api/v2/aliases/{$id}");
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertSame($this->getJson("/api/v2/aliases/{$id}")->headers->get('ETag'), $response->headers->get('ETag'));
        $row = $this->aliasRow($id);
        $this->assertNotNull($row);
        $this->assertSame(
            ['sales@example.org', 'anna@example.org,bob@example.org', 'example.org', 1, 1, 0, 1, '', ''],
            [$row->address, $row->goto, $row->domain, (int) $row->sogo_visible, (int) $row->sender_allowed, (int) $row->internal, (int) $row->active, $row->public_comment, $row->private_comment],
        );
        $response->assertJsonPath('data.active', true)->assertJsonPath('data.senderAllowed', true)
            ->assertJsonPath('data.groupwareVisible', true)->assertJsonPath('data.internal', false)
            ->assertJsonPath('data.kind', 'regular');
        $this->assertStringContainsString('sales@example.org', (string) $this->viewRow('anna@example.org')?->aliases);
        $this->assertStringContainsString('sales@example.org', (string) $this->viewRow('bob@example.org')?->aliases);
        $this->assertSame(1, $this->memcached()->flushCount());

        $records = $this->auditOf('aliasCreation');
        $this->assertCount(1, $records);
        $this->assertSame(['alias', (string) $id, 'sales@example.org', 'success'], [$records[0]->target_type, $records[0]->target_id, $records[0]->target_name, $records[0]->outcome]);
        $this->assertSame('done', $records[0]->details['groupwareUpdate']);
        $this->assertSame(['anna@example.org', 'bob@example.org'], $records[0]->details['values']['destinations']);
        $this->assertSame('addresses', $records[0]->details['values']['destinationType']);
    }

    public function test_a_catch_all_alias_is_never_a_groupware_identity(): void
    {
        $this->asAdmin();

        $this->createAlias(['address' => '@Example.org', 'destinations' => ['anna@example.org']])->assertCreated()
            ->assertJsonPath('data.kind', 'catchAll')->assertJsonPath('data.address', '@example.org');

        $this->assertStringNotContainsString('@example.org', str_replace('anna@example.org', '', (string) $this->viewRow('anna@example.org')?->aliases));
    }

    public function test_special_actions_are_stored_as_the_reserved_addresses(): void
    {
        $this->asAdmin();

        foreach (['discard' => 'null@localhost', 'learnSpam' => 'spam@localhost', 'learnHam' => 'ham@localhost'] as $type => $goto) {
            $id = (int) $this->createAlias(['address' => "{$type}@example.org", 'destinationType' => $type])->assertCreated()
                ->assertJsonPath('data.destinationType', $type)->assertJsonPath('data.destinations', [])->json('data.id');
            $this->assertSame($goto, $this->aliasRow($id)?->goto);
        }
    }

    public function test_omitted_flags_take_the_legacy_form_defaults_and_given_values_are_stored(): void
    {
        $this->asAdmin();

        $id = (int) $this->createAlias([
            'address' => 'quiet@example.org', 'destinations' => ['anna@example.org'], 'active' => false, 'senderAllowed' => false,
            'groupwareVisible' => false, 'internal' => true, 'publicComment' => 'Public', 'privateComment' => 'Private',
        ])->assertCreated()->json('data.id');

        $row = $this->aliasRow($id);
        $this->assertSame([0, 0, 0, 1, 'Public', 'Private'], [(int) $row?->active, (int) $row?->sender_allowed, (int) $row?->sogo_visible, (int) $row?->internal, $row?->public_comment, $row?->private_comment]);
    }

    public function test_addresses_and_destinations_are_normalised_and_deduplicated(): void
    {
        $this->mailcow()->domain('xn--bcher-kva.example');
        $this->asAdmin();

        $response = $this->createAlias(['address' => ' Sales@Bücher.Example ', 'destinations' => ['Bob@Example.ORG', 'anna@example.org', 'bob@example.org', 'typo@example.org', 'x@Bücher.example']])->assertCreated();

        $this->assertSame('Sales@xn--bcher-kva.example', $response->json('data.address'));
        $this->assertSame('Sales@bücher.example', $response->json('data.addressUnicode'));
        $this->assertSame(['Bob@example.org', 'anna@example.org', 'typo@example.org', 'x@xn--bcher-kva.example'], $response->json('data.destinations'));
        $this->assertSame('Bob@example.org,anna@example.org,typo@example.org,x@xn--bcher-kva.example', $this->aliasRow((int) $response->json('data.id'))?->goto);
    }

    public function test_explicit_permissions_duplicating_the_alias_right_are_removed_only_when_sending_is_allowed(): void
    {
        $this->mailcow()->senderAcl('anna@example.org', 'sales@example.org');
        $this->mailcow()->senderAcl('anna@example.org', 'info@example.org');
        $this->mailcow()->senderAcl('carl@example.org', 'sales@example.org');
        $this->asAdmin();

        $this->createAlias(['address' => 'info@example.org', 'destinations' => ['anna@example.org'], 'senderAllowed' => false])->assertCreated();
        $this->assertContains('anna@example.org info@example.org', $this->senderAcls());

        $this->createAlias(['address' => 'sales@example.org', 'destinations' => ['anna@example.org']])->assertCreated();
        $this->assertSame(['anna@example.org info@example.org', 'carl@example.org sales@example.org'], $this->senderAcls());
    }

    public function test_relay_inactive_and_single_label_domains(): void
    {
        $f = $this->mailcow();
        $f->domain('relay.example', attributes: ['backupmx' => 1]);
        $f->domain('off.example', 0);
        $f->domain('localdomain');
        $this->asAdmin();

        $this->createAlias(['address' => 'a@relay.example', 'destinations' => ['anna@example.org']])->assertCreated();
        $this->createAlias(['address' => 'a@off.example', 'destinations' => ['anna@example.org']])->assertCreated();
        $this->createAlias(['address' => '@localdomain', 'destinations' => ['anna@example.org']])->assertCreated();
        $this->createAlias(['address' => 'info@localdomain', 'destinations' => ['anna@example.org']])->assertStatus(422)
            ->assertJsonPath('errors.address.0.code', 'invalidFormat');
    }

    public function test_nothing_is_written_to_the_groupware_when_it_is_disabled(): void
    {
        config(['restv2.skip_sogo' => true]);
        $this->asAdmin();

        $this->createAlias(['address' => 'sales@example.org', 'destinations' => ['anna@example.org']])->assertCreated();

        $this->assertSame(0, DB::table('_sogo_static_view')->count());
        $this->assertSame(0, $this->memcached()->flushCount());
        $this->assertSame('groupwareDisabled', $this->auditOf('aliasCreation')[0]->details['groupwareUpdate']);
    }

    public function test_a_failed_groupware_update_keeps_the_alias_and_is_recorded(): void
    {
        $this->app->instance(SogoDirectory::class, new class($this->app->make(MemcachedFlusher::class)) extends SogoDirectory
        {
            public function refresh(array $usernames): GroupwareOutcome
            {
                DB::table('_sogo_static_view')->insert(['c_uid' => 'partial@example.org', 'domain' => 'example.org', 'c_name' => 'x', 'c_password' => 'x', 'c_cn' => 'x', 'mail' => 'x', 'aliases' => '', 'ad_aliases' => '', 'ext_acl' => '', 'kind' => '', 'multiple_bookings' => -1]);

                throw new RuntimeException('view broken');
            }
        });
        $this->asAdmin();

        $id = (int) $this->createAlias(['address' => 'sales@example.org', 'destinations' => ['anna@example.org']])->assertCreated()->json('data.id');

        $this->assertNotNull($this->aliasRow($id));
        $this->assertNull($this->viewRow('partial@example.org'), 'The failed update is rolled back to its savepoint.');
        $this->assertSame('failed', $this->auditOf('aliasCreation')[0]->details['groupwareUpdate']);
        $this->assertSame(0, $this->memcached()->flushCount());
    }

    public function test_a_failed_audit_record_rolls_the_creation_back(): void
    {
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(AuditEventType $type, bool $success, mixed $actor, ?array $target, ?string $authMethod, ?string $failureReason, array $details): AuditEvent
            {
                if ($type === AuditEventType::AliasCreation && $success) {
                    throw new RuntimeException('audit log unavailable');
                }

                return parent::record($type, $success, $actor, $target, $authMethod, $failureReason, $details);
            }
        });
        $this->asAdmin();

        $this->createAlias(['address' => 'sales@example.org', 'destinations' => ['anna@example.org']])->assertStatus(500);

        $this->assertNull($this->aliasRow('sales@example.org'));
        $this->assertSame(0, $this->memcached()->flushCount());
    }
}
