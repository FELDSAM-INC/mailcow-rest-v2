<?php

namespace Tests\Feature\MtaSts;

use Illuminate\Support\Facades\DB;
use stdClass;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T074 (US4, FR-033 to FR-035, FR-051): a JSON Merge Patch changes the members sent; a
 * change of the version, the mode, the MX hosts (order included) or the maximum age renews the
 * policy identifier, always to a different value; a change of only the active state, or none at
 * all, keeps it.
 */
class UpdateMtaStsPolicyTest extends TestCase
{
    use DkimTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('nopolicy.example');
        $this->mailcow()->mtaSts('example.org', 'enforce', ['mail.example.org', 'mx2.example.org'], 86400, 1, '20261018120000');
    }

    private function row(): stdClass
    {
        $row = DB::table('mta_sts')->where('domain', 'example.org')->first();
        $this->assertInstanceOf(stdClass::class, $row);

        return $row;
    }

    private function patchPolicy(array $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->send('PATCH', '/api/v2/mta-sts-policies/example.org', $body, $headers);
    }

    public function test_a_content_change_renews_the_identifier_and_an_active_change_keeps_it(): void
    {
        $this->asAdmin();

        $mode = $this->patchPolicy(['mode' => 'testing'])->assertOk()->assertJsonPath('data.mode', 'testing');
        $afterMode = (string) $mode->json('data.policyId');
        $this->assertNotSame('20261018120000', $afterMode);
        $this->assertSame([$afterMode, 'testing'], [(string) $this->row()->id, $this->row()->mode]);

        $active = $this->patchPolicy(['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->assertSame($afterMode, $active->json('data.policyId'));
        $this->assertSame(0, (int) $this->row()->active);

        $order = (string) $this->patchPolicy(['mxHosts' => ['mx2.example.org', 'mail.example.org']])->assertOk()->json('data.policyId');
        $age = (string) $this->patchPolicy(['maxAge' => 604800])->assertOk()->json('data.policyId');
        $version = (string) $this->patchPolicy(['version' => 'STSv1', 'mode' => 'none'])->assertOk()->json('data.policyId');

        // All within the same second of the fake clock: each identifier is still a new one.
        $this->assertCount(4, array_unique([$afterMode, $order, $age, $version]));
        $this->assertSame(['mx2.example.org,mail.example.org', '604800', 'none'], [$this->row()->mx, $this->row()->max_age, $this->row()->mode]);
    }

    public function test_a_patch_that_changes_nothing_keeps_the_identifier_and_tag_and_is_not_audited(): void
    {
        $this->asAdmin();
        $before = $this->getJson('/api/v2/mta-sts-policies/example.org')->assertOk();

        $this->patchPolicy(['mode' => 'ENFORCE', 'active' => true, 'mxHosts' => ['mail.example.org', 'MX2.example.org']])->assertOk()
            ->assertJsonPath('data.policyId', '20261018120000')
            ->assertHeader('ETag', (string) $before->headers->get('ETag'));

        $this->assertSame([], $this->auditOfType('mtaStsPolicyUpdate'));
    }

    public function test_an_outdated_version_tag_is_refused(): void
    {
        $this->asAdmin();
        $tag = (string) $this->getJson('/api/v2/mta-sts-policies/example.org')->headers->get('ETag');

        // A legacy edit changes the stored row, and so the tag.
        DB::table('mta_sts')->where('domain', 'example.org')->update(['max_age' => '3600']);
        $this->patchPolicy(['mode' => 'testing'], ['If-Match' => $tag])->assertStatus(412);
        $this->assertSame('enforce', $this->row()->mode);

        $current = (string) $this->getJson('/api/v2/mta-sts-policies/example.org')->headers->get('ETag');
        $this->patchPolicy(['mode' => 'testing'], ['If-Match' => $current])->assertOk();
        $this->assertSame('versionOutdated', $this->auditOfType('mtaStsPolicyUpdate')[0]->failure_reason);
    }

    public function test_members_not_sent_keep_their_values(): void
    {
        $this->asAdmin();

        $this->patchPolicy(['maxAge' => 1])->assertOk()
            ->assertJsonPath('data.mode', 'enforce')
            ->assertJsonPath('data.mxHosts', ['mail.example.org', 'mx2.example.org'])
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.maxAge', 1);
    }

    public function test_an_invalid_patch_is_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->patchPolicy([])->assertStatus(422)->assertJsonPath('errors.body.0.code', 'required');
        $this->patchPolicy(['mode' => null])->assertStatus(422)->assertJsonPath('errors.mode.0.code', 'invalidType');
        $this->patchPolicy(['mxHosts' => null, 'maxAge' => null, 'active' => null, 'version' => null])->assertStatus(422)
            ->assertJsonPath('errors.mxHosts.0.code', 'invalidType')
            ->assertJsonPath('errors.maxAge.0.code', 'invalidType')
            ->assertJsonPath('errors.active.0.code', 'invalidType')
            ->assertJsonPath('errors.version.0.code', 'invalidType');
        $this->patchPolicy(['maxAge' => 31557601, 'mxHosts' => []])->assertStatus(422)
            ->assertJsonPath('errors.maxAge.0.code', 'outOfRange')
            ->assertJsonPath('errors.mxHosts.0.code', 'required');

        $this->assertSame('20261018120000', (string) $this->row()->id);
    }

    public function test_a_domain_without_a_policy_is_not_found(): void
    {
        $this->asAdmin();

        $this->send('PATCH', '/api/v2/mta-sts-policies/nopolicy.example', ['mode' => 'testing'])->assertNotFound();
        $this->send('PATCH', '/api/v2/mta-sts-policies/nothing.example', ['mode' => 'testing'])->assertNotFound();
    }

    public function test_a_domain_administrator_changes_an_own_domain_s_policy(): void
    {
        $this->asDomainAdmin(['example.org']);

        $this->patchPolicy(['mode' => 'testing'])->assertOk();

        $this->assertSame('testing', $this->row()->mode);
    }

    public function test_the_audit_record_names_each_change_and_both_identifiers(): void
    {
        $this->asAdmin();

        $new = (string) $this->patchPolicy(['mode' => 'testing', 'active' => false, 'mxHosts' => ['mail.example.org']])->assertOk()->json('data.policyId');

        $record = $this->onlyAudit('mtaStsPolicyUpdate');
        $this->assertSame(['success', 'mtaStsPolicy', 'example.org'], [$record->outcome, $record->target_type, $record->target_id]);
        $this->assertSame([
            'mode' => ['old' => 'enforce', 'new' => 'testing'],
            'mxHosts' => ['old' => ['mail.example.org', 'mx2.example.org'], 'new' => ['mail.example.org']],
            'active' => ['old' => true, 'new' => false],
        ], $record->details['changes']);
        $this->assertSame(['20261018120000', $new], [$record->details['oldPolicyId'], $record->details['newPolicyId']]);
    }
}
