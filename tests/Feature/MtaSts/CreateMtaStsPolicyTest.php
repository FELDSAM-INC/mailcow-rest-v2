<?php

namespace Tests\Feature\MtaSts;

use App\Audit\AuditEventType;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use stdClass;
use Tests\Support\Audit\FlakyAuditLogger;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T073 (US4, FR-032 to FR-037): a policy is created for a domain in the caller's scope,
 * stored exactly as legacy stores it, refused for an alias domain's name and for a domain that has
 * one, and audited in the transaction of its row.
 */
class CreateMtaStsPolicyTest extends TestCase
{
    use DkimTestHelpers;

    private const BODY = ['domain' => 'example.org', 'mode' => 'enforce', 'mxHosts' => ['mail.example.org'], 'maxAge' => 86400, 'active' => true];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('foreign.example');
        $this->mailcow()->aliasDomain('alias.example', 'example.org');
    }

    private function row(string $domain = 'example.org'): ?stdClass
    {
        $row = DB::table('mta_sts')->where('domain', $domain)->first();

        return $row instanceof stdClass ? $row : null;
    }

    public function test_a_policy_is_stored_as_legacy_stores_it(): void
    {
        $this->asAdmin();

        $response = $this->send('POST', '/api/v2/mta-sts-policies', self::BODY)->assertCreated()
            ->assertHeader('Location', '/api/v2/mta-sts-policies/example.org')
            ->assertJsonPath('data.domain', 'example.org')
            ->assertJsonPath('data.displayDomain', 'example.org')
            ->assertJsonPath('data.version', 'STSv1')
            ->assertJsonPath('data.mode', 'enforce')
            ->assertJsonPath('data.mxHosts', ['mail.example.org'])
            ->assertJsonPath('data.maxAge', 86400)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.policyUrl', 'https://mta-sts.example.org/.well-known/mta-sts.txt')
            ->assertJsonPath('data.policyText', "version: STSv1\nmode: enforce\nmax_age: 86400\nmx: mail.example.org\n");

        $row = $this->row();
        $this->assertNotNull($row);
        $this->assertSame(['stsv1', 'enforce', 'mail.example.org', '86400', 1], [$row->version, $row->mode, $row->mx, $row->max_age, (int) $row->active]);
        $this->assertMatchesRegularExpression('/^\d{14}$/', (string) $row->id);

        $id = (string) $row->id;
        $response->assertJsonPath('data.policyId', $id)
            ->assertJsonPath('data.dnsRecords', [
                ['name' => '_mta-sts.example.org', 'type' => 'TXT', 'value' => "v=STSv1;id={$id};"],
                ['name' => 'mta-sts.example.org', 'type' => 'CNAME', 'value' => 'mail.example.org'],
            ]);
        $response->assertHeader('ETag', (string) $response->json('data.etag'));

        $record = $this->onlyAudit('mtaStsPolicyCreation');
        $this->assertSame(['success', 'mtaStsPolicy', 'example.org'], [$record->outcome, $record->target_type, $record->target_id]);
        $this->assertSame(['version' => 'STSv1', 'mode' => 'enforce', 'mxHosts' => ['mail.example.org'], 'maxAge' => 86400, 'active' => true, 'policyId' => $id], $record->details);
    }

    public function test_the_defaults_are_stsv1_and_inactive(): void
    {
        $this->asAdmin();

        $this->send('POST', '/api/v2/mta-sts-policies', ['domain' => 'example.org', 'mode' => 'testing', 'mxHosts' => ['mail.example.org'], 'maxAge' => 604800])->assertCreated()
            ->assertJsonPath('data.active', false);

        $this->assertSame(['stsv1', 0], [$this->row()?->version, (int) $this->row()?->active]);
    }

    public function test_invalid_values_are_refused_with_their_rule(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();
        $post = fn (array $changes) => $this->send('POST', '/api/v2/mta-sts-policies', array_merge(self::BODY, $changes))->assertStatus(422);

        $post(['maxAge' => 0])->assertJsonPath('errors.maxAge.0.code', 'outOfRange')->assertJsonPath('errors.maxAge.0.params', ['min' => 1, 'max' => 31557600]);
        $post(['maxAge' => 31557601])->assertJsonPath('errors.maxAge.0.code', 'outOfRange');
        $post(['maxAge' => '86400'])->assertJsonPath('errors.maxAge.0.code', 'invalidType');
        $post(['mxHosts' => []])->assertJsonPath('errors.mxHosts.0.code', 'required');
        $violation = $post(['mxHosts' => ['mail.example.org', 'mail..example']])->json('errors')['mxHosts.1'][0];
        $this->assertSame(['invalidFormat', ['format' => 'mxHost']], [$violation['code'], $violation['params']]);
        $post(['mode' => 'strict'])->assertJsonPath('errors.mode.0.code', 'notInList')->assertJsonPath('errors.mode.0.params.allowed', ['enforce', 'testing', 'none']);
        $post(['version' => 'STSv2'])->assertJsonPath('errors.version.0.code', 'notInList')->assertJsonPath('errors.version.0.params.allowed', ['STSv1']);
        $post(['mxHosts' => array_map(fn (int $i): string => sprintf('mx%02d.example-host.org', $i), range(1, 13))])
            ->assertJsonPath('errors.mxHosts.0.code', 'tooLong')->assertJsonPath('errors.mxHosts.0.params', ['max' => 255]);
        $post(['mxHosts' => array_map(fn (int $i): string => "m{$i}.org", range(1, 129))])->assertJsonPath('errors.mxHosts.0.code', 'tooMany');
        $post(['active' => 1])->assertJsonPath('errors.active.0.code', 'invalidType');
        $this->send('POST', '/api/v2/mta-sts-policies', ['domain' => 'example.org'])->assertStatus(422)
            ->assertJsonPath('errors.mode.0.code', 'required')->assertJsonPath('errors.maxAge.0.code', 'required')->assertJsonPath('errors.mxHosts.0.code', 'required');

        $this->assertNull($this->row());
        $this->assertCount(11, $this->auditOfType('mtaStsPolicyCreation'));
        $this->assertSame('validationFailed', $this->auditOfType('mtaStsPolicyCreation')[0]->failure_reason);
    }

    public function test_hosts_are_normalised_and_deduplicated_and_values_accepted_in_any_case(): void
    {
        $this->asAdmin();

        $this->send('POST', '/api/v2/mta-sts-policies', array_merge(self::BODY, [
            'mxHosts' => ['MAIL.Example.ORG', 'mail.example.org', '*.example.org', ' mx.BÜCHER.example '],
            'mode' => 'ENFORCE',
            'version' => 'stsv1',
        ]))->assertCreated()
            ->assertJsonPath('data.mxHosts', ['mail.example.org', '*.example.org', 'mx.xn--bcher-kva.example'])
            ->assertJsonPath('data.mode', 'enforce');

        $this->assertSame(['mail.example.org,*.example.org,mx.xn--bcher-kva.example', 'enforce', 'stsv1'], [$this->row()?->mx, $this->row()?->mode, $this->row()?->version]);
    }

    public function test_a_second_policy_is_a_conflict(): void
    {
        $this->mailcow()->mtaSts('example.org');
        $this->asAdmin();

        $this->send('POST', '/api/v2/mta-sts-policies', self::BODY)->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/mta-sts-policy-exists');

        $this->assertSame(['failure', 'conflict'], [$this->onlyAudit('mtaStsPolicyCreation')->outcome, $this->onlyAudit('mtaStsPolicyCreation')->failure_reason]);
    }

    public function test_an_alias_domain_has_no_policy_of_its_own(): void
    {
        DB::table('mta_sts')->insert(['id' => 20240101000000, 'domain' => 'alias.example', 'version' => 'stsv1', 'mode' => 'none', 'mx' => 'x.example', 'max_age' => '1', 'active' => 1]);
        $this->asAdmin();

        $this->send('POST', '/api/v2/mta-sts-policies', array_merge(self::BODY, ['domain' => 'alias.example']))->assertNotFound();
        $this->getJson('/api/v2/mta-sts-policies/alias.example')->assertNotFound();
        $this->getJson('/api/v2/mta-sts-policies')->assertOk()->assertJsonPath('meta.total', 0);

        $this->assertSame(['none', 'x.example'], [$this->row('alias.example')?->mode, $this->row('alias.example')?->mx]);
    }

    public function test_a_domain_administrator_creates_for_own_domains_only(): void
    {
        $this->asDomainAdmin(['example.org']);

        $this->send('POST', '/api/v2/mta-sts-policies', array_merge(self::BODY, ['domain' => 'foreign.example']))->assertNotFound();
        $this->send('POST', '/api/v2/mta-sts-policies', array_merge(self::BODY, ['domain' => 'Example.ORG']))->assertCreated();

        $this->assertNull($this->row('foreign.example'));
        $this->assertSame(['notFound', null], array_column($this->auditOfType('mtaStsPolicyCreation'), 'failure_reason'));
    }

    public function test_a_failed_audit_record_leaves_no_row(): void
    {
        $this->asAdmin();
        $this->app->instance(AuditLogger::class, new FlakyAuditLogger(failingTypes: [AuditEventType::MtaStsPolicyCreation]));

        $this->send('POST', '/api/v2/mta-sts-policies', self::BODY)->assertStatus(500);

        $this->assertNull($this->row());
    }
}
