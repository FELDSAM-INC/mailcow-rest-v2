<?php

namespace Tests\Feature\TemporaryAliases;

use App\Services\TemporaryAliases\TemporaryAliasAddressGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T080 (feature 005 FR-042–FR-046, US6 AS-1–AS-3, AS-5): the temporary aliases of a mailbox, stored
 * as legacy stores them, never colliding with an address in use.
 */
class TemporaryAliasCrudTest extends TestCase
{
    use SelfServiceTestHelpers;

    private const BASE = '/api/v2/mailboxes/anna@example.org/temporary-aliases';

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function create(array $body): TestResponse
    {
        return $this->postJson(self::BASE, $body, $this->csrf());
    }

    private function row(string $address): object
    {
        $row = DB::table('spamalias')->where('address', $address)->first();
        $this->assertNotNull($row);

        return $row;
    }

    public function test_an_alias_in_the_mailbox_domain_is_valid_for_a_year(): void
    {
        $this->freezeTime();
        $this->asSelfServiceUser();

        $response = $this->create(['domain' => 'example.org', 'description' => 'shop'])->assertCreated();

        $address = (string) $response->json('data.address');
        $this->assertMatchesRegularExpression('/^[a-z]{6,8}\.[a-z]{6,8}@example\.org$/', $address);
        $response->assertHeader('Location', self::BASE.'/'.$address)
            ->assertHeader('ETag', (string) $response->json('data.etag'))
            ->assertJsonPath('data.mailbox', 'anna@example.org')
            ->assertJsonPath('data.description', 'shop')
            ->assertJsonPath('data.permanent', false)
            ->assertJsonPath('data.expiresAt', CarbonImmutable::now('UTC')->addHours(8760)->format('Y-m-d\TH:i:s\Z'));
        $row = $this->row($address);
        $this->assertSame(['anna@example.org', 'shop', CarbonImmutable::now()->getTimestamp() + 8760 * 3600, 0], [$row->goto, $row->description, (int) $row->validity, (int) $row->permanent]);

        $record = $this->onlyAuditRecord('temporaryAliasCreation');
        $this->assertSame(['temporaryAlias', 'anna@example.org', $address], [$record->target_type, $record->target_id, $record->target_name]);
        $this->assertSame($address, $record->details['temporaryAlias']);
    }

    public function test_an_alias_in_an_alias_domain_and_permanence(): void
    {
        $this->asSelfServiceUser();
        $this->mailcow()->aliasDomain('xn--mnchen-3ya.de', 'example.org');

        $address = (string) $this->create(['domain' => 'MÜNCHEN.de', 'permanent' => true])->assertCreated()->assertJsonPath('data.expiresAt', null)->assertJsonPath('data.permanent', true)->json('data.address');

        $this->assertStringEndsWith('@xn--mnchen-3ya.de', $address);
        $this->assertSame('', $this->row($address)->description);
        $this->assertSame(1, (int) $this->row($address)->permanent);
    }

    public function test_invalid_values_are_refused(): void
    {
        $this->asSelfServiceUser();
        $this->mailcow()->aliasDomain('example.net', 'example.org');

        $this->create(['domain' => 'unrelated.example'])->assertStatus(422)->assertJsonPath('errors.domain.0.code', 'notInList')->assertJsonPath('errors.domain.0.params.allowed', ['example.org', 'example.net']);
        $this->withoutRequestValidation();
        $this->create(['domain' => 'example.org', 'lifetimeHours' => 0])->assertStatus(422)->assertJsonPath('errors.lifetimeHours.0.code', 'outOfRange');
        $this->create(['domain' => 'example.org', 'lifetimeHours' => 87601])->assertStatus(422)->assertJsonPath('errors.lifetimeHours.0.code', 'outOfRange');
        $this->create(['domain' => 'example.org', 'description' => str_repeat('a', 256)])->assertStatus(422)->assertJsonPath('errors.description.0.code', 'tooLong');
        $this->assertSame(0, DB::table('spamalias')->count());
    }

    public function test_the_lifetime_changes(): void
    {
        $this->freezeTime();
        $this->asSelfServiceUser();
        $this->mailcow()->temporaryAliasRow('abcdef.ghijkl@example.org', 'anna@example.org', 'shop');

        $this->patchMerge(self::BASE.'/abcdef.ghijkl@example.org', ['permanent' => true], $this->csrf())->assertOk()->assertJsonPath('data.permanent', true);
        $this->assertSame([0, 1], [(int) $this->row('abcdef.ghijkl@example.org')->validity, (int) $this->row('abcdef.ghijkl@example.org')->permanent]);

        $this->patchMerge(self::BASE.'/ABCDEF.ghijkl@example.org', ['lifetimeHours' => 1], $this->csrf())->assertOk()->assertJsonPath('data.permanent', false);
        $this->assertSame([CarbonImmutable::now()->getTimestamp() + 3600, 0], [(int) $this->row('abcdef.ghijkl@example.org')->validity, (int) $this->row('abcdef.ghijkl@example.org')->permanent]);
        $this->assertSame(['permanent' => ['old' => true, 'new' => false], 'expiresAt' => ['old' => null, 'new' => CarbonImmutable::now('UTC')->addHour()->format('Y-m-d\TH:i:s\Z')]], $this->auditRecords('temporaryAliasUpdate')[1]->details['changes']);
    }

