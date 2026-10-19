<?php

namespace Tests\Feature\SsoTokens;

use App\Casts\MailcowDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T078 (AS US5-1, US5-5, FR-057 to FR-059, research R20): administrators issue
 * single sign-on tokens for domain administrators into mailcow's own token store; the token is shown
 * only in the issue response and never audited.
 */
class IssueSsoTokenTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->admin('admin', 'x');
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
    }

    public function test_a_billing_system_issues_a_token_with_a_read_write_key(): void
    {
        $this->mailcow()->daSso('other', 'BBBBBB-BBBBBB-BBBBBB-BBBBBB-BBBBBB', createdSecondsAgo: 31);
        $this->captureLog();

        $response = $this->postEmpty('/api/v2/domain-admins/ReSeller1/sso-token', $this->keyHeader($this->apiKey('admin')))->assertOk();

        $response->assertHeader('Cache-Control', 'no-store')->assertJsonPath('data.username', 'reseller1');
        $token = (string) $response->json('data.token');
        $this->assertMatchesRegularExpression('/^[0-9A-F]{6}(-[0-9A-F]{6}){4}$/', $token);
        $row = DB::table('da_sso')->where('token', $token)->sole();
        $this->assertSame('reseller1', $row->username);
        $expected = CarbonImmutable::parse((string) MailcowDateTime::toUtcString($row->created))->addSeconds(30)->format('Y-m-d\TH:i:s\Z');
        $this->assertSame($expected, $response->json('data.expiresAt'));
        $this->assertFalse(DB::table('da_sso')->where('username', 'other')->exists());

        $this->assertAudit('ssoTokenIssue', 'success', 'ssoToken', 'reseller1', ['expiresAt' => $expected]);
        $this->assertNoSecretIn($token);
    }

    public function test_an_inactive_account_gets_no_token(): void
    {
        DB::table('admin')->where('username', 'reseller1')->update(['active' => 0]);
        $this->asAdmin();

        $this->postEmpty('/api/v2/domain-admins/reseller1/sso-token', $this->csrf())->assertConflict()
            ->assertJsonPath('type', '/api/v2/problems/account-inactive');

        $this->assertSame(0, DB::table('da_sso')->count());
        $record = $this->assertAudit('ssoTokenIssue', 'failure', 'ssoToken', 'reseller1');
        $this->assertSame('accountInactive', $record->failure_reason);
    }

    public function test_unknown_accounts_and_administrators_are_not_found(): void
    {
        $this->asAdmin();

        $this->postEmpty('/api/v2/domain-admins/nobody/sso-token', $this->csrf())->assertNotFound();
        $this->postEmpty('/api/v2/domain-admins/admin/sso-token', $this->csrf())->assertNotFound();
        $this->assertSame(0, DB::table('da_sso')->count());
    }

    public function test_an_account_without_assignments_gets_a_token(): void
    {
        $this->mailcow()->orphanedDomainAdmin('lonely');
        $this->asAdmin();

        $this->postEmpty('/api/v2/domain-admins/lonely/sso-token', $this->csrf())->assertOk();
    }
}
