<?php

namespace Tests\Feature\Domains;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use RuntimeException;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T052 (FR-057, SC-007): one audit record per creation, completed with the creation
 * report; refusals are audited once; a creation whose record cannot be written does not happen.
 */
class CreateDomainAuditTest extends TestCase
{
    use DomainTestHelpers;

    public function test_one_completed_record_per_creation(): void
    {
        $template = $this->mailcow()->defaultDomainTemplate(['key_size' => 1024]);
        $this->asAdmin();

        $response = $this->postJson('/api/v2/domains', ['name' => 'München.de', 'tags' => ['gold'], 'rateLimit' => ['value' => 10, 'frame' => 'hour']], $this->csrf())->assertCreated();

        $records = $this->auditOf('domainCreation');
        $this->assertCount(1, $records);
        $record = $records[0];
        $this->assertSame('success', $record->outcome);
        $this->assertSame('admin', $record->actor_username);
        $this->assertSame('domain', $record->target_type);
        $this->assertSame('xn--mnchen-3ya.de', $record->target_id);
        $this->assertSame('münchen.de', $record->target_name);
        $this->assertSame('127.0.0.1', $record->client_address);
        $this->assertSame($template, $record->details['templateId']);
        $this->assertSame(['gold'], $record->details['values']['tags']);
        $this->assertSame(10 * 1, $record->details['values']['rateLimit']['value']);
        $this->assertSame(10240 * self::MIB, $record->details['values']['domainQuota']);
        $this->assertSame($response->json('data.creationReport'), $record->details['creationReport']);
        $this->assertNotSame('pending', $record->details['creationReport']);

        // The DKIM key has its own history record (feature 010 research R10).
        $this->assertCount(1, $this->auditOf('dkimKeyGeneration'));
        $this->assertSame($record->request_id, $this->auditOf('dkimKeyGeneration')[0]->request_id);
    }

    public function test_refused_creations_are_audited_once(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $this->postJson('/api/v2/domains', ['name' => 'example.org'], $this->csrf())->assertStatus(409);
        $this->postJson('/api/v2/domains', ['name' => 'exa_mple.org'], $this->csrf())->assertStatus(422);

        $records = $this->auditOf('domainCreation');
        $this->assertCount(2, $records);
        $this->assertSame(['conflict', 'validationFailed'], array_column($records, 'failure_reason'));
        $this->assertSame('/api/v2/problems/domain-exists', $records[0]->details['problemType']);
        $this->assertSame('/api/v2/problems/validation-failed', $records[1]->details['problemType']);
        $this->assertSame([['code' => 'invalidFormat', 'params' => ['format' => 'domainName']]], $records[1]->details['errors']['name']);
        $this->assertSame('example.org', $records[0]->target_id);
    }

    public function test_a_creation_whose_record_cannot_be_written_does_not_happen(): void
    {
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(AuditEventType $type, bool $success, Principal|array|null $actor, ?array $target, ?string $authMethod, ?string $failureReason, array $details): AuditEvent
            {
                throw new RuntimeException('the audit table is unavailable');
            }
        });
        $this->asAdmin();

        $this->postJson('/api/v2/domains', ['name' => 'example.org', 'dkimSelector' => ''], $this->csrf())->assertStatus(500);

        $this->assertNull($this->domainRow('example.org'));
        $this->assertFalse($this->redis()->hGet('DOMAIN_MAP', 'example.org'));
        $this->assertSame(0, $this->memcached()->flushCount());
    }
}
