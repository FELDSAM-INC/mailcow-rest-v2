<?php

namespace Tests\Feature\DomainTemplates;

use Database\Factories\MailcowRows;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T078 (FR-057): template creations, updates and deletions are audited, refusals too.
 */
class DomainTemplateAuditTest extends TestCase
{
    use DomainTestHelpers;

    public function test_changes_are_audited_with_values_and_changes(): void
    {
        $this->asAdmin();
        $id = (int) $this->postJson('/api/v2/domain-templates', ['name' => 'Small', 'maxMailboxes' => 5], $this->csrf())->assertCreated()->json('data.id');
        $this->patchMerge('/api/v2/domain-templates/'.$id, ['maxMailboxes' => 6, 'name' => 'Smaller'], $this->csrf())->assertOk();
        $this->deleteJson('/api/v2/domain-templates/'.$id, [], $this->csrf())->assertNoContent();

        $created = $this->auditOf('domainTemplateCreation')[0];
        $this->assertSame(['type' => 'domainTemplate', 'id' => (string) $id, 'name' => 'Small'], ['type' => $created->target_type, 'id' => $created->target_id, 'name' => $created->target_name]);
        $this->assertSame(5, $created->details['values']['maxMailboxes']);

        $updated = $this->auditOf('domainTemplateUpdate')[0];
        $this->assertSame(['old' => 5, 'new' => 6], $updated->details['changes']['maxMailboxes']);
        $this->assertSame(['old' => 'Small', 'new' => 'Smaller'], $updated->details['changes']['name']);
        $this->assertSame('Smaller', $updated->target_name);

        $deleted = $this->auditOf('domainTemplateDeletion')[0];
        $this->assertSame((string) $id, $deleted->target_id);
        $this->assertSame(6, $deleted->details['values']['maxMailboxes']);
    }

    public function test_refusals_are_audited(): void
    {
        $default = $this->mailcow()->defaultDomainTemplate();
        $other = $this->mailcow()->domainTemplate('Other', MailcowRows::DEFAULT_DOMAIN_TEMPLATE);
        $this->asAdmin();

        $this->deleteJson('/api/v2/domain-templates/'.$default, [], $this->csrf())->assertStatus(409);
        $this->patchMerge('/api/v2/domain-templates/'.$other, ['maxMailboxes' => 1], $this->csrf() + ['If-Match' => '"stale"'])->assertStatus(412);

        $this->assertSame('conflict', $this->auditOf('domainTemplateDeletion')[0]->failure_reason);
        $this->assertSame('Default', $this->auditOf('domainTemplateDeletion')[0]->target_name);
        $this->assertSame('versionOutdated', $this->auditOf('domainTemplateUpdate')[0]->failure_reason);
    }
}
