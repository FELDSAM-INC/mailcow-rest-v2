<?php

namespace Tests\Feature\Domains;

use Database\Factories\MailcowRows;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Owner decision 2026-10-18: a domain's default mailbox quota (`domain.defquota`) is an upstream
 * leftover. The API does not expose it, but keeps the column valid for the classic interface
 * (legacy requires 0 < default ≤ maximum mailbox quota): a creation writes the template's default
 * capped at the maximum mailbox quota, or the maximum when the template has none; an edit that
 * changes the maximum lowers a larger default to it; nothing of this is audited as a change.
 */
class LegacyDefaultQuotaTest extends TestCase
{
    use DomainTestHelpers;

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function create(array $body): TestResponse
    {
        return $this->postJson('/api/v2/domains', $body + ['dkimSelector' => ''], $this->csrf());
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return TestResponse<Response>
     */
    private function patchDomain(array $patch, string $domain = 'example.org'): TestResponse
    {
        return $this->patchMerge('/api/v2/domains/'.rawurlencode($domain), $patch, $this->csrf());
    }

    /**
     * @phpstan-impure
     */
    private function defquota(string $domain = 'example.org'): int
    {
        return (int) $this->domainRow($domain)?->defquota;
    }

    public function test_domains_do_not_expose_the_default_mailbox_quota(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $data = $this->getJson('/api/v2/domains/example.org')->assertOk()->json('data');
        $this->assertArrayNotHasKey('defaultMailboxQuota', $data);
        $this->assertArrayNotHasKey('defaultNewMailboxQuota', $data['usage']);

        $entry = $this->getJson('/api/v2/domains')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('defaultMailboxQuota', $entry);
        $this->assertArrayNotHasKey('defaultNewMailboxQuota', $entry['usage']);

        $created = $this->create(['name' => 'new.example'])->assertCreated()->json('data');
        $this->assertArrayNotHasKey('defaultMailboxQuota', $created);
        $this->assertArrayNotHasKey('defaultMailboxQuota', $this->patchDomain(['maxMailboxes' => 3])->assertOk()->json('data'));
    }

    public function test_a_creation_writes_the_template_default_capped_at_the_maximum(): void
    {
        $this->mailcow()->defaultDomainTemplate();
        $this->asAdmin();

        $this->create(['name' => 'example.org'])->assertCreated();
        $this->create(['name' => 'small.example', 'maxMailboxQuota' => 1024 * self::MIB])->assertCreated();

        $this->assertSame(3072, $this->defquota('example.org'));
        $this->assertSame(1024, $this->defquota('small.example'));

        foreach ($this->auditOf('domainCreation') as $record) {
            $this->assertArrayNotHasKey('defaultMailboxQuota', $record->details['values']);
        }
    }

    public function test_a_creation_writes_the_maximum_when_the_template_has_no_usable_default(): void
    {
        $zero = $this->mailcow()->domainTemplate('Zero', array_merge(MailcowRows::DEFAULT_DOMAIN_TEMPLATE, ['def_quota_for_mbox' => 0, 'max_quota_for_mbox' => 2048 * self::MIB]));
        $attributes = MailcowRows::DEFAULT_DOMAIN_TEMPLATE;
        unset($attributes['def_quota_for_mbox']);
        $missing = $this->mailcow()->domainTemplate('Missing', $attributes);
        $this->asAdmin();

        $this->create(['name' => 'zero.example', 'templateId' => $zero])->assertCreated();
        $this->create(['name' => 'missing.example', 'templateId' => $missing])->assertCreated();
        // No Default template: the built-in values apply.
        $this->create(['name' => 'builtin.example', 'maxMailboxQuota' => 5120 * self::MIB])->assertCreated();

        $this->assertSame(2048, $this->defquota('zero.example'));
        $this->assertSame(10240, $this->defquota('missing.example'));
        $this->assertSame(5120, $this->defquota('builtin.example'));
    }

    public function test_a_sent_default_mailbox_quota_is_ignored_like_any_undeclared_member(): void
    {
        $this->mailcow()->defaultDomainTemplate();
        $this->asAdmin();

        $this->create(['name' => 'example.org', 'defaultMailboxQuota' => 2048 * self::MIB, 'maxMailboxQuota' => 1024 * self::MIB])->assertCreated();
        $this->assertSame(1024, $this->defquota());

        $this->patchDomain(['defaultMailboxQuota' => 512 * self::MIB])->assertOk();
        $this->assertSame(1024, $this->defquota());
        $this->assertEquals([], $this->auditOf('domainUpdate')[0]->details['changes']);
    }

    public function test_lowering_the_maximum_lowers_a_larger_default_without_auditing_it(): void
    {
        $this->mailcow()->domain('example.org', attributes: ['defquota' => 3072, 'maxquota' => 10240, 'quota' => 10240]);
        $this->asAdmin();

        $this->patchDomain(['maxMailboxQuota' => 2048 * self::MIB])->assertOk()->assertJsonPath('data.maxMailboxQuota', 2048 * self::MIB);

        $this->assertSame(2048, $this->defquota());
        $this->assertSame(
            ['maxMailboxQuota' => ['old' => 10240 * self::MIB, 'new' => 2048 * self::MIB]],
            $this->auditOf('domainUpdate')[0]->details['changes'],
        );
    }

    public function test_other_edits_leave_the_default_unchanged(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org', attributes: ['defquota' => 3072, 'maxquota' => 10240, 'quota' => 10240]);
        // Rows the classic interface cannot produce: a default above the maximum, and no default.
        $f->domain('above.example', attributes: ['defquota' => 4096, 'maxquota' => 2048, 'quota' => 10240]);
        $f->domain('zero.example', attributes: ['defquota' => 0, 'maxquota' => 10240, 'quota' => 10240]);
        $this->asAdmin();

        $this->patchDomain(['maxMailboxQuota' => 20480 * self::MIB, 'domainQuota' => 20480 * self::MIB])->assertOk();
        $this->assertSame(3072, $this->defquota());

        $this->patchDomain(['description' => 'Untouched maximum'], 'above.example')->assertOk();
        $this->assertSame(4096, $this->defquota('above.example'));

        $this->patchDomain(['maxMailboxQuota' => 1024 * self::MIB], 'zero.example')->assertOk();
        $this->assertSame(0, $this->defquota('zero.example'));
    }

    public function test_the_default_mailbox_quota_is_an_invalid_sort_field(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->getJson('/api/v2/domains?sort=defaultMailboxQuota')->assertStatus(422)->assertJsonPath('errors.sort.0.code', 'notInList');
    }
}
