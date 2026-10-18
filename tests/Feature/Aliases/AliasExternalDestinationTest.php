<?php

namespace Tests\Feature\Aliases;

use Illuminate\Support\Facades\DB;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T032 (FR-006, FR-007, FR-027, US2): external destinations need `aliasExternalGoto`
 * from domain administrators and are refused for everybody when the server disables them.
 */
class AliasExternalDestinationTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('customer.example');
        $f->domain('off.example', 0);
        $f->aliasDomain('inactive-alias.example', 'example.org', 0);
        $this->mailbox('anna@example.org');
    }

    public function test_a_domain_administrator_without_the_flag_may_not_add_external_destinations(): void
    {
        $this->asDomainAdmin(['example.org'], 'reseller', ['alias_external_goto' => 0]);

        $this->createAlias(['address' => 'a@example.org', 'destinations' => ['anna@example.org', 'someone@gmail.example', 'x@off.example', 'y@inactive-alias.example']])
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/attribute-not-permitted')
            ->assertJsonPath('attributes', ['destinations.1', 'destinations.2', 'destinations.3'])
            ->assertJsonPath('requiredFlags', ['destinations.1' => 'aliasExternalGoto', 'destinations.2' => 'aliasExternalGoto', 'destinations.3' => 'aliasExternalGoto']);
        $this->assertNull($this->aliasRow('a@example.org'));
        $this->assertSame('attributeNotPermitted', $this->auditOf('aliasCreation')[0]->failure_reason);

        // Another customer's domain on the same server is not external.
        $this->createAlias(['address' => 'b@example.org', 'destinations' => ['anna@example.org', 'someone@customer.example']])->assertCreated();
    }

    public function test_the_flag_is_read_on_every_request(): void
    {
        $this->asDomainAdmin(['example.org'], 'reseller', ['alias_external_goto' => 1]);

        $this->createAlias(['address' => 'a@example.org', 'destinations' => ['someone@gmail.example']])->assertCreated();

        DB::table('da_acl')->where('username', 'reseller')->update(['alias_external_goto' => 0]);
        $this->createAlias(['address' => 'b@example.org', 'destinations' => ['someone@gmail.example']])->assertStatus(403);

        DB::table('da_acl')->where('username', 'reseller')->update(['alias_external_goto' => 1]);
        $this->createAlias(['address' => 'c@example.org', 'destinations' => ['someone@gmail.example']])->assertCreated();
    }

    public function test_the_server_setting_refuses_external_destinations_for_administrators_too(): void
    {
        config(['restv2.vars_overrides.ALIAS_DISABLE_EXTERNAL_DOMAINS' => 'true']);
        $this->asAdmin();

        $errors = $this->createAlias(['address' => 'a@example.org', 'destinations' => ['anna@example.org', 'someone@gmail.example']])
            ->assertStatus(422)->json('errors');
        $this->assertSame('notEligible', $errors['destinations.1'][0]['code']);
        $this->assertSame(['kind' => 'externalDestination'], $errors['destinations.1'][0]['params']);

        $this->createAlias(['address' => 'b@example.org', 'destinations' => ['anna@example.org', 'someone@customer.example']])->assertCreated();
    }

    public function test_administrators_may_add_external_destinations_by_default(): void
    {
        $this->asAdmin();

        $this->createAlias(['address' => 'a@example.org', 'destinations' => ['someone@gmail.example']])->assertCreated();
    }
}
