<?php

namespace Tests\Support\Accounts;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\Domains\DomainTestHelpers;

/**
 * Requests and lookups shared by the tests of feature 007. Used in Tests\TestCase subclasses.
 */
trait AccountTestHelpers
{
    use AccountAssertions;
    use ApiKeys;
    use AuthRequests;
    use DomainTestHelpers;

    /** The 19 permission flags: contract name => `da_acl` column. */
    protected const FLAG_COLUMNS = [
        'syncjobs' => 'syncjobs',
        'quarantine' => 'quarantine',
        'loginAs' => 'login_as',
        'sogoAccess' => 'sogo_access',
        'appPasswds' => 'app_passwds',
        'bccMaps' => 'bcc_maps',
        'pushover' => 'pushover',
        'filters' => 'filters',
        'ratelimit' => 'ratelimit',
        'spamPolicy' => 'spam_policy',
        'extendSenderAcl' => 'extend_sender_acl',
        'unlimitedQuota' => 'unlimited_quota',
        'protocolAccess' => 'protocol_access',
        'smtpIpAccess' => 'smtp_ip_access',
        'aliasDomains' => 'alias_domains',
        'mailboxRelayhost' => 'mailbox_relayhost',
        'domainRelayhost' => 'domain_relayhost',
        'domainDesc' => 'domain_desc',
        'aliasExternalGoto' => 'alias_external_goto',
    ];

    /**
     * Registers `assertViolation()` and `assertViolationParams()` on test responses: the field
     * errors of a `422` by their exact key, also when it contains dots (`domains.1`).
     */
    protected function setUpAccountTestHelpers(): void
    {
        TestResponse::macro('assertViolation', function (string $field, string $code, int $index = 0): TestResponse {
            /** @var TestResponse<Response> $this */
            $errors = (array) $this->json('errors');
            Assert::assertArrayHasKey($field, $errors, 'No violation of '.$field.': '.json_encode($errors));
            Assert::assertSame($code, $errors[$field][$index]['code'] ?? null, 'Violations of '.$field.': '.json_encode($errors[$field]));

            return $this;
        });
        TestResponse::macro('assertViolationParams', function (string $field, array $params, int $index = 0): TestResponse {
            /** @var TestResponse<Response> $this */
            $errors = (array) $this->json('errors');
            Assert::assertSame($params, $errors[$field][$index]['params'] ?? null, 'Violations of '.$field.': '.json_encode($errors[$field] ?? null));

            return $this;
        });
    }

    /**
     * The `da_acl` column defaults of the test schema, by contract name.
     *
     * @return array<string, bool>
     */
    protected function flagDefaults(): array
    {
        $defaults = DB::table('information_schema.COLUMNS')
            ->whereRaw('`TABLE_SCHEMA` = DATABASE()')
            ->where('TABLE_NAME', 'da_acl')
            ->pluck('COLUMN_DEFAULT', 'COLUMN_NAME')
            ->all();
        $flags = [];

        foreach (self::FLAG_COLUMNS as $name => $column) {
            $flags[$name] = (int) trim((string) ($defaults[$column] ?? '0'), "'") === 1;
        }

        return $flags;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function createDomainAdmin(array $body, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v2/domain-admins', $body, $headers === [] ? $this->csrf() : $headers);
    }

    /**
     * @param  array<string, mixed>  $patch
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function patchDomainAdmin(string $username, array $patch, array $headers = []): TestResponse
    {
        return $this->patchMerge('/api/v2/domain-admins/'.$username, $patch, isset($headers['X-API-Key']) ? $headers : $this->csrf() + $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function deleteDomainAdmin(string $username, array $headers = []): TestResponse
    {
        return $this->deleteJson('/api/v2/domain-admins/'.$username, [], isset($headers['X-API-Key']) ? $headers : $this->csrf() + $headers);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function createAdmin(array $body, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v2/admins', $body, $headers === [] ? $this->csrf() : $headers);
    }

    /**
     * @param  array<string, mixed>  $patch
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function patchAdmin(string $username, array $patch, array $headers = []): TestResponse
    {
        return $this->patchMerge('/api/v2/admins/'.$username, $patch, isset($headers['X-API-Key']) ? $headers : $this->csrf() + $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function deleteAdmin(string $username, array $headers = []): TestResponse
    {
        return $this->deleteJson('/api/v2/admins/'.$username, [], isset($headers['X-API-Key']) ? $headers : $this->csrf() + $headers);
    }

    /**
     * `POST /admins/{username}/second-factor-suspension` or the domain administrator one.
     *
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function suspendSecondFactors(string $collection, string $username, array $headers = []): TestResponse
    {
        return $this->postEmpty("/api/v2/{$collection}/{$username}/second-factor-suspension", isset($headers['X-API-Key']) ? $headers : $this->csrf() + $headers);
    }

    /**
     * `POST /admins/bulk-actions` or `POST /domain-admins/bulk-actions`.
     *
     * @param  list<array{id: string, version?: string}>  $targets
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function bulkAccounts(string $collection, string $action, array $targets, array $headers = []): TestResponse
    {
        return $this->postJson("/api/v2/{$collection}/bulk-actions", ['action' => $action, 'targets' => $targets], isset($headers['X-API-Key']) ? $headers : $this->csrf() + $headers);
    }

    /**
     * The decoded `admin.attributes` of an account.
     *
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    protected function adminAttributes(string $username): array
    {
        return (array) json_decode((string) DB::table('admin')->where('username', $username)->value('attributes'), true);
    }

    /**
     * A TOTP and a WebAuthn entry and two passkeys of an account, as legacy stores them.
     */
    protected function secondFactorsFor(string $username, int $totpActive = 1, int $webauthnActive = 1, int $passkeys = 2): void
    {
        $this->mailcow()->tfaTotp($username, 'JBSWY3DPEHPK3PXP', 'Phone', $totpActive);
        $this->mailcow()->tfaWebAuthn($username, random_bytes(16), "-----BEGIN PUBLIC KEY-----\nX\n-----END PUBLIC KEY-----", 'Key', $webauthnActive);

        for ($i = 0; $i < $passkeys; $i++) {
            $this->mailcow()->fido2($username, random_bytes(16), "-----BEGIN PUBLIC KEY-----\nX\n-----END PUBLIC KEY-----", 'mail.example.org', friendlyName: 'Passkey '.$i);
        }
    }

    /**
     * Preferences of an account as feature 001 stores them.
     */
    protected function preferencesFor(string $username, string $kind = 'admin', string $language = 'de-de'): void
    {
        DB::table('restv2_preferences')->insert([
            'account_kind' => $kind,
            'username' => $username,
            'language' => $language,
            'theme' => 'dark',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
