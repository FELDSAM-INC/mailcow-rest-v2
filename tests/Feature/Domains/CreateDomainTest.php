<?php

namespace Tests\Feature\Domains;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 002 T047 (FR-017–FR-022, FR-029, US2): createDomain from a template, with validation
 * and the role, key and anti-forgery checks.
 */
class CreateDomainTest extends TestCase
{
    use ApiKeys;
    use DomainTestHelpers;

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function create(array $body): TestResponse
    {
        return $this->postJson('/api/v2/domains', $body, $this->csrf());
    }

    public function test_a_domain_with_only_its_name_takes_the_default_template(): void
    {
        $this->mailcow()->defaultDomainTemplate(['key_size' => 1024]);
        $this->asAdmin();

        $response = $this->create(['name' => 'example.org'])->assertCreated();

        $response->assertHeader('Location', '/api/v2/domains/example.org');
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $data = $response->json('data');
        $this->assertSame('example.org', $data['name']);
        $this->assertSame('example.org', $data['description']);
        $this->assertSame(10, $data['maxMailboxes']);
        $this->assertSame(400, $data['maxAliases']);
        $this->assertSame(3072 * self::MIB, $data['defaultMailboxQuota']);
        $this->assertSame(10240 * self::MIB, $data['maxMailboxQuota']);
        $this->assertSame(10240 * self::MIB, $data['domainQuota']);
        $this->assertTrue($data['active']);
        $this->assertTrue($data['globalAddressList']);
        $this->assertFalse($data['backupMx']);
        $this->assertSame([], $data['tags']);
        $this->assertSame('created', $data['creationReport']['dkimKey']);

        $row = $this->domainRow('example.org');
        $this->assertNotNull($row);
        $this->assertSame(3072, (int) $row->defquota);
        $this->assertSame('0', $row->relayhost);
    }

    public function test_request_values_override_the_named_template(): void
    {
        $id = $this->mailcow()->domainTemplate('Reseller', array_merge(MailcowFactory::DEFAULT_DOMAIN_TEMPLATE, [
            'max_num_mboxes_for_domain' => 50,
            'max_quota_for_domain' => 51200 * self::MIB,
            'max_num_aliases_for_domain' => 77,
            'tags' => ['reseller'],
            'gal' => 0,
            'key_size' => 1024,
        ]));
        $this->mailcow()->defaultDomainTemplate();
        $this->asAdmin();

        $data = $this->create(['name' => 'example.org', 'templateId' => $id, 'maxMailboxes' => 20])->assertCreated()->json('data');

        $this->assertSame(20, $data['maxMailboxes']);
        $this->assertSame(51200 * self::MIB, $data['domainQuota']);
        $this->assertSame(77, $data['maxAliases']);
        $this->assertSame(['reseller'], $data['tags']);
        $this->assertFalse($data['globalAddressList']);
    }

    public function test_an_existing_domain_or_alias_domain_is_a_conflict_in_any_spelling(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('target.example');
        $this->mailcow()->aliasDomain('alias.example', 'target.example');
        $this->asAdmin();

        foreach (['Example.org', 'EXAMPLE.ORG', 'Alias.Example'] as $name) {
            $this->create(['name' => $name, 'dkimKeySize' => 1024])->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/domain-exists');
        }

        $this->assertSame(2, DB::table('domain')->count());
        $this->assertSame([], $this->redis()->hGetAll('DOMAIN_MAP'));
    }

    public function test_quotas_must_be_in_order(): void
    {
        $this->asAdmin();

        $this->create(['name' => 'a.example', 'defaultMailboxQuota' => 2048 * self::MIB, 'maxMailboxQuota' => 1024 * self::MIB])
            ->assertStatus(422)
            ->assertJsonPath('errors.defaultMailboxQuota.0.code', 'mustNotExceed')
            ->assertJsonPath('errors.defaultMailboxQuota.0.params', ['field' => 'maxMailboxQuota', 'value' => 1024 * self::MIB]);

        $this->create(['name' => 'b.example', 'maxMailboxQuota' => 20480 * self::MIB, 'domainQuota' => 10240 * self::MIB])
            ->assertStatus(422)
            ->assertJsonPath('errors.maxMailboxQuota.0.code', 'mustNotExceed')
            ->assertJsonPath('errors.maxMailboxQuota.0.params', ['field' => 'domainQuota', 'value' => 10240 * self::MIB]);

        $this->assertSame(0, DB::table('domain')->count());
    }

    public function test_the_server_host_name_is_reserved(): void
    {
        $this->asAdmin();

        $this->create(['name' => 'MAIL.EXAMPLE.ORG'])->assertStatus(422)->assertJsonPath('errors.name.0.code', 'reserved');
    }

    public function test_relay_options_are_normalised(): void
    {
        $this->asAdmin();

        $data = $this->create(['name' => 'relay.example', 'relayUnknownOnly' => true, 'dkimSelector' => ''])->assertCreated()->json('data');

        $this->assertTrue($data['backupMx']);
        $this->assertTrue($data['relayAllRecipients']);
        $this->assertTrue($data['relayUnknownOnly']);

        $data = $this->create(['name' => 'relay2.example', 'relayAllRecipients' => true, 'backupMx' => false, 'dkimSelector' => ''])->assertCreated()->json('data');
        $this->assertTrue($data['backupMx']);
        $this->assertFalse($data['relayUnknownOnly']);
    }

