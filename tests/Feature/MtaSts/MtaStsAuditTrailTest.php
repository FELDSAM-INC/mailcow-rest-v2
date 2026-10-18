<?php

namespace Tests\Feature\MtaSts;

use App\Auth\Role;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T097 (US8 AS2, FR-054, FR-055): a domain administrator's mode change is recorded with
 * the old and new mode and policy identifier, and the history of a policy lists its creation and
 * changes, also when the domain is given in Unicode.
 */
class MtaStsAuditTrailTest extends TestCase
{
    use DkimTestHelpers;

    public function test_the_history_of_a_policy(): void
    {
        $this->mailcow()->domain('xn--bcher-kva.example');
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->asDomainAdmin(['xn--bcher-kva.example']);

        $created = (string) $this->send('POST', '/api/v2/mta-sts-policies', ['domain' => 'bücher.example', 'mode' => 'testing', 'mxHosts' => ['mail.example.org'], 'maxAge' => 600])->assertCreated()->json('data.policyId');
        $this->clock()->advance(2);
        $changed = (string) $this->send('PATCH', '/api/v2/mta-sts-policies/xn--bcher-kva.example', ['mode' => 'enforce'])->assertOk()->json('data.policyId');

        $this->signInAs('admin', Role::Admin);
        $response = $this->getJson('/api/v2/audit-events?targetType=mtaStsPolicy&targetId='.rawurlencode('BÜCHER.example'))->assertOk();
        $events = (array) $response->json('data');

        $this->assertSame(['mtaStsPolicyUpdate', 'mtaStsPolicyCreation'], array_column($events, 'eventType'));
        $this->assertSame('reseller', $events[0]['actor']['username']);
        $this->assertSame(['mode' => ['old' => 'testing', 'new' => 'enforce']], $events[0]['details']['changes']);
        $this->assertSame([$created, $changed], [$events[0]['details']['oldPolicyId'], $events[0]['details']['newPolicyId']]);
        $this->assertNotSame($created, $changed);
        $this->assertSame('testing', $events[1]['details']['mode']);
    }
}
