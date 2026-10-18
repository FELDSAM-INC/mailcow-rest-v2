<?php

namespace Tests\Feature\Mailboxes;

use App\Services\Passwords\MailboxPasswordHasher;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 003 T044 (FR-064, AS-12, G31, G32): getMailboxCapabilities reports the identity provider
 * type, the groupware and last-login switches, the limits, the garbage-area retention, the
 * recognised pre-hashed schemes and the built-in mailbox defaults, without any secret.
 */
class MailboxCapabilitiesTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    public function test_ldap_and_a_disabled_groupware(): void
    {
        $this->mailcow()->identityProvider('ldap');
        DB::table('identity_provider')->insert(['key' => 'bindpass', 'value' => 'very-secret-bind-password']);
        config(['restv2.skip_sogo' => true]);
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $response = $this->getJson('/api/v2/capabilities/mailboxes')->assertOk();

        $this->assertSame([
            'identityProviderType' => 'ldap',
            'authSources' => ['mailcow', 'ldap'],
            'groupwareEnabled' => false,
            'lastLoginsReported' => true,
            'bulkLimit' => 100,
            'taggingLimit' => 25,
            'tagMaxLength' => 255,
            'customAttributeLimit' => 100,
            'customAttributeNameMaxLength' => 255,
            'customAttributeValueMaxLength' => 4096,
            'defaultPageSize' => 25,
            'maxPageSize' => 1000,
            'garbageRetentionMinutes' => 7200,
            'preHashedPasswordSchemes' => MailboxPasswordHasher::PRE_HASHED_SCHEMES,
        ], array_diff_key($response->json('data'), ['templateDefaults' => 1]));
        $this->assertStringNotContainsString('very-secret', (string) $response->getContent());
    }

    public function test_no_provider_and_the_server_settings(): void
    {
        config([
            'restv2.vars_overrides.SHOW_LAST_LOGIN' => 'n',
            'restv2.vars_overrides.TAGGING_LIMIT' => '7',
            'restv2.bulk_limit' => 50,
            'restv2.mailbox_custom_attribute_limit' => 20,
            'restv2.maildir_gc_time' => 1440,
        ]);
        $this->asAdmin();

        $data = $this->getJson('/api/v2/capabilities/mailboxes')->assertOk()->json('data');

        $this->assertSame('none', $data['identityProviderType']);
        $this->assertSame(['mailcow'], $data['authSources']);
        $this->assertTrue($data['groupwareEnabled']);
        $this->assertFalse($data['lastLoginsReported']);
        $this->assertSame([50, 7, 20, 1440], [$data['bulkLimit'], $data['taggingLimit'], $data['customAttributeLimit'], $data['garbageRetentionMinutes']]);
    }

    public function test_the_built_in_defaults_are_exposed(): void
    {
        config(['restv2.vars_overrides.MAILBOX_DEFAULT_ATTRIBUTES' => '{"pop3_access":false}']);
        $this->asAdmin();

        $defaults = $this->getJson('/api/v2/capabilities/mailboxes')->assertOk()->json('data.templateDefaults');

        $this->assertSame(0, $defaults['quota']);
        $this->assertSame('active', $defaults['status']);
        $this->assertFalse($defaults['protocolAccess']['pop3']);
        $this->assertFalse($defaults['userPermissions']['syncjobs']);
        $this->assertNull($defaults['rateLimit']);
    }

    public function test_mailbox_users_are_refused_and_read_only_keys_may_read(): void
    {
        $this->asMailboxUser('anna@example.org');
        $this->getJson('/api/v2/capabilities/mailboxes')->assertStatus(403);

        $this->forgetCookies();
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->getJson('/api/v2/capabilities/mailboxes', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk();
    }
}