    public function test_names_are_normalised_and_validated(): void
    {
        $this->asAdmin();

        $this->create(['name' => 'München.de', 'dkimSelector' => ''])->assertCreated()
            ->assertJsonPath('data.name', 'xn--mnchen-3ya.de')
            ->assertJsonPath('data.unicodeName', 'münchen.de')
            ->assertHeader('Location', '/api/v2/domains/xn--mnchen-3ya.de');
        $this->create(['name' => 'localdomain', 'dkimSelector' => ''])->assertCreated();

        $this->create(['name' => 'exa_mple.org'])->assertStatus(422)
            ->assertJsonPath('errors.name.0.code', 'invalidFormat')
            ->assertJsonPath('errors.name.0.params', ['format' => 'domainName']);
        $this->create(['name' => 'a..b'])->assertStatus(422)->assertJsonPath('errors.name.0.code', 'invalidFormat');
    }

    public function test_business_rule_violations_are_reported_together(): void
    {
        $this->asAdmin();
        $tags = array_map(fn (int $i): string => "t{$i}", range(1, 26));

        $errors = $this->create([
            'name' => 'exa_mple.org',
            'templateId' => 999,
            'domainQuota' => 1572864,
            'tags' => $tags,
        ])->assertStatus(422)->json('errors');

        $this->assertSame('invalidFormat', $errors['name'][0]['code']);
        $this->assertSame('notFound', $errors['templateId'][0]['code']);
        $this->assertSame(['factor' => 1048576], $errors['domainQuota'][0]['params']);
        $this->assertSame('notMultipleOf', $errors['domainQuota'][0]['code']);
        $this->assertSame(['max' => 25], $errors['tags'][0]['params']);
        $this->assertSame(0, DB::table('domain')->count());
    }

    public function test_invalid_values_that_the_contract_refuses_are_reported_with_rule_codes(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $errors = $this->create([
            'name' => 'example.org',
            'description' => str_repeat('d', 256),
            'dkimSelector' => 'a b',
            'dkimKeySize' => 512,
            'rateLimit' => ['value' => 10, 'frame' => 'week'],
            'maxMailboxes' => -1,
        ])->assertStatus(422)->json('errors');

        $this->assertSame('tooLong', $errors['description'][0]['code']);
        $this->assertSame(['format' => 'dkimSelector'], $errors['dkimSelector'][0]['params']);
        $this->assertSame('notInList', $errors['dkimKeySize'][0]['code']);
        $this->assertSame('notInList', $errors['rateLimit.frame'][0]['code']);
        $this->assertSame('outOfRange', $errors['maxMailboxes'][0]['code']);
        $this->assertSame(0, DB::table('domain')->count());
    }

    public function test_the_built_in_values_apply_without_a_default_template(): void
    {
        config(['restv2.dkim.default_selector' => 'feldhost']);
        $this->asAdmin();

        $data = $this->create(['name' => 'example.org', 'dkimKeySize' => 1024])->assertCreated()->json('data');

        $this->assertSame(10, $data['maxMailboxes']);
        $this->assertSame(400, $data['maxAliases']);
        $this->assertSame(3072 * self::MIB, $data['defaultMailboxQuota']);
        $this->assertSame('feldhost', $this->redis()->hGet('DKIM_SELECTORS', 'example.org'));
    }

    public function test_external_sender_addresses_in_the_new_domain_are_removed(): void
    {
        $this->mailcow()->domain('other.example');
        $this->mailcow()->mailbox('alice@other.example');
        $this->mailcow()->senderAcl('alice@other.example', 'x@example.org', 1);
        $keepInternal = $this->mailcow()->senderAcl('alice@other.example', 'y@example.org', 0);
        $keepOther = $this->mailcow()->senderAcl('alice@other.example', 'x@example.net', 1);
        $this->asAdmin();

        $this->create(['name' => 'example.org', 'dkimSelector' => ''])->assertCreated();

        $this->assertSame([$keepInternal, $keepOther], DB::table('sender_acl')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    public function test_only_administrators_with_write_access_may_create(): void
    {
        $this->mailcow()->domain('mine.example');
        $this->asDomainAdmin(['mine.example']);
        $this->create(['name' => 'new.example'])->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $key = $this->apiKey('admin', 'readOnly');
        $this->postJson('/api/v2/domains', ['name' => 'new.example'], $this->keyHeader($key))->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->forgetCookies();
        $this->asMailboxUser('user@mine.example');
        $this->create(['name' => 'new.example'])->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->asAdmin();
        $this->postJson('/api/v2/domains', ['name' => 'new.example'])->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');

        $this->assertFalse(DB::table('domain')->where('domain', 'new.example')->exists());
    }

    public function test_a_read_write_administrator_key_creates_domains(): void
    {
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $key = $this->apiKey('admin', 'readWrite');

        $this->postJson('/api/v2/domains', ['name' => 'billing.example', 'dkimSelector' => ''], $this->keyHeader($key))->assertCreated();
    }
}
