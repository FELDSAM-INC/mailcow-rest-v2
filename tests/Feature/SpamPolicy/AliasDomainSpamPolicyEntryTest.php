<?php

namespace Tests\Feature\SpamPolicy;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T065 (FR-043, AS-5.7): the allow and block lists of an alias domain, reached through
 * its target domain; they apply to the alias domain only.
 */
class AliasDomainSpamPolicyEntryTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('other.example');
        $this->mailcow()->aliasDomain('example.info', 'example.org');
        $this->mailcow()->aliasDomain('other.info', 'other.example');
        $this->mailcow()->domainAdmin('reseller', ['example.org'], ['spam_policy' => 1], scheme: 'SSHA256');
    }

    public function test_a_domain_admin_adds_an_allow_entry_for_an_alias_domain_of_their_domain(): void
    {
        $this->signInAs('reseller', Role::DomainAdmin);

        $response = $this->send('POST', '/api/v2/alias-domains/example.info/spam-policy-entries', ['list' => 'allow', 'pattern' => 'partner@example.net'])->assertCreated();

        $id = (int) $response->json('data.id');
        $response->assertHeader('Location', "/api/v2/alias-domains/example.info/spam-policy-entries/{$id}");
        $this->assertSame(['kind' => 'aliasDomain', 'name' => 'example.info'], $response->json('data.owner'));
        $this->assertSame('example.info', DB::table('filterconf')->where('prefid', $id)->value('object'));
        $this->getJson('/api/v2/alias-domains/example.info/spam-policy-entries')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->send('DELETE', "/api/v2/domains/example.org/spam-policy-entries/{$id}")->assertNotFound();
        $this->send('DELETE', "/api/v2/alias-domains/example.info/spam-policy-entries/{$id}")->assertNoContent();
        $this->assertSame(['spamPolicyEntryCreation', 'spamPolicyEntryRemoval'], array_column($this->auditOfTarget('aliasDomain', 'example.info'), 'event_type'));
    }

    public function test_an_alias_domain_of_another_domain_is_not_found(): void
    {
        $this->signInAs('reseller', Role::DomainAdmin);

        $this->getJson('/api/v2/alias-domains/other.info/spam-policy-entries')->assertNotFound();
        $this->send('POST', '/api/v2/alias-domains/other.info/spam-policy-entries', ['list' => 'allow', 'pattern' => 'partner@example.net'])->assertNotFound();
    }

    public function test_a_mailbox_user_is_refused(): void
    {
        $this->asQuarantineUser('alice@example.org');

        $this->getJson('/api/v2/alias-domains/example.info/spam-policy-entries')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
