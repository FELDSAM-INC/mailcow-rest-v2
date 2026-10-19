<?php

namespace Tests\Feature\Settings;

use App\Auth\Role;
use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\DockerCallResult;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Support\Settings\SecretScan;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T090 (SC-012, FR-067, research R16): the Rspamd web interface password never appears
 * in a response, the log, the audit log, the API's Redis keys or its tables, whatever the outcome:
 * success, every validation failure, a refused key or caller, an anti-forgery failure, a failure,
 * a timeout and an unexpected error of the container service. Every response of the whole suite is
 * also checked for the test passphrases by Tests\TestCase.
 */
class SecretScanTest extends TestCase
{
    use SettingsTestHelpers;

    private const URI = '/api/v2/settings/rspamd-ui-password';

    private const PASSPHRASE = 'Moo scan secret 7';

    public function test_no_outcome_leaves_the_passphrase_anywhere_also_for_invalid_and_malformed_requests(): void
    {
        $log = storage_path('logs/testing.log');
        $offset = is_file($log) ? (int) filesize($log) : 0;
        $this->mailcow()->domain('example.org');
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $key = $this->adminKey();
        $readOnly = $this->adminKey('readOnly');
        $bodies = [];
        $keep = function ($response) use (&$bodies): void {
            $bodies[] = (string) $response->baseResponse->getContent();
        };

        $keep($this->putJson(self::URI, ['password' => self::PASSPHRASE])->assertUnauthorized());
        $keep($this->send('PUT', self::URI, ['password' => self::PASSPHRASE], $key)->assertForbidden());
        $keep($this->send('PUT', self::URI, ['password' => self::PASSPHRASE], $readOnly)->assertForbidden());
        $this->asDomainAdmin(['example.org']);
        $keep($this->send('PUT', self::URI, ['password' => self::PASSPHRASE])->assertForbidden());
        $this->forgetCookies();
        $this->signInAs('admin', Role::Admin);
        $keep($this->putJson(self::URI, ['password' => self::PASSPHRASE], ['X-CSRF-Token' => 'wrong'])->assertForbidden());
        $keep($this->send('PUT', self::URI, ['password' => self::PASSPHRASE])->assertNoContent());
        $this->withoutRequestValidation();
        $keep($this->send('PUT', self::URI, ['password' => self::PASSPHRASE.str_repeat('x', 256)])->assertStatus(422));
        $keep($this->send('PUT', self::URI, ['password' => self::PASSPHRASE."\n"])->assertStatus(422));
        $keep($this->send('PUT', self::URI, ['password' => self::PASSPHRASE, 'confirmation' => self::PASSPHRASE])->assertStatus(422));
        $keep($this->send('PUT', self::URI, ['password' => [self::PASSPHRASE]])->assertStatus(422));
        $keep($this->call('PUT', self::URI, [], [], [], $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Content-Type' => 'application/json'] + $this->csrf()), '{"password": "'.self::PASSPHRASE.'"'));
        $this->dockerApi()->respondTo('exec', DockerCallResult::failed('rspamadm pw failed for '.self::PASSPHRASE));
        $keep($this->send('PUT', self::URI, ['password' => self::PASSPHRASE])->assertStatus(502));
        $this->dockerApi()->respondTo('exec', DockerCallResult::success());
        $this->dockerApi()->failNext('exec', 'timeout');
        $keep($this->send('PUT', self::URI, ['password' => self::PASSPHRASE])->assertStatus(504));
        $this->app->instance(DockerApiClient::class, Mockery::mock(DockerApiClient::class)->shouldReceive('exec')->andThrow(new RuntimeException('dockerapi crashed'))->getMock());
        $keep($this->send('PUT', self::URI, ['password' => self::PASSPHRASE])->assertStatus(500));

        $this->assertNull(SecretScan::find(implode("\n", $bodies)), 'responses');
        $logged = is_file($log) ? (string) file_get_contents($log, false, null, $offset) : '';
        $this->assertStringContainsString('dockerapi crashed', $logged, 'the unexpected error was logged');
        $this->assertNull(SecretScan::find($logged), 'log');
        $this->assertNull(SecretScan::find((string) json_encode(DB::table('restv2_audit_events')->get(), JSON_UNESCAPED_UNICODE)), 'audit log');
        $this->assertSame(['passwordChanged' => true, 'rspamdConfirmed' => true], $this->successesOf('rspamdUiPasswordChange')[0]->details);
        $this->assertNull(SecretScan::find($this->redisDump()), 'Redis');
        $this->assertNull(SecretScan::find($this->apiTables()), 'API tables');
    }

    /** Every key and value of the test Redis database (mailcow's keys and the API's). */
    private function redisDump(): string
    {
        $redis = $this->redis();
        $dump = '';

        foreach ((array) $redis->keys('*') as $key) {
            $dump .= $key."\n".(string) $redis->dump((string) $key)."\n";
        }

        return $dump;
    }

    /** The rows of the API's own tables. */
    private function apiTables(): string
    {
        $dump = '';

        foreach (DB::select("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'restv2\\_%'") as $table) {
            $dump .= json_encode(DB::table((string) $table->name)->get(), JSON_UNESCAPED_UNICODE)."\n";
        }

        return $dump;
    }
}
