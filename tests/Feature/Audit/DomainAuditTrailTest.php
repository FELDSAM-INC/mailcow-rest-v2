<?php

namespace Tests\Feature\Audit;

use Illuminate\Support\Facades\DB;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T087 (FR-057, FR-058, US8): administrators trace domain, footer and template changes
 * with the generic target filters of listAuditEvents.
 */
class DomainAuditTrailTest extends TestCase
{
    use DomainTestHelpers;

    /**
     * @return list<array<string, mixed>>
     */
    private function events(string $query): array
    {
        return (array) $this->getJson('/api/v2/audit-events?'.$query)->assertOk()->json('data');
    }

    public function test_a_quota_raise_shows_actor_time_address_and_values(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $this->patchMerge('/api/v2/domains/example.org', ['domainQuota' => 20480 * self::MIB], $this->csrf())->assertOk();

        $event = $this->events('targetType=domain&targetId=example.org')[0];
        $this->assertSame('domainUpdate', $event['eventType']);
        $this->assertSame('admin', $event['actor']['username']);
        $this->assertSame('127.0.0.1', $event['clientAddress']);
        $this->assertNotEmpty($event['occurredAt']);
        $this->assertSame(['old' => 10240 * self::MIB, 'new' => 20480 * self::MIB], $event['details']['changes']['domainQuota']);
    }

    public function test_target_identifiers_are_normalised_domain_names(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('xn--mnchen-3ya.de');
        $this->asAdmin();
        $this->patchMerge('/api/v2/domains/example.org', ['maxMailboxes' => 11], $this->csrf())->assertOk();
        $this->putJson('/api/v2/domains/example.org/footer', ['html' => 'x', 'plain' => 'x', 'skipReplies' => false], $this->csrf())->assertOk();
        $this->patchMerge('/api/v2/domains/xn--mnchen-3ya.de', ['maxMailboxes' => 12], $this->csrf())->assertOk();
        $template = (int) $this->postJson('/api/v2/domain-templates', ['name' => 'Small'], $this->csrf())->assertCreated()->json('data.id');

        $this->assertSame(['domainUpdate'], array_column($this->events('targetType=domain&targetId=EXAMPLE.org'), 'eventType'));
        $this->assertSame(['domainUpdate'], array_column($this->events('targetType=domain&targetId='.rawurlencode('münchen.de')), 'eventType'));
        $this->assertEqualsCanonicalizing(['domainUpdate', 'domainFooterUpdate'], array_column($this->events('targetId=Example.ORG'), 'eventType'));
        $this->assertSame(['domainFooterUpdate'], array_column($this->events('targetType=domainFooter&targetId=example.org'), 'eventType'));
        $this->assertSame(['domainTemplateCreation'], array_column($this->events('targetType=domainTemplate&targetId='.$template), 'eventType'));
    }

    public function test_a_refused_edit_of_a_domain_administrator_is_listed_with_its_reason(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);
        $this->patchMerge('/api/v2/domains/example.org', ['maxMailboxes' => 99], $this->csrf())->assertStatus(403);

        $this->forgetCookies();
        $this->asAdmin();
        $event = $this->events('targetType=domain&targetId=example.org&outcome=failure')[0];

        $this->assertSame('reseller', $event['actor']['username']);
        $this->assertSame('attributeNotPermitted', $event['failureReason']);
        $this->assertSame('/api/v2/problems/attribute-not-permitted', $event['details']['problemType']);
    }

    public function test_domain_administrators_may_not_read_the_audit_log(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $this->getJson('/api/v2/audit-events?targetType=domain&targetId=example.org')->assertStatus(403);
    }

    public function test_an_invalid_target_type_matches_nothing(): void
    {
        $this->withoutRequestValidation();
        $this->mailcow()->domain('example.org');
        $this->asAdmin();
        $this->patchMerge('/api/v2/domains/example.org', ['maxMailboxes' => 11], $this->csrf())->assertOk();

        $this->assertSame([], $this->events('targetType=unknownThing&targetId=example.org'));
    }

    public function test_reads_write_no_records(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->defaultDomainTemplate();
        $this->asAdmin();

        $this->getJson('/api/v2/domains')->assertOk();
        $this->getJson('/api/v2/domains/example.org')->assertOk();
        $this->getJson('/api/v2/domains/example.org/deletion-preview')->assertOk();
        $this->getJson('/api/v2/domains/example.org/footer')->assertOk();
        $this->getJson('/api/v2/capabilities/domains')->assertOk();
        $this->getJson('/api/v2/domain-tags')->assertOk();
        $this->getJson('/api/v2/domain-templates')->assertOk();

        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }
}