    public function test_invalid_patches_are_refused(): void
    {
        $this->asSelfServiceUser();
        $this->mailcow()->temporaryAliasRow('abcdef.ghijkl@example.org', 'anna@example.org');
        $this->withoutRequestValidation();

        $this->patchMerge(self::BASE.'/abcdef.ghijkl@example.org', ['lifetimeHours' => 1, 'permanent' => true], $this->csrf())->assertStatus(422)->assertJsonPath('errors.lifetimeHours.0.code', 'mutuallyExclusive');
        $this->patchMerge(self::BASE.'/abcdef.ghijkl@example.org', [], $this->csrf())->assertStatus(422);
    }

    public function test_an_expired_alias_does_not_exist(): void
    {
        $this->asSelfServiceUser();
        $this->mailcow()->temporaryAliasRow('abcdef.ghijkl@example.org', 'anna@example.org', validity: CarbonImmutable::now()->getTimestamp() + 3600);

        $this->travel(2)->hours();

        $this->getJson(self::BASE)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::BASE.'/abcdef.ghijkl@example.org')->assertNotFound();
        $this->patchMerge(self::BASE.'/abcdef.ghijkl@example.org', ['permanent' => true], $this->csrf())->assertNotFound();
        $this->deleteJson(self::BASE.'/abcdef.ghijkl@example.org', [], $this->csrf())->assertNotFound();
    }

    public function test_deletion(): void
    {
        $this->asSelfServiceUser();
        $this->mailcow()->temporaryAliasRow('abcdef.ghijkl@example.org', 'anna@example.org', 'shop');

        $this->deleteJson(self::BASE.'/abcdef.ghijkl@example.org', [], $this->csrf())->assertNoContent();

        $this->assertFalse(DB::table('spamalias')->where('address', 'abcdef.ghijkl@example.org')->exists());
        $this->assertSame('shop', $this->onlyAuditRecord('temporaryAliasDeletion')->details['removed']['description']);
    }

    public function test_the_list_sorts_filters_and_searches(): void
    {
        $this->asSelfServiceUser();
        $now = CarbonImmutable::now()->getTimestamp();
        $this->mailcow()->temporaryAliasRow('aaaaaa.aaaaaa@example.org', 'anna@example.org', 'shop', $now + 100);
        $this->mailcow()->temporaryAliasRow('bbbbbb.bbbbbb@example.org', 'anna@example.org', 'forum', $now + 50);
        $this->mailcow()->temporaryAliasRow('cccccc.cccccc@example.org', 'anna@example.org', 'keep', 0, 1);
        $this->mailcow()->mailboxWithDomain('carl@example.org');
        $this->mailcow()->temporaryAliasRow('dddddd.dddddd@example.org', 'carl@example.org');

        $list = $this->getJson(self::BASE)->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson(self::BASE.'/'.$list->json('data.0.address'))->assertHeader('ETag', (string) $list->json('data.0.etag'));
        $this->assertSame(['bbbbbb.bbbbbb@example.org', 'aaaaaa.aaaaaa@example.org', 'cccccc.cccccc@example.org'], array_column($this->getJson(self::BASE.'?sort=expiresAt')->json('data'), 'address'));
        $this->assertSame(['cccccc.cccccc@example.org'], array_column($this->getJson(self::BASE.'?permanent=true')->json('data'), 'address'));
        $this->assertSame(['bbbbbb.bbbbbb@example.org'], array_column($this->getJson(self::BASE.'?q=forum')->json('data'), 'address'));
        $this->assertSame('aaaaaa.aaaaaa@example.org', $this->getJson(self::BASE.'?sort=address&order=asc&limit=1')->json('data.0.address'));
    }

    /**
     * @param  list<string>  $sequence
     */
    private function seedGenerator(array $sequence): void
    {
        $this->app->instance(TemporaryAliasAddressGenerator::class, new class($sequence) extends TemporaryAliasAddressGenerator
        {
            /** @param list<string> $sequence */
            public function __construct(private array $sequence)
            {
                parent::__construct();
            }

            public function localPart(): string
            {
                return (string) array_shift($this->sequence);
            }
        });
    }

    public function test_generated_addresses_never_equal_an_address_in_use(): void
    {
        $this->asSelfServiceUser();
        $this->mailcow()->mailbox('bababa.bababa@example.org');
        $this->mailcow()->resource('cecece.cecece@example.org', 'Room');
        $this->mailcow()->alias('dididi.dididi@example.org', 'anna@example.org');
        $this->mailcow()->temporaryAliasRow('fofofo.fofofo@example.org', 'anna@example.org');
        $this->mailcow()->aliasDomain('example.net', 'example.org');

        $this->seedGenerator(['bababa.bababa', 'cecece.cecece', 'dididi.dididi', 'fofofo.fofofo', 'hahaha.hahaha']);
        $this->create(['domain' => 'example.org'])->assertCreated()->assertJsonPath('data.address', 'hahaha.hahaha@example.org');

        // In an alias domain, the target domain's mailbox or alias with the same local part counts.
        $this->seedGenerator(['bababa.bababa', 'dididi.dididi', 'jijiji.jijiji']);
        $this->create(['domain' => 'example.net'])->assertCreated()->assertJsonPath('data.address', 'jijiji.jijiji@example.net');

        // Ten taken candidates: a temporary failure, nothing stored.
        $this->seedGenerator(array_fill(0, 10, 'bababa.bababa'));
        $this->create(['domain' => 'example.org'])->assertStatus(503)->assertJsonPath('dependency', 'addressGeneration');
        $this->assertSame(3, DB::table('spamalias')->count());
    }
}
