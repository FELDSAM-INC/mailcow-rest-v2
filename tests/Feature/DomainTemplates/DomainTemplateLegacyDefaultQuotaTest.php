<?php

namespace Tests\Feature\DomainTemplates;

use Database\Factories\MailcowRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Owner decision 2026-10-18: the default mailbox quota of domain templates (`def_quota_for_mbox`)
 * is an upstream leftover. The API does not expose it, but keeps it valid for legacy's add-domain
 * form: a creation stores the template's maximum mailbox quota, an edit lowers a larger value to the
 * maximum (and replaces a missing or zero one with it); nothing of this is audited as a change.
 */
class DomainTemplateLegacyDefaultQuotaTest extends TestCase
{
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
     * @return TestResponse<Response>
     */
    private function patchTemplate(int $id, array $patch): TestResponse
    {
        return $this->patchMerge('/api/v2/domain-templates/'.$id, $patch, $this->csrf());
    }

    /**
     * @phpstan-impure
     */
    private function storedDefault(int $id): mixed
    {
        $attributes = (array) json_decode((string) DB::table('templates')->where('id', $id)->value('attributes'), true);

        return $attributes['def_quota_for_mbox'] ?? null;
    }

    public function test_templates_do_not_expose_the_default_mailbox_quota(): void
    {
        $id = $this->mailcow()->defaultDomainTemplate();
        $this->asAdmin();

        $this->assertArrayNotHasKey('defaultMailboxQuota', $this->getJson('/api/v2/domain-templates/'.$id)->assertOk()->json('data'));
        $this->assertArrayNotHasKey('defaultMailboxQuota', $this->getJson('/api/v2/domain-templates')->assertOk()->json('data.0'));
        $this->assertArrayNotHasKey('defaultMailboxQuota', $this->createTemplate(['name' => 'New'])->assertCreated()->json('data'));
        $this->assertArrayNotHasKey('defaultMailboxQuota', $this->patchTemplate($id, ['maxMailboxes' => 3])->assertOk()->json('data'));
        $this->assertArrayNotHasKey('defaultMailboxQuota', $this->getJson('/api/v2/capabilities/domains')->assertOk()->json('data.templateDefaults'));
    }

    public function test_a_creation_stores_the_maximum_mailbox_quota_as_the_legacy_default(): void
    {
        $this->asAdmin();

        $small = (int) $this->createTemplate(['name' => 'Small', 'maxMailboxQuota' => 2048 * self::MIB])->assertCreated()->json('data.id');
        $plain = (int) $this->createTemplate(['name' => 'Plain'])->assertCreated()->json('data.id');
        // A sent value is ignored like any undeclared member.
        $sent = (int) $this->createTemplate(['name' => 'Sent', 'defaultMailboxQuota' => 20480 * self::MIB, 'maxMailboxQuota' => 1024 * self::MIB])->assertCreated()->json('data.id');

        $this->assertSame(2048 * self::MIB, $this->storedDefault($small));
        $this->assertSame(10240 * self::MIB, $this->storedDefault($plain));
        $this->assertSame(1024 * self::MIB, $this->storedDefault($sent));

        foreach ($this->auditOf('domainTemplateCreation') as $record) {
            $this->assertArrayNotHasKey('defaultMailboxQuota', $record->details['values']);
        }
    }

    public function test_an_edit_lowers_a_larger_legacy_default_to_the_maximum_without_auditing_it(): void
    {
        $id = $this->mailcow()->domainTemplate('Reseller', MailcowRows::DEFAULT_DOMAIN_TEMPLATE);
        $this->asAdmin();

        $this->patchTemplate($id, ['maxMailboxQuota' => 1024 * self::MIB])->assertOk()->assertJsonPath('data.maxMailboxQuota', 1024 * self::MIB);

        $this->assertSame(1024 * self::MIB, $this->storedDefault($id));
        $this->assertSame(
            ['maxMailboxQuota' => ['old' => 10240 * self::MIB, 'new' => 1024 * self::MIB]],
            $this->auditOf('domainTemplateUpdate')[0]->details['changes'],
        );
    }

    public function test_an_edit_keeps_a_legacy_default_within_the_maximum(): void
    {
        $id = $this->mailcow()->domainTemplate('Reseller', MailcowRows::DEFAULT_DOMAIN_TEMPLATE);
        $this->asAdmin();

        $this->patchTemplate($id, ['maxMailboxQuota' => 20480 * self::MIB, 'domainQuota' => 20480 * self::MIB])->assertOk();
        $this->assertSame(3072 * self::MIB, $this->storedDefault($id));

        $this->patchTemplate($id, ['name' => 'Renamed', 'defaultMailboxQuota' => 1024 * self::MIB])->assertOk();
        $this->assertSame(3072 * self::MIB, $this->storedDefault($id));
    }

    public function test_an_edit_replaces_a_missing_or_zero_legacy_default_with_the_maximum(): void
    {
        // Legacy's template edit stores 0 for an omitted value.
        $zero = $this->mailcow()->domainTemplate('Zero', array_merge(MailcowRows::DEFAULT_DOMAIN_TEMPLATE, ['def_quota_for_mbox' => 0]));
        $missing = $this->mailcow()->domainTemplate('Missing', ['max_quota_for_mbox' => 4096 * self::MIB]);
        $this->asAdmin();

        $this->patchTemplate($zero, ['maxAliases' => 5])->assertOk();
        $this->patchTemplate($missing, ['maxAliases' => 5])->assertOk();

        $this->assertSame(10240 * self::MIB, $this->storedDefault($zero));
        $this->assertSame(4096 * self::MIB, $this->storedDefault($missing));
    }

    public function test_legacys_add_form_gets_a_valid_default_from_api_templates(): void
    {
        $this->asAdmin();
        $id = (int) $this->createTemplate(['name' => 'Api', 'maxMailboxQuota' => 5120 * self::MIB])->assertCreated()->json('data.id');

        // functions.mailbox.inc.php 514-538: default ≤ maximum mailbox quota, never 0.
        $attributes = (array) json_decode((string) DB::table('templates')->where('id', $id)->value('attributes'), true);
        $this->assertGreaterThan(0, $attributes['def_quota_for_mbox']);
        $this->assertLessThanOrEqual($attributes['max_quota_for_mbox'], $attributes['def_quota_for_mbox']);
    }
}
