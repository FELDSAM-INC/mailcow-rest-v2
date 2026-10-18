<?php

namespace Tests\Feature\Domains;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T061 (FR-041, FR-042, SC-006, US3 AS-3–AS-5): stale changes are refused, also after
 * changes made in the legacy UI; usage, rate limits and assignments do not outdate a version.
 */
class UpdateDomainVersionTest extends TestCase
{
    use DomainTestHelpers;

    private function etag(): string
    {
        return (string) $this->getJson('/api/v2/domains/example.org')->assertOk()->headers->get('ETag');
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return TestResponse<Response>
     */
    private function patchDomain(array $patch, ?string $ifMatch): TestResponse
    {
        $headers = $this->csrf();

        if ($ifMatch !== null) {
            $headers['If-Match'] = $ifMatch;
        }

        return $this->patchMerge('/api/v2/domains/example.org', $patch, $headers);
    }

    public function test_the_second_of_two_clients_is_refused(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();
        $read = $this->etag();

        $this->patchDomain(['maxMailboxes' => 20], $read)->assertOk();
        $this->patchDomain(['maxMailboxes' => 30], $read)->assertStatus(412)->assertJsonPath('type', 'about:blank');

        $this->assertSame(20, (int) $this->domainRow('example.org')?->mailboxes);
        $failures = array_values(array_filter($this->auditOf('domainUpdate'), fn (object $r): bool => $r->outcome === 'failure'));
        $this->assertCount(1, $failures);
        $this->assertSame('versionOutdated', $failures[0]->failure_reason);
    }

    public function test_changes_in_the_legacy_ui_outdate_the_version(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $read = $this->etag();
        DB::table('domain')->where('domain', 'example.org')->update(['active' => 0]);
        $this->patchDomain(['maxMailboxes' => 20], $read)->assertStatus(412);

        $read = $this->etag();
        $this->mailcow()->domainTags('example.org', ['legacy']);
        $this->patchDomain(['maxMailboxes' => 20], $read)->assertStatus(412);
    }

    public function test_usage_rate_limits_and_assignments_do_not_outdate_the_version(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->mailbox('a@example.org');
        $this->asAdmin();
        $read = $this->etag();

        $this->mailcow()->quota2('a@example.org', 1000, 1);
        $this->redis()->hSet('RL_VALUE', 'example.org', '10 / 1h');
        $this->mailcow()->domainAdmin('reseller', ['example.org']);

        $this->patchDomain(['maxMailboxes' => 20], $read)->assertOk();
    }

    public function test_without_if_match_or_with_a_wildcard_the_change_is_saved(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $this->patchDomain(['maxMailboxes' => 20], null)->assertOk();
        $this->patchDomain(['maxMailboxes' => 21], '*')->assertOk();
        $this->assertSame(21, (int) $this->domainRow('example.org')?->mailboxes);
    }
}
