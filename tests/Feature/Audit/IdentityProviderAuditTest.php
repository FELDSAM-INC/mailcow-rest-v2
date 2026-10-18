<?php

namespace Tests\Feature\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\IdentityProvider\FakeOidcProvider;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\ProviderPasswordTestHelpers;
use Tests\TestCase;

/**
 * T086 (FR-052 to FR-054, US5 AS-1 to AS-3, SC-010): the audit trail of identity provider and
 * OAuth2 changes and of sign-ins through the provider, filtered with the generic filters only.
 */
class IdentityProviderAuditTest extends TestCase
{
    use ProviderPasswordTestHelpers;

    /**
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function events(array $query): array
    {
        $this->asAdmin();

        return (array) $this->getJson('/api/v2/audit-events?'.http_build_query($query + ['limit' => 1000]))->assertOk()->json('data');
    }

    public function test_a_configuration_change_shows_old_and_new_values_and_secrets_only_as_changed(): void
    {
        $this->mailcow()->mailboxTemplate('Staff');
        $this->mailcow()->ldapConfig();
        $this->asAdmin();

        $this->patchProvider(['bindPassword' => 'new-ldap-secret', 'syncInterval' => 45])->assertOk();

        $events = $this->events(['targetType' => 'identityProvider']);
        $this->assertCount(1, $events);
        $this->assertSame('identityProviderUpdate', $events[0]['eventType']);
        $this->assertSame(['old' => 15, 'new' => 45], $events[0]['details']['changes']['syncInterval']);
        $this->assertSame(['changed' => true], $events[0]['details']['changes']['bindPassword']);
        $this->assertStringNotContainsString('ldap-secret', (string) json_encode($events));
    }

    public function test_a_mailbox_created_on_single_sign_on_is_listed_with_its_template_and_sign_in(): void
    {
        $this->staffTemplate();
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->keycloakConfig();
        $this->keycloakSignIn(IdentityProviderFixtures::userInfo('ben@example.org', 'Ben', 'staff'));
        $this->completeSso($this->startSso()['state'])->assertCreated();
        $this->forgetCookies();

        $events = $this->events(['targetType' => 'mailbox', 'targetId' => 'ben@example.org']);
        $creation = array_values(array_filter($events, fn (array $e): bool => $e['eventType'] === 'mailboxCreation'));
        $this->assertCount(1, $creation);
        $this->assertSame('Staff', $creation[0]['details']['template']);
        $this->assertSame('identityProviderSignIn', $creation[0]['details']['trigger']);

        $signIn = $this->events(['eventType' => 'signIn', 'authMethod' => 'singleSignOn']);
        $this->assertCount(1, $signIn);
        $this->assertSame($signIn[0]['requestId'], $creation[0]['details']['signInRequestId']);
    }

    public function test_a_failed_single_sign_on_shows_the_providers_address_and_the_reason(): void
    {
        $this->mailcow()->keycloakConfig(['mappers' => '[]', 'templates' => '[]']);
        $this->keycloakSignIn(IdentityProviderFixtures::userInfo('erik@example.org', 'Erik'));
        $this->completeSso($this->startSso()['state'])->assertUnauthorized();
        $this->forgetCookies();

        $events = $this->events(['outcome' => 'failure', 'eventType' => 'signIn']);
        $this->assertCount(1, $events);
        $this->assertSame(['erik@example.org', 'noMatchingMapping', 'singleSignOn'], [$events[0]['actor']['username'], $events[0]['failureReason'], $events[0]['authMethod']]);
    }

    public function test_sign_in_records_carry_their_method(): void
    {
        $this->externalMailbox('carl@example.org', 'ldap');
        $this->directory();
        $this->passwordSignIn('carl@example.org', self::DIRECTORY_PASSWORD)->assertCreated();
        $this->forgetCookies();

        $this->assertSame(['directoryPassword'], array_column($this->events(['eventType' => 'signIn']), 'authMethod'));
        $this->assertCount(1, $this->events(['authMethod' => 'directoryPassword']));
    }

    public function test_the_generic_target_filters_find_oauth2_clients(): void
    {
        $first = $this->mailcow()->oauthClient('https://a.example.org/cb');
        $second = $this->mailcow()->oauthClient('https://b.example.org/cb');
        $this->asAdmin();
        $this->postEmpty('/api/v2/oauth2-clients/'.$first.'/token-revocation', $this->csrf())->assertOk();
        $this->postEmpty('/api/v2/oauth2-clients/'.$second.'/secret-regeneration', $this->csrf())->assertOk();

        $events = $this->events(['targetType' => 'oauth2Client', 'targetId' => (string) $first]);
        $this->assertSame(['oauth2ClientTokenRevocation'], array_column($events, 'eventType'));
        $this->assertCount(2, $this->events(['targetType' => 'oauth2Client']));
    }

    public function test_no_audit_filter_parameter_of_this_feature_exists(): void
    {
        $contract = json_decode((string) file_get_contents(base_path('build/contract/openapi.json')), true);
        $names = array_column((array) $contract['paths']['/audit-events']['get']['parameters'], 'name');

        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression('/identityProvider|oauth2|singleSignOn|provider/i', (string) $name);
        }
    }

    /**
     * @return iterable<string, array{\Closure(self): TestResponse<Response>, string, int}>
     */
    public static function requests(): iterable
    {
        yield 'save' => [fn (self $t) => $t->putProvider(IdentityProviderFixtures::ldap()), 'identityProviderUpdate', 1];
        yield 'change' => [fn (self $t) => $t->patchProvider(['syncInterval' => 20]), 'identityProviderUpdate', 1];
        yield 'refused change' => [fn (self $t) => $t->patchProvider(['realm' => 'x']), 'identityProviderUpdate', 1];
        yield 'deletion' => [fn (self $t) => $t->deleteProvider(), 'identityProviderDeletion', 1];
        yield 'connection test' => [fn (self $t) => $t->testConnection(IdentityProviderFixtures::keycloak()), 'identityProviderConnectionTest', 1];
        yield 'client creation' => [fn (self $t) => $t->postJson('/api/v2/oauth2-clients', ['redirectUris' => ['https://x.example.org/cb']], $t->csrf()), 'oauth2ClientCreation', 1];
        yield 'client change' => [fn (self $t) => $t->patchMerge('/api/v2/oauth2-clients/'.$t->clientId(), ['redirectUris' => ['https://y.example.org/cb']], $t->csrf()), 'oauth2ClientUpdate', 1];
        yield 'secret regeneration' => [fn (self $t) => $t->postEmpty('/api/v2/oauth2-clients/'.$t->clientId().'/secret-regeneration', $t->csrf()), 'oauth2ClientSecretRegeneration', 1];
        yield 'revocation' => [fn (self $t) => $t->postEmpty('/api/v2/oauth2-clients/'.$t->clientId().'/token-revocation', $t->csrf()), 'oauth2ClientTokenRevocation', 1];
        yield 'client deletion' => [fn (self $t) => $t->deleteJson('/api/v2/oauth2-clients/'.$t->clientId(), [], $t->csrf()), 'oauth2ClientDeletion', 1];
        yield 'bulk deletion of three distinct clients' => [fn (self $t) => $t->postJson('/api/v2/oauth2-clients/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $t->clientId()], ['id' => (string) $t->clientId()], ['id' => '999998'], ['id' => '999999']]], $t->csrf()), 'oauth2ClientDeletion', 3];
    }

    /**
     * @param  \Closure(self): TestResponse<Response>  $request
     */
    #[DataProvider('requests')]
    public function test_every_request_writes_exactly_one_record_per_object(\Closure $request, string $eventType, int $records): void
    {
        $this->mailcow()->mailboxTemplate('Staff');
        $this->mailcow()->ldapConfig();
        FakeOidcProvider::install()->tokenIssued('client_credentials', 'service-token', null);
        $this->asAdmin();
        $before = DB::table('restv2_audit_events')->count();

        $request($this);

        $this->assertSame($records, DB::table('restv2_audit_events')->where('event_type', $eventType)->count());
        $this->assertSame($records, DB::table('restv2_audit_events')->count() - $before);
    }

    private ?int $clientId = null;

    public function clientId(): int
    {
        return $this->clientId ??= $this->mailcow()->oauthClient('https://wiki.example.org/cb');
    }
}
