<?php

namespace Tests\Feature\Domains;

use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T085 (FR-057, SC-007): one domainFooterUpdate record per save, texts only as sizes.
 */
class DomainFooterAuditTest extends TestCase
{
    use DomainTestHelpers;

    public function test_one_record_per_save_without_the_texts(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->mailbox('alice@example.org');
        $this->mailcow()->domainFooter('example.org', 'old html', 'old');
        $this->asAdmin();

        $this->putJson('/api/v2/domains/example.org/footer', ['html' => 'secret-new-html', 'plain' => 'old', 'skipReplies' => true, 'excludedAddresses' => ['alice@example.org']], $this->csrf())->assertOk();

        $records = $this->auditOf('domainFooterUpdate');
        $this->assertCount(1, $records);
        $record = $records[0];
        $this->assertSame('domainFooter', $record->target_type);
        $this->assertSame('example.org', $record->target_id);
        $this->assertSame(['changed' => true, 'oldSize' => 8, 'newSize' => 15], $record->details['changes']['html']);
        $this->assertSame(['changed' => false, 'oldSize' => 3, 'newSize' => 3], $record->details['changes']['plain']);
        $this->assertSame(['old' => false, 'new' => true], $record->details['changes']['skipReplies']);
        $this->assertSame(['old' => [], 'new' => ['alice@example.org']], $record->details['changes']['excludedAddresses']);
        $this->assertArrayNotHasKey('excludedAliasDomains', $record->details['changes']);
        $this->assertStringNotContainsString('secret-new-html', json_encode($record) ?: '');
    }

    public function test_refusals_are_audited(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $this->putJson('/api/v2/domains/example.org/footer', ['html' => '', 'plain' => '', 'skipReplies' => false, 'excludedAddresses' => ['nobody@example.org']], $this->csrf())->assertStatus(422);

        $record = $this->auditOf('domainFooterUpdate')[0];
        $this->assertSame('validationFailed', $record->failure_reason);
        $this->assertSame('domainFooter', $record->target_type);
    }
}
