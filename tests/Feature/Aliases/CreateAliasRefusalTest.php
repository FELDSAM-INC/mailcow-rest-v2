<?php

namespace Tests\Feature\Aliases;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T031 (FR-009, FR-010, FR-021–FR-028, US2): refused alias creations change nothing,
 * name every invalid entry and are audited.
 */
class CreateAliasRefusalTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->aliasDomain('example.net', 'example.org');
        $this->mailbox('anna@example.org');
        $f->resource('MeetingRoom1@example.org', 'Meeting Room 1');
        $f->alias('info@example.org', 'anna@example.org');
        $f->temporaryAlias('tmp123@example.org', 'anna@example.org');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function taken(): array
    {
        return [
            'mailbox' => ['anna@example.org', 'mailbox'],
            'mailbox in another case' => ['ANNA@example.org', 'mailbox'],
            'resource' => ['meetingroom1@example.org', 'resource'],
            'alias' => ['info@example.org', 'alias'],
            'alias in another case' => ['INFO@example.org', 'alias'],
            'temporary alias' => ['tmp123@example.org', 'temporaryAlias'],
            'mailbox through an alias domain' => ['anna@example.net', 'mailbox'],
        ];
    }

    #[DataProvider('taken')]
    public function test_an_address_in_use_is_a_conflict_naming_the_holder(string $address, string $holder): void
    {
        $this->asAdmin();
        $before = DB::table('alias')->count();

        $this->createAlias(['address' => $address, 'destinations' => ['bob@example.org']])->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/address-in-use')
            ->assertJsonPath('holder', $holder);

        $this->assertSame($before, DB::table('alias')->count());
        $refusal = $this->auditOf('aliasCreation')[0];
        $this->assertSame(['failure', 'conflict'], [$refusal->outcome, $refusal->failure_reason]);
    }

    public function test_the_alias_limit_counts_the_primary_domain_and_its_alias_domains(): void
    {
        DB::table('domain')->where('domain', 'example.org')->update(['aliases' => 3]);
        $this->mailcow()->alias('two@example.net', 'anna@example.org');
        $this->asAdmin();
        $this->createAlias(['address' => 'three@example.org', 'destinations' => ['anna@example.org']])->assertCreated();

        foreach (['four@example.org', 'four@example.net'] as $address) {
            $this->createAlias(['address' => $address, 'destinations' => ['anna@example.org']])->assertStatus(409)
                ->assertJsonPath('type', '/api/v2/problems/domain-alias-limit-reached')
                ->assertJsonPath('domain', 'example.org')
                ->assertJsonPath('count', 3)
                ->assertJsonPath('limit', 3)
                ->assertJsonPath('requested', 1);
        }

        $this->assertSame(4, DB::table('alias')->count(), 'Only the own-alias row, info, two and three exist.');
    }

    public function test_every_invalid_entry_is_named_in_one_response(): void
    {
        $this->asAdmin();

        $errors = $this->createAlias([
            'address' => 'x@example.org',
            'destinations' => ['anna@example.org', 'not an address', 'MeetingRoom1@example.org', 'X@example.org', 'spam@localhost'],
            'privateComment' => str_repeat('é', 80).'x',
        ])->assertStatus(422)->json('errors');

        $this->assertEquals(['code' => 'invalidFormat', 'params' => ['format' => 'email']], array_intersect_key($errors['destinations.1'][0], ['code' => 1, 'params' => 1]));
        $this->assertEquals(['code' => 'notEligible', 'params' => ['kind' => 'resource']], array_intersect_key($errors['destinations.2'][0], ['code' => 1, 'params' => 1]));
        $this->assertEquals(['code' => 'conflictsWith', 'params' => ['field' => 'address', 'value' => 'x@example.org']], array_intersect_key($errors['destinations.3'][0], ['code' => 1, 'params' => 1]));
        $this->assertSame('invalidFormat', $errors['destinations.4'][0]['code']);
        $this->assertEquals(['code' => 'tooLong', 'params' => ['max' => 160, 'unit' => 'bytes']], array_intersect_key($errors['privateComment'][0], ['code' => 1, 'params' => 1]));
        $this->assertArrayNotHasKey('destinations.0', $errors);
        $this->assertNull($this->aliasRow('x@example.org'));

        $refusal = $this->auditOf('aliasCreation')[0];
        $this->assertSame('validationFailed', $refusal->failure_reason);
        $this->assertSame('invalidFormat', $refusal->details['errors']['destinations.1'][0]['code']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAddresses(): array
    {
        return [
            'quoted' => ['"a b"@example.org'],
            'non-ASCII local part' => ['müller@example.org'],
            'address literal' => ['a@[192.0.2.1]'],
            '65 characters' => [str_repeat('a', 65).'@example.org'],
            'double dot' => ['a..b@example.org'],
        ];
    }

    #[DataProvider('invalidAddresses')]
    public function test_invalid_addresses_are_refused(string $address): void
    {
        $this->asAdmin();

        $this->createAlias(['address' => $address, 'destinations' => ['anna@example.org']])->assertStatus(422)
            ->assertJsonPath('errors.address.0.code', 'invalidFormat')
            ->assertJsonPath('errors.address.0.params.format', 'aliasAddress');
    }

    public function test_destination_type_rules(): void
    {
        $this->asAdmin();

        $this->createAlias(['address' => 'a@example.org', 'destinationType' => 'discard', 'destinations' => ['anna@example.org']])->assertStatus(422)
            ->assertJsonPath('errors.destinations.0.code', 'conflictsWith')
            ->assertJsonPath('errors.destinations.0.params.field', 'destinationType');
        $this->createAlias(['address' => 'a@example.org'])->assertStatus(422)->assertJsonPath('errors.destinations.0.code', 'required');
        $this->createAlias(['address' => 'a@example.org', 'destinationType' => 'addresses'])->assertStatus(422)->assertJsonPath('errors.destinations.0.code', 'required');

        $domain = str_repeat('d', 60).'.'.str_repeat('e', 60).'.'.str_repeat('f', 60).'.example';
        $many = array_map(fn (int $i): string => "user{$i}@{$domain}", range(1, 400));
        $this->createAlias(['address' => 'a@example.org', 'destinations' => $many])->assertStatus(422)
            ->assertJsonPath('errors.destinations.0.code', 'tooLong')
            ->assertJsonPath('errors.destinations.0.params', ['max' => 65535, 'unit' => 'characters']);
    }

    public function test_an_unknown_domain_and_one_outside_the_scope_get_the_same_answer(): void
    {
        $this->mailcow()->domain('foreign.example');
        $this->asDomainAdmin(['example.org']);

        foreach (['a@foreign.example', 'a@unknown.example', '@foreign.example'] as $address) {
            $this->createAlias(['address' => $address, 'destinations' => ['anna@example.org']])->assertStatus(422)
                ->assertJsonPath('errors.address.0.code', 'notFound')
                ->assertJsonPath('errors.address.0.params.resource', 'domain');
        }

        $this->createAlias(['address' => 'a@example.net', 'destinations' => ['anna@example.org']])->assertCreated();
    }
}
