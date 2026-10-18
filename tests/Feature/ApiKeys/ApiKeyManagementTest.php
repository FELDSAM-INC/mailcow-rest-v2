<?php

namespace Tests\Feature\ApiKeys;

use App\Auth\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Auth\AuthRequests;
use Tests\TestCase;

/**
 * T083 (FR-032–FR-037, FR-058, SC-010, R19): administrators create, list, inspect and revoke API
 * keys. The secret is shown once, keys never create or revoke keys, and other roles are refused.
 */
class ApiKeyManagementTest extends TestCase
{
    use ApiKeys;
    use AuthRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->admin('admin', 'x');
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $this->mailcow()->mailboxWithDomain('alice@example.org');
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function create(array $body, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v2/api-keys', $body, ['X-CSRF-Token' => $this->csrfToken()] + $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function revoke(string $id, array $headers = []): TestResponse
    {
        return $this->postEmpty('/api/v2/api-keys/'.$id.'/revocation', ['X-CSRF-Token' => $this->csrfToken()] + $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function validBody(): array
    {
        return ['name' => 'Billing', 'ownerUsername' => 'reseller', 'accessLevel' => 'readWrite'];
    }

    public function test_an_admin_session_creates_a_key_and_sees_the_secret_once(): void
    {
        $this->freezeTime();
        $this->signInAs('admin', Role::Admin);

        $response = $this->create([
            'name' => '  Billing system ',
            'ownerUsername' => 'reseller',
            'accessLevel' => 'readWrite',
            'expiresAt' => CarbonImmutable::now('UTC')->addDays(30)->setTimezone('+02:00')->format('Y-m-d\TH:i:sP'),
            'allowedNetworks' => ['198.51.100.7', '192.0.2.0/24', '198.51.100.7/32', '2001:DB8::/32'],
        ])->assertCreated();

        $id = (string) $response->json('data.id');
        $secret = (string) $response->json('data.secret');
        $response->assertHeader('Location', '/api/v2/api-keys/'.$id);
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertMatchesRegularExpression('/^mcv2_[A-Za-z0-9]{40}$/', $secret);
        $response->assertJsonPath('data.prefix', substr($secret, 0, 12))
            ->assertJsonPath('data.name', 'Billing system')
            ->assertJsonPath('data.owner', ['username' => 'reseller', 'role' => 'domainAdmin', 'active' => true])
            ->assertJsonPath('data.accessLevel', 'readWrite')
            ->assertJsonPath('data.allowedNetworks', ['198.51.100.7/32', '192.0.2.0/24', '2001:db8::/32'])
            ->assertJsonPath('data.expiresAt', CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z'))
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.createdAt', CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'))
            ->assertJsonPath('data.createdBy', ['username' => 'admin'])
            ->assertJsonPath('data.lastUsedAt', null)
            ->assertJsonPath('data.lastUsedFrom', null)
            ->assertJsonPath('data.revokedAt', null)
            ->assertJsonPath('data.revokedBy', null);

        // Only the hash is stored.
        $row = (array) DB::table('restv2_api_keys')->where('id', $id)->first();
        $this->assertSame(hash('sha256', $secret), $row['secret_hash']);
        $this->assertStringNotContainsString($secret, (string) json_encode($row));

        // Later reads never contain the secret.
        $single = $this->getJson('/api/v2/api-keys/'.$id)->assertOk();
        $this->assertArrayNotHasKey('secret', $single->json('data'));
        $list = $this->getJson('/api/v2/api-keys')->assertOk();
        $this->assertArrayNotHasKey('secret', $list->json('data.0'));
        $this->assertStringNotContainsString($secret, (string) $single->getContent().$list->getContent());

        // Audited without the secret.
        $audit = $this->onlyAuditRecord('apiKeyCreation');
        $this->assertSame('success', $audit->outcome);
        $this->assertSame('admin', $audit->actor_username);
        $this->assertSame('admin', $audit->actor_role);
        $this->assertSame('password', $audit->auth_method);
        $this->assertSame(['apiKey', $id, 'Billing system'], [$audit->target_type, $audit->target_id, $audit->target_name]);
        $this->assertSame([
            'ownerUsername' => 'reseller',
            'accessLevel' => 'readWrite',
            'expiresAt' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z'),
            'allowedNetworks' => ['198.51.100.7/32', '192.0.2.0/24', '2001:db8::/32'],
        ], $audit->details);
        $serialised = (string) json_encode(DB::table('restv2_audit_events')->get());
        $this->assertStringNotContainsString($secret, $serialised);
        $this->assertStringNotContainsString(substr($secret, 12), $serialised);
    }

    public function test_the_owner_is_stored_with_its_canonical_username(): void
    {
        $this->signInAs('admin', Role::Admin);

        $this->create(['name' => 'x', 'ownerUsername' => 'RESELLER', 'accessLevel' => 'readOnly'])
            ->assertCreated()
            ->assertJsonPath('data.owner.username', 'reseller')
            ->assertJsonPath('data.expiresAt', null)
            ->assertJsonPath('data.allowedNetworks', []);
    }

    public function test_owner_expiry_and_network_values_are_validated(): void
    {
        $this->signInAs('admin', Role::Admin);

        $this->create(['ownerUsername' => 'nobody'] + $this->validBody())
            ->assertUnprocessable()
            ->assertJsonPath('type', '/api/v2/problems/validation-failed')
            ->assertJsonPath('errors.ownerUsername.0.code', 'notFound');
        $this->create(['ownerUsername' => 'alice@example.org'] + $this->validBody())
            ->assertUnprocessable()
            ->assertJsonPath('errors.ownerUsername.0.code', 'notEligible')
            ->assertJsonPath('errors.ownerUsername.0.params.allowedRoles', ['admin', 'domainAdmin']);
        $this->create(['expiresAt' => CarbonImmutable::now('UTC')->subMinute()->format('Y-m-d\TH:i:s\Z')] + $this->validBody())
            ->assertUnprocessable()
            ->assertJsonPath('errors.expiresAt.0.code', 'mustBeInFuture');
        $errors = (array) $this->create(['allowedNetworks' => ['192.0.2.1', '10.0.0.0/33']] + $this->validBody())
            ->assertUnprocessable()
            ->json('errors');
        $this->assertSame(['allowedNetworks.1'], array_keys($errors));
        $this->assertSame('invalidFormat', $errors['allowedNetworks.1'][0]['code']);
        $this->assertSame(['format' => 'ipNetwork'], $errors['allowedNetworks.1'][0]['params']);
        $this->create(['name' => '   '] + $this->validBody())
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0.code', 'required');

        $this->assertSame(0, DB::table('restv2_api_keys')->count());
        $this->assertSame([], $this->auditRecords('apiKeyCreation'));
    }

    public function test_invalid_request_bodies_are_refused_with_field_errors(): void
    {
        $this->withoutRequestValidation();
        $this->signInAs('admin', Role::Admin);
        $body = $this->validBody();

        $cases = [
            ['name', array_diff_key($body, ['name' => 1]), 'required', []],
            ['name', ['name' => ''] + $body, 'required', []],
            ['name', ['name' => str_repeat('x', 256)] + $body, 'tooLong', ['max' => 255]],
            ['ownerUsername', array_diff_key($body, ['ownerUsername' => 1]), 'required', []],
            ['accessLevel', ['accessLevel' => 'admin'] + $body, 'notInList', ['allowed' => ['readOnly', 'readWrite']]],
            ['expiresAt', ['expiresAt' => 'tomorrow'] + $body, 'invalidFormat', ['format' => 'dateTime']],
            ['allowedNetworks', ['allowedNetworks' => array_map(fn (int $i): string => "192.0.2.{$i}", range(0, 100))] + $body, 'tooMany', ['max' => 100]],
        ];

        foreach ($cases as [$field, $request, $code, $params]) {
            $response = $this->create($request)->assertUnprocessable();
            $this->assertSame($code, $response->json("errors.{$field}.0.code"), "{$field}: ".$response->getContent());
            $this->assertEquals($params, $response->json("errors.{$field}.0.params"), $field);
        }

        $this->assertSame(0, DB::table('restv2_api_keys')->count());
    }

    public function test_the_list_filters_sorts_searches_and_pages(): void
    {
        $this->signInAs('admin', Role::Admin);
        $active = $this->apiKey('reseller', 'readOnly', name: 'Billing');
        $expired = $this->apiKey('admin', 'readWrite', expiresAt: CarbonImmutable::now()->subDay(), name: 'Old backup');
        $revoked = $this->apiKey('admin', 'readOnly', revokedAt: CarbonImmutable::now()->subHour(), name: 'Monitoring');
        $future = $this->apiKey('admin', 'readWrite', expiresAt: CarbonImmutable::now()->addDay(), name: 'Zabbix 100%');
        foreach ([$active, $expired, $revoked, $future] as $i => $key) {
            DB::table('restv2_api_keys')->where('id', $key['id'])->update(['created_at' => CarbonImmutable::now('UTC')->subMinutes(10 - $i)->format('Y-m-d H:i:s')]);
        }
        $ids = fn (TestResponse $r): array => array_column($r->assertOk()->json('data'), 'id');

        // Newest first by default.
        $response = $this->getJson('/api/v2/api-keys');
        $this->assertSame([$future['id'], $revoked['id'], $expired['id'], $active['id']], $ids($response));
        $response->assertJsonPath('meta', ['total' => 4, 'limit' => 25, 'offset' => 0]);

        $this->assertSame([$active['id']], $ids($this->getJson('/api/v2/api-keys?ownerUsername=reseller')));
        $this->assertSame([$future['id'], $active['id']], $ids($this->getJson('/api/v2/api-keys?status=active')));
        $this->assertSame([$expired['id']], $ids($this->getJson('/api/v2/api-keys?status=expired')));
        $this->assertSame([$revoked['id']], $ids($this->getJson('/api/v2/api-keys?status=revoked')));
        $this->assertSame([$revoked['id'], $active['id']], $ids($this->getJson('/api/v2/api-keys?accessLevel=readOnly')));
        $this->assertSame(['Billing', 'Monitoring', 'Old backup', 'Zabbix 100%'], array_column($this->getJson('/api/v2/api-keys?sort=name&order=asc')->json('data'), 'name'));
        $this->assertSame([$expired['id'], $future['id']], array_slice($ids($this->getJson('/api/v2/api-keys?sort=expiresAt&order=asc')), 2));

        // q: name, prefix and owner username, case-insensitively; % is literal.
        $this->assertSame([$expired['id']], $ids($this->getJson('/api/v2/api-keys?q=BACKUP')));
        $this->assertSame([$active['id']], $ids($this->getJson('/api/v2/api-keys?q='.urlencode($active['prefix']))));
        $this->assertSame([$active['id']], $ids($this->getJson('/api/v2/api-keys?q=resell')));
        $this->assertSame([$future['id']], $ids($this->getJson('/api/v2/api-keys?q='.urlencode('100%'))));

        $page = $this->getJson('/api/v2/api-keys?limit=2&offset=1');
        $this->assertSame([$revoked['id'], $expired['id']], $ids($page));
        $page->assertJsonPath('meta', ['total' => 4, 'limit' => 2, 'offset' => 1]);
    }

    public function test_invalid_list_parameters_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->signInAs('admin', Role::Admin);

        $this->getJson('/api/v2/api-keys?limit=1001')->assertUnprocessable()->assertJsonPath('errors.limit.0.code', 'outOfRange');
        $this->getJson('/api/v2/api-keys?status=gone')->assertUnprocessable()->assertJsonPath('errors.status.0.code', 'notInList');
        $this->getJson('/api/v2/api-keys?accessLevel=admin')->assertUnprocessable()->assertJsonPath('errors.accessLevel.0.code', 'notInList');
        $this->getJson('/api/v2/api-keys?sort=secret')->assertUnprocessable()->assertJsonPath('errors.sort.0.code', 'notInList');
    }

    public function test_the_version_tag_covers_only_the_stored_settings(): void
    {
        $this->signInAs('admin', Role::Admin);
        $key = $this->apiKey('admin', expiresAt: CarbonImmutable::now()->addMinutes(5));
        $tag = fn (): string => (string) $this->getJson('/api/v2/api-keys/'.$key['id'])->assertOk()->headers->get('ETag');
        $etag = $tag();

        $this->assertSame($etag, $this->getJson('/api/v2/api-keys')->json('data.0.etag'));
        $this->assertSame($etag, $this->getJson('/api/v2/api-keys/'.$key['id'])->json('data.etag'));

        // A use of the key (last use) and the expiry passing do not change it.
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();
        $this->assertNotNull($this->getJson('/api/v2/api-keys/'.$key['id'])->json('data.lastUsedAt'));
        $this->travel(6)->minutes();
        $this->getJson('/api/v2/api-keys/'.$key['id'])->assertJsonPath('data.status', 'expired');
        $this->assertSame($etag, $tag());

        // A revocation does.
        $this->revoke($key['id'])->assertOk();
        $this->assertNotSame($etag, $tag());
    }

    public function test_a_single_key_shows_its_owner_state(): void
    {
        $this->signInAs('admin', Role::Admin);
        $key = $this->apiKey('reseller');

        $this->getJson('/api/v2/api-keys/'.$key['id'])->assertOk()->assertJsonPath('data.owner', ['username' => 'reseller', 'role' => 'domainAdmin', 'active' => true]);

        DB::table('admin')->where('username', 'reseller')->update(['active' => 0]);
        $this->getJson('/api/v2/api-keys/'.$key['id'])->assertJsonPath('data.owner', ['username' => 'reseller', 'role' => 'domainAdmin', 'active' => false]);

        DB::table('admin')->where('username', 'reseller')->delete();
        $this->getJson('/api/v2/api-keys/'.$key['id'])->assertJsonPath('data.owner', ['username' => 'reseller', 'role' => null, 'active' => false]);
    }

    public function test_an_unknown_key_is_not_found(): void
    {
        $this->signInAs('admin', Role::Admin);
        $unknown = '01JAAAAAAAAAAAAAAAAAAAAAAA';

        $this->getJson('/api/v2/api-keys/'.$unknown)->assertNotFound()->assertJsonPath('type', 'about:blank');
        $this->revoke($unknown)->assertNotFound();
    }

    public function test_revocation_is_final_and_idempotent(): void
    {
        $this->freezeTime();
        $this->signInAs('admin', Role::Admin);
        $key = $this->apiKey('reseller', name: 'Billing');
        $etag = (string) $this->getJson('/api/v2/api-keys/'.$key['id'])->headers->get('ETag');

        $response = $this->revoke($key['id'], ['If-Match' => $etag])->assertOk();

        $response->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.revokedAt', CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'))
            ->assertJsonPath('data.revokedBy', ['username' => 'admin']);
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->getJson('/api/v2/api-keys?status=revoked')->assertJsonPath('data.0.id', $key['id']);
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertJsonPath('type', '/api/v2/problems/api-key-revoked');

