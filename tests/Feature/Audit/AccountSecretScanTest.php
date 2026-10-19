<?php

namespace Tests\Feature\Audit;

use App\Auth\Role;
use Database\Factories\MailcowRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T089 (AS US7-3, FR-074, SC-009): after every operation of the feature with known
 * passwords (plain and pre-hashed) and tokens, no audit record, no response after the token's issue
 * response and no log line contains a password, a password hash or a token.
 */
class AccountSecretScanTest extends TestCase
{
    use AccountTestHelpers;

    public function test_no_password_hash_or_token_leaks(): void
    {
        $this->impersonationCast();
        config(['restv2.allow_admin_email_login' => true]);
        $preHashed = MailcowRows::hash('SSHA512', 'Pre-Hashed-S3cret!');
        $this->captureLog();
        $this->signInAs('alice', Role::Admin);
        $key = $this->keyHeader($this->apiKey('alice'));

        $bodies = [
            (string) $this->createAdmin(['username' => 'bob', 'password' => 'Adm1n-S3cret!x'])->assertCreated()->getContent(),
            (string) $this->createDomainAdmin(['username' => 'reseller2', 'password' => $preHashed, 'domains' => ['a.example']])->assertCreated()->getContent(),
            (string) $this->patchAdmin('bob', ['password' => 'Chang3d-S3cret!x', 'username' => 'robert'])->assertOk()->getContent(),
            (string) $this->patchDomainAdmin('reseller1', ['password' => 'Res3ller-S3cret!x'])->assertOk()->getContent(),
            (string) $this->getJson('/api/v2/admins')->assertOk()->getContent(),
            (string) $this->getJson('/api/v2/domain-admins')->assertOk()->getContent(),
            (string) $this->suspendSecondFactors('domain-admins', 'reseller1')->assertOk()->getContent(),
            (string) $this->bulkAccounts('domain-admins', 'deactivate', [['id' => 'reseller2']])->assertOk()->getContent(),
        ];
        $token = (string) $this->postEmpty('/api/v2/domain-admins/reseller1/sso-token', $key)->assertOk()->json('data.token');
        $bodies[] = (string) $this->postMailboxWebmail()->getContent();
        $bodies[] = (string) $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated()->getContent();
        $bodies[] = (string) $this->endImpersonation()->getContent();
        $bodies[] = (string) $this->deleteAdmin('robert')->assertNoContent()->getContent();
        $bodies[] = (string) $this->getJson('/api/v2/audit-events?limit=1000')->assertOk()->getContent();
        $this->forgetCookies();
        $bodies[] = (string) $this->postJson('/api/v2/session/sso-token', ['token' => $token], $this->csrf())->assertCreated()->getContent();
        $bodies[] = (string) $this->postJson('/api/v2/session/sso-token', ['token' => $token], $this->csrf())->assertUnauthorized()->getContent();

        $hashes = DB::table('admin')->pluck('password')->map(fn (mixed $h): string => (string) $h)->all();
        $secrets = ['Adm1n-S3cret!x', 'Chang3d-S3cret!x', 'Res3ller-S3cret!x', 'Pre-Hashed-S3cret!', $preHashed, $token, ...$hashes];
        $this->assertNoSecretIn(...$secrets);

        foreach ($bodies as $body) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }

            foreach (['{BLF-CRYPT}', '{SSHA512}', '$2y$'] as $marker) {
                $this->assertStringNotContainsString($marker, $body);
            }
        }
    }

    private function postMailboxWebmail(): TestResponse
    {
        return $this->postEmpty('/api/v2/mailboxes/carol@a.example/webmail-sign-in', $this->csrf())->assertOk();
    }
}
