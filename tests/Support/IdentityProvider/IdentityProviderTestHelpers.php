<?php

namespace Tests\Support\IdentityProvider;

use App\Clients\Ldap\LdapDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\Support\Fakes\FakeLdapDirectory;

/**
 * Helpers of feature 012's identity provider tests: requests of the module as a signed-in
 * administrator, the stored rows and the audit records.
 */
trait IdentityProviderTestHelpers
{
    use ApiKeys;
    use DomainTestHelpers;

    protected function ldapFake(): FakeLdapDirectory
    {
        $fake = new FakeLdapDirectory;
        $this->app->instance(LdapDirectory::class, $fake);

        return $fake;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function putProvider(array $body, array $headers = []): TestResponse
    {
        return $this->putJson('/api/v2/identity-provider', $body, $this->csrf() + $headers);
    }

    /**
     * @param  array<string, mixed>  $patch
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function patchProvider(array $patch, array $headers = []): TestResponse
    {
        return $this->patchMerge('/api/v2/identity-provider', $patch, $this->csrf() + $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function deleteProvider(array $headers = []): TestResponse
    {
        return $this->deleteJson('/api/v2/identity-provider', [], $this->csrf() + $headers);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    protected function testConnection(array $body): TestResponse
    {
        return $this->postJson('/api/v2/identity-provider/connection-tests', $body, $this->csrf());
    }

    /**
     * The `identity_provider` rows, key => value, sorted by key.
     *
     * @return array<string, string>
     *
     * @phpstan-impure
     */
    protected function providerRows(): array
    {
        $rows = DB::table('identity_provider')->pluck('value', 'key')->map(fn (mixed $v): string => (string) $v)->all();
        ksort($rows);

        return $rows;
    }

    /**
     * Mailboxes with an authentication source, in their own domain.
     */
    protected function externalMailboxes(string $authsource, int $count, string $domain = 'example.org'): void
    {
        $this->mailcow()->ensureDomain($domain);

        for ($i = 1; $i <= $count; $i++) {
            $this->mailcow()->mailbox("{$authsource}{$i}@{$domain}", authsource: $authsource);
        }
    }

    /**
     * Audit records of the module's target type.
     *
     * @return list<\stdClass>
     *
     * @phpstan-impure
     */
    protected function providerAudit(?string $eventType = null): array
    {
        return array_values(array_filter(
            $this->allAudit(),
            fn (\stdClass $row): bool => $row->target_type === 'identityProvider' && ($eventType === null || $row->event_type === $eventType),
        ));
    }

    /**
     * @return list<\stdClass>
     *
     * @phpstan-impure
     */
    protected function allAudit(): array
    {
        return DB::table('restv2_audit_events')->orderBy('occurred_at')->orderBy('id')->get()
            ->map(function (\stdClass $row): \stdClass {
                $row->details = json_decode((string) $row->details, true);

                return $row;
            })->all();
    }
}
