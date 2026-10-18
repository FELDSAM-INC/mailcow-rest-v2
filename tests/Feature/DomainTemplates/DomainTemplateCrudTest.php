<?php

namespace Tests\Feature\DomainTemplates;

use Database\Factories\MailcowRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T076 (FR-045–FR-050, US5): domain templates.
 */
class DomainTemplateCrudTest extends TestCase
{
    use ApiKeys;
    use DomainTestHelpers;

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function createTemplate(array $body): TestResponse
    {
        return $this->postJson('/api/v2/domain-templates', $body, $this->csrf());
    }

    /**
     * @param  array<string, mixed>  $patch
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function patchTemplate(int $id, array $patch, array $headers = []): TestResponse
    {
        return $this->patchMerge('/api/v2/domain-templates/'.$id, $patch, $this->csrf() + $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(int $id): array
    {
        return (array) json_decode((string) DB::table('templates')->where('id', $id)->value('attributes'), true);
    }

    public function test_the_list_shows_domain_templates_only_with_their_version_tags(): void
    {
        $default = $this->mailcow()->defaultDomainTemplate();
        $this->mailcow()->domainTemplate('Alpha', MailcowRows::DEFAULT_DOMAIN_TEMPLATE);
        $mailboxTemplate = $this->mailcow()->domainTemplate('Default', ['quota' => 0], 'mailbox');
        $this->asAdmin();

        $response = $this->getJson('/api/v2/domain-templates')->assertOk();

        $this->assertSame(['Alpha', 'Default'], array_column((array) $response->json('data'), 'name'));
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame([false, true], array_column((array) $response->json('data'), 'isDefault'));
        $this->assertSame(['Default', 'Alpha'], array_column((array) $this->getJson('/api/v2/domain-templates?sort=id')->json('data'), 'name'));
        $this->assertSame(['Default'], array_column((array) $this->getJson('/api/v2/domain-templates?q=fault')->json('data'), 'name'));
        $this->assertSame(['Default'], array_column((array) $this->getJson('/api/v2/domain-templates?order=desc&limit=1')->json('data'), 'name'));

        foreach ((array) $response->json('data') as $entry) {
            $this->assertSame($this->getJson('/api/v2/domain-templates/'.$entry['id'])->headers->get('ETag'), $entry['etag']);
        }

        $this->getJson('/api/v2/domain-templates/'.$mailboxTemplate)->assertNotFound();
        $this->getJson('/api/v2/domain-templates/'.$default)->assertOk()->assertJsonPath('data.isDefault', true);
    }

    public function test_a_new_template_gets_the_legacy_defaults(): void
    {
        config(['restv2.dkim.default_selector' => 'feldhost']);
        $this->asAdmin();

        $response = $this->createTemplate(['name' => 'Small', 'maxMailboxes' => 5, 'maxAliases' => 0])->assertCreated();

        $id = (int) $response->json('data.id');
        $response->assertHeader('Location', '/api/v2/domain-templates/'.$id);
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $data = $response->json('data');
        $this->assertSame(5, $data['maxMailboxes']);
        $this->assertSame(0, $data['maxAliases']);
        $this->assertArrayNotHasKey('defaultMailboxQuota', $data);
        $this->assertSame('feldhost', $data['dkimSelector']);
        $this->assertSame(2048, $data['dkimKeySize']);
        $this->assertNull($data['rateLimit']);
        $this->assertFalse($data['isDefault']);
        $this->assertSame([
            'tags', 'max_num_aliases_for_domain', 'max_num_mboxes_for_domain', 'def_quota_for_mbox', 'max_quota_for_mbox',
            'max_quota_for_domain', 'rl_frame', 'rl_value', 'active', 'gal', 'backupmx', 'relay_all_recipients',
            'relay_unknown_only', 'dkim_selector', 'key_size',
        ], array_keys($this->stored($id)));
        $this->assertSame(10240 * self::MIB, $this->stored($id)['def_quota_for_mbox'], 'The maximum mailbox quota, for legacy\'s add form.');
        $this->assertSame(0, $this->stored($id)['max_num_aliases_for_domain']);
    }

    public function test_names_are_unique_without_regard_to_letter_case(): void
    {
        $this->mailcow()->domainTemplate('Small', MailcowRows::DEFAULT_DOMAIN_TEMPLATE);
        $other = $this->mailcow()->domainTemplate('Other', MailcowRows::DEFAULT_DOMAIN_TEMPLATE);
        $this->asAdmin();

        $this->createTemplate(['name' => 'small'])->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/domain-template-name-exists');
        $this->patchTemplate($other, ['name' => 'SMALL'])->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/domain-template-name-exists');
        $this->assertSame('Other', DB::table('templates')->where('id', $other)->value('template'));
    }

    public function test_the_default_template_cannot_be_renamed_or_deleted(): void
    {
        $default = $this->mailcow()->defaultDomainTemplate();
        $this->asAdmin();

        $this->patchTemplate($default, ['name' => 'Standard'])->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/domain-template-protected');
        $this->deleteJson('/api/v2/domain-templates/'.$default, [], $this->csrf())->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/domain-template-protected');

        $this->patchTemplate($default, ['name' => 'Default', 'maxMailboxes' => 25])->assertOk()->assertJsonPath('data.maxMailboxes', 25);
        $this->assertSame('Default', DB::table('templates')->where('id', $default)->value('template'));
    }

    public function test_a_patch_keeps_every_value_it_does_not_send(): void
    {
        $id = $this->mailcow()->domainTemplate('Reseller', array_merge(MailcowRows::DEFAULT_DOMAIN_TEMPLATE, ['tags' => ['gold'], 'rl_value' => 10, 'rl_frame' => 'h', 'gal' => 0]));
        $this->asAdmin();

        $data = $this->patchTemplate($id, ['maxMailboxes' => 50])->assertOk()->json('data');

        $this->assertSame(50, $data['maxMailboxes']);
        $this->assertSame(['gold'], $data['tags']);
        $this->assertSame(['value' => 10, 'frame' => 'hour'], $data['rateLimit']);
        $this->assertFalse($data['globalAddressList']);
        $this->assertSame(400, $this->stored($id)['max_num_aliases_for_domain']);
    }

    public function test_values_follow_the_domain_rules(): void
    {
        $id = $this->mailcow()->domainTemplate('T', MailcowRows::DEFAULT_DOMAIN_TEMPLATE);
        $this->asAdmin();

        $errors = $this->createTemplate([
            'name' => 'Bad',
            'maxMailboxQuota' => 20480 * self::MIB,
            'domainQuota' => 1572864,
            'tags' => array_map(fn (int $i): string => "t{$i}", range(1, 26)),
        ])->assertStatus(422)->json('errors');
        $this->assertSame('mustNotExceed', $errors['maxMailboxQuota'][0]['code']);
        $this->assertSame('notMultipleOf', $errors['domainQuota'][0]['code']);
        $this->assertSame('tooMany', $errors['tags'][0]['code']);

        $this->patchTemplate($id, ['relayUnknownOnly' => true])->assertOk()
            ->assertJsonPath('data.backupMx', true)
            ->assertJsonPath('data.relayAllRecipients', true);
        $this->patchTemplate($id, ['rateLimit' => ['value' => 5, 'frame' => 'minute']])->assertOk()->assertJsonPath('data.rateLimit.value', 5);
        $this->patchTemplate($id, ['rateLimit' => null])->assertOk()->assertJsonPath('data.rateLimit', null);
        $this->assertSame('', $this->stored($id)['rl_value']);

        $etag = (string) $this->getJson('/api/v2/domain-templates/'.$id)->headers->get('ETag');
        $this->patchTemplate($id, ['maxMailboxes' => 1], ['If-Match' => $etag])->assertOk();
        $this->patchTemplate($id, ['maxMailboxes' => 2], ['If-Match' => $etag])->assertStatus(412);

        $errors = $this->patchTemplate($id, ['id' => 999, 'isDefault' => true, 'createdAt' => '2000-01-01T00:00:00Z'])->assertStatus(422)->json('errors');
        $this->assertSame(['immutable', 'immutable', 'immutable'], [$errors['id'][0]['code'], $errors['isDefault'][0]['code'], $errors['createdAt'][0]['code']]);
    }

    public function test_domains_keep_their_values_when_the_template_changes(): void
    {
        $id = $this->mailcow()->domainTemplate('Small', array_merge(MailcowRows::DEFAULT_DOMAIN_TEMPLATE, ['max_num_mboxes_for_domain' => 5, 'key_size' => 1024]));
        $this->asAdmin();

        $this->postJson('/api/v2/domains', ['name' => 'example.org', 'templateId' => $id, 'dkimSelector' => ''], $this->csrf())->assertCreated();
        $this->patchTemplate($id, ['maxMailboxes' => 99])->assertOk();
        $this->deleteJson('/api/v2/domain-templates/'.$id, [], $this->csrf())->assertNoContent();

        $this->assertSame(5, (int) $this->domainRow('example.org')?->mailboxes);
        $this->assertSame(0, DB::table('templates')->where('id', $id)->count());
    }

    public function test_domain_administrators_are_forbidden_and_read_only_keys_only_read(): void
    {
        $id = $this->mailcow()->defaultDomainTemplate();
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $this->getJson('/api/v2/domain-templates')->assertStatus(403);
        $this->getJson('/api/v2/domain-templates/'.$id)->assertStatus(403);
        $this->createTemplate(['name' => 'X'])->assertStatus(403);
        $this->patchTemplate($id, ['maxMailboxes' => 1])->assertStatus(403);
        $this->deleteJson('/api/v2/domain-templates/'.$id, [], $this->csrf())->assertStatus(403);
        $this->getJson('/api/v2/domain-templates/999999')->assertStatus(403);

        $this->forgetCookies();
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $key = $this->apiKey('admin', 'readOnly');
        $this->getJson('/api/v2/domain-templates', $this->keyHeader($key))->assertOk();
        $this->postJson('/api/v2/domain-templates', ['name' => 'X'], $this->keyHeader($key))->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
    }
}
