<?php

namespace Tests\Feature\Domains;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T084 (FR-051–FR-055, US7): getDomainFooter and replaceDomainFooter.
 */
class DomainFooterTest extends TestCase
{
    use ApiKeys;
    use DomainTestHelpers;

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function replace(array $body, string $domain = 'example.org', array $headers = []): TestResponse
    {
        return $this->putJson('/api/v2/domains/'.$domain.'/footer', $body + ['html' => '', 'plain' => '', 'skipReplies' => false], $this->csrf() + $headers);
    }

    public function test_a_domain_without_a_footer_has_an_empty_one(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $response = $this->getJson('/api/v2/domains/example.org/footer')->assertOk();

        $this->assertSame([
            'domain' => 'example.org',
            'html' => '',
            'plain' => '',
            'skipReplies' => false,
            'excludedAddresses' => [],
            'excludedAliasDomains' => [],
            'etag' => $response->headers->get('ETag'),
        ], $response->json('data'));
    }

    public function test_a_domain_administrator_saves_a_footer_as_legacy_stores_it(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->mailbox('alice@example.org');
        $this->asDomainAdmin(['example.org'], daAcl: ['domain_desc' => 0]);

        $data = $this->replace(['html' => '<p>{= from_name =}</p>', 'plain' => '{= from_name =}', 'skipReplies' => true, 'excludedAddresses' => ['alice@example.org']])
            ->assertOk()->json('data');

        $this->assertSame(['alice@example.org'], $data['excludedAddresses']);
        $row = DB::table('domain_wide_footer')->where('domain', 'example.org')->first();
        $this->assertNotNull($row);
        $this->assertSame('<p>{= from_name =}</p>', $row->html);
        $this->assertSame('{= from_name =}', $row->plain);
        $this->assertSame('["alice@example.org"]', $row->mbox_exclude);
        $this->assertSame('[]', $row->alias_domain_exclude);
        $this->assertSame(1, (int) $row->skip_replies);
    }

    public function test_aliases_alias_domains_letter_case_and_duplicates(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $f->aliasDomain('xn--mnchen-3ya.de', 'example.org');
        $f->alias('info@xn--mnchen-3ya.de', 'x@external.example');
        $f->mailbox('alice@example.org');
        $this->asAdmin();

        $data = $this->replace([
            'excludedAddresses' => ['ALICE@Example.org', 'alice@example.org', 'info@münchen.de'],
            'excludedAliasDomains' => ['MÜNCHEN.de', 'xn--mnchen-3ya.de'],
        ])->assertOk()->json('data');

        $this->assertSame(['alice@example.org', 'info@xn--mnchen-3ya.de'], $data['excludedAddresses']);
        $this->assertSame(['xn--mnchen-3ya.de'], $data['excludedAliasDomains']);
    }

    public function test_entries_that_do_not_belong_to_the_domain_are_refused_by_position(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('other.example');
        $f->mailbox('bob@other.example');
        $f->aliasDomain('foreign-alias.example', 'other.example');
        $f->mailbox('alice@example.org');
        $f->domainFooter('example.org', 'keep', 'keep');
        $this->asAdmin();

        $errors = $this->replace([
            'html' => 'new',
            'excludedAddresses' => ['alice@example.org', 'bob@other.example', 'nobody@example.org', 'not an address'],
            'excludedAliasDomains' => ['foreign-alias.example'],
        ])->assertStatus(422)->json('errors');

        $this->assertArrayNotHasKey('excludedAddresses.0', $errors);
        $this->assertSame('notFound', $errors['excludedAddresses.1'][0]['code']);
        $this->assertSame('notFound', $errors['excludedAddresses.2'][0]['code']);
        $this->assertSame(['format' => 'email'], $errors['excludedAddresses.3'][0]['params']);
        $this->assertSame('notFound', $errors['excludedAliasDomains.0'][0]['code']);
        $this->assertSame('keep', DB::table('domain_wide_footer')->where('domain', 'example.org')->value('html'));
    }

    public function test_a_stale_version_and_the_tag_of_an_absent_footer(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();
        $absent = (string) $this->getJson('/api/v2/domains/example.org/footer')->headers->get('ETag');

        $saved = $this->replace([], 'example.org', ['If-Match' => $absent])->assertOk();
        $this->assertSame($absent, $saved->headers->get('ETag'));

        $this->replace(['html' => 'x'], 'example.org', ['If-Match' => $absent])->assertOk();
        $this->replace(['html' => 'y'], 'example.org', ['If-Match' => $absent])->assertStatus(412);
        $this->assertSame('x', DB::table('domain_wide_footer')->where('domain', 'example.org')->value('html'));
    }

    public function test_alias_domains_foreign_domains_and_roles(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('foreign.example');
        $this->mailcow()->aliasDomain('alias.example', 'example.org');
        $this->asDomainAdmin(['example.org']);

        $this->getJson('/api/v2/domains/alias.example/footer')->assertNotFound();
        $this->replace([], 'alias.example')->assertNotFound();
        $this->getJson('/api/v2/domains/foreign.example/footer')->assertNotFound();

        $this->forgetCookies();
        $key = $this->apiKey('reseller', 'readOnly');
        $this->getJson('/api/v2/domains/example.org/footer', $this->keyHeader($key))->assertOk();
        $this->putJson('/api/v2/domains/example.org/footer', ['html' => '', 'plain' => '', 'skipReplies' => false], $this->keyHeader($key))
            ->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->asMailboxUser('user@example.org');
        $this->getJson('/api/v2/domains/example.org/footer')->assertStatus(403);
    }

    public function test_texts_are_limited_to_one_mebibyte(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        // 349,526 three-byte characters: under the contract's character limit, over 1 MiB.
        $this->replace(['html' => str_repeat('€', 349526)])->assertStatus(422)
            ->assertJsonPath('errors.html.0.code', 'tooLong')
            ->assertJsonPath('errors.html.0.params', ['max' => 1048576, 'unit' => 'bytes']);
        $this->replace(['plain' => str_repeat('a', 1048576)])->assertOk();
    }

    public function test_a_stored_exclusion_that_no_longer_belongs_is_shown_and_must_be_removed(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domainFooter('example.org', 'f', 'f', ['gone@example.org']);
        $this->asAdmin();

        $this->assertSame(['gone@example.org'], $this->getJson('/api/v2/domains/example.org/footer')->json('data.excludedAddresses'));
        $this->replace(['html' => 'g', 'excludedAddresses' => ['gone@example.org']])->assertStatus(422);
        $this->replace(['html' => 'g'])->assertOk();
    }
}
