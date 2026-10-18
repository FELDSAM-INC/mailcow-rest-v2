<?php

namespace Tests\Feature\SieveFilters;

use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T107 (feature 005 FR-052): checking a Sieve script without saving it; a read, so read-only keys
 * may use it.
 */
class SieveScriptValidationTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    private const URL = '/api/v2/sieve-filters/validation';

    public function test_valid_and_invalid_scripts(): void
    {
        $this->asAdmin();

        $this->postJson(self::URL, ['script' => "require \"fileinto\";\nfileinto \"Junk\";"], $this->csrf())
            ->assertOk()
            ->assertExactJson(['data' => ['valid' => true, 'message' => null]]);
        $this->postJson(self::URL, ['script' => "keep;\nfileinto2 \"x\";"], $this->csrf())
            ->assertOk()
            ->assertExactJson(['data' => ['valid' => false, 'message' => 'line 2: unknown command fileinto2']]);

        $this->assertSame(0, DB::table('sieve_filters')->count());
        $this->assertSame(0, DB::table('restv2_audit_events')->where('event_type', 'like', 'sieve%')->count());
    }

    public function test_a_read_only_key_and_a_domain_administrator_may_validate(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->postJson(self::URL, ['script' => 'keep;'], $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertOk()
            ->assertJsonPath('data.valid', true);

        $this->asDomainAdmin(['example.org'], 'reseller', ['filters' => 0]);
        $this->postJson(self::URL, ['script' => 'keep;'], $this->csrf())->assertOk();
    }

    public function test_a_mailbox_user_is_refused(): void
    {
        $this->asSelfServiceUser();

        $this->postJson(self::URL, ['script' => 'keep;'], $this->csrf())
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/forbidden');
    }

    public function test_invalid_bodies_are_refused(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();

        $this->postJson(self::URL, [], $this->csrf())->assertStatus(422)->assertJsonPath('errors.script.0.code', 'required');
        $this->postJson(self::URL, ['script' => 5], $this->csrf())->assertStatus(422)->assertJsonPath('errors.script.0.code', 'invalidType');
        $this->postJson(self::URL, ['script' => ''], $this->csrf())->assertStatus(422)->assertJsonPath('errors.script.0.code', 'tooShort');
        $this->postJson(self::URL, ['script' => str_repeat('#', 65536)], $this->csrf())->assertStatus(422)->assertJsonPath('errors.script.0.code', 'tooLong');
    }
}