        $audit = $this->onlyAuditRecord('apiKeyRevocation');
        $this->assertSame(['apiKey', $key['id'], 'Billing'], [$audit->target_type, $audit->target_id, $audit->target_name]);
        $this->assertSame(['ownerUsername' => 'reseller'], $audit->details);

        // A retry, even with the tag read before the first revocation, changes nothing.
        $this->travel(5)->minutes();
        $this->revoke($key['id'], ['If-Match' => $etag])->assertOk()
            ->assertJsonPath('data.revokedAt', CarbonImmutable::now('UTC')->subMinutes(5)->format('Y-m-d\TH:i:s\Z'));
        $this->assertCount(1, $this->auditRecords('apiKeyRevocation'));
    }

    public function test_a_stale_version_tag_is_refused(): void
    {
        $this->signInAs('admin', Role::Admin);
        $key = $this->apiKey('reseller');

        $this->revoke($key['id'], ['If-Match' => '"stale"'])->assertStatus(412)->assertJsonPath('type', 'about:blank');

        $this->getJson('/api/v2/api-keys/'.$key['id'])->assertJsonPath('data.status', 'active');
        $this->assertSame([], $this->auditRecords('apiKeyRevocation'));
    }

    public function test_keys_never_create_or_revoke_keys(): void
    {
        $admin = $this->apiKey('admin', 'readWrite');
        $target = $this->apiKey('reseller');

        $this->postJson('/api/v2/api-keys', $this->validBody(), $this->keyHeader($admin))
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/session-required');
        $this->postEmpty('/api/v2/api-keys/'.$target['id'].'/revocation', $this->keyHeader($admin))
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/session-required');

        // An administrator's key may list and inspect them.
        $this->getJson('/api/v2/api-keys', $this->keyHeader($admin))->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v2/api-keys/'.$target['id'], $this->keyHeader($admin))->assertOk();
        $this->getJson('/api/v2/api-keys', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk();

        $this->assertSame(3, DB::table('restv2_api_keys')->count());
        $this->assertSame(0, DB::table('restv2_api_keys')->whereNotNull('revoked_at')->count());
    }

    public function test_other_roles_are_forbidden(): void
    {
        $key = $this->apiKey('admin');
        $forbidden = '/api/v2/problems/forbidden';

        foreach ([['reseller', Role::DomainAdmin], ['alice@example.org', Role::MailboxUser]] as [$username, $role]) {
            $this->forgetCookies();
            $this->signInAs($username, $role);

            $this->getJson('/api/v2/api-keys')->assertForbidden()->assertJsonPath('type', $forbidden);
            $this->getJson('/api/v2/api-keys/'.$key['id'])->assertForbidden()->assertJsonPath('type', $forbidden);
            $this->create($this->validBody())->assertForbidden()->assertJsonPath('type', $forbidden);
            $this->revoke($key['id'])->assertForbidden()->assertJsonPath('type', $forbidden);
        }

        // A domain administrator's key: forbidden to read; creating and revoking need a session.
        $this->forgetCookies();
        $resellerKey = $this->apiKey('reseller', 'readWrite');
        $this->getJson('/api/v2/api-keys', $this->keyHeader($resellerKey))->assertForbidden()->assertJsonPath('type', $forbidden);
        $this->getJson('/api/v2/api-keys/'.$key['id'], $this->keyHeader($resellerKey))->assertForbidden()->assertJsonPath('type', $forbidden);
        $this->postJson('/api/v2/api-keys', $this->validBody(), $this->keyHeader($resellerKey))->assertJsonPath('type', '/api/v2/problems/session-required');
        $this->postEmpty('/api/v2/api-keys/'.$key['id'].'/revocation', $this->keyHeader($resellerKey))->assertJsonPath('type', '/api/v2/problems/session-required');

        $this->assertSame([], $this->auditRecords('apiKeyCreation'));
        $this->assertSame([], $this->auditRecords('apiKeyRevocation'));
    }

    public function test_unknown_keys_are_forbidden_rather_than_not_found_for_other_roles(): void
    {
        $this->signInAs('reseller', Role::DomainAdmin);

        $this->getJson('/api/v2/api-keys/01JAAAAAAAAAAAAAAAAAAAAAAA')->assertForbidden();
    }
}
