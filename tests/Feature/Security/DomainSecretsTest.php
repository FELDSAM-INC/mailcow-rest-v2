<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T091 (constitution VI): with the DKIM DNS publication on, a creation's responses, log
 * lines and audit records contain neither the DKIM private key nor the ISPConfig password.
 */
class DomainSecretsTest extends TestCase
{
    use DomainTestHelpers;

    private const PASSWORD = 'IspC0nfig-Remote-S3cret';

    public function test_no_secret_reaches_responses_logs_or_audit_records(): void
    {
        config([
            'restv2.dkim.dns_publication' => true,
            'restv2.ispconfig.url' => 'https://ispconfig.example:8080/remote/json.php',
            'restv2.ispconfig.username' => 'remote',
            'restv2.ispconfig.password' => self::PASSWORD,
        ]);
        $this->ispconfig()->zone('example.org.', 7, 3);
        $this->ispconfig()->failOn('dns_txt_add', 'remoteFault', 'login as remote with '.self::PASSWORD.' failed');
        $log = storage_path('logs/testing.log');
        $offset = is_file($log) ? (int) filesize($log) : 0;
        $this->asAdmin();

        $bodies = [
            (string) $this->postJson('/api/v2/domains', ['name' => 'example.org', 'dkimKeySize' => 1024], $this->csrf())->assertCreated()->getContent(),
            (string) $this->getJson('/api/v2/domains/example.org')->getContent(),
            (string) $this->getJson('/api/v2/capabilities/domains')->getContent(),
        ];

        $privateKey = (string) $this->redis()->hGet('DKIM_PRIV_KEYS', 'dkim.example.org');
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $privateKey);
        $keyBody = explode("\n", $privateKey)[1];
        $logged = is_file($log) ? (string) file_get_contents($log, offset: $offset) : '';
        $audit = (string) json_encode(DB::table('restv2_audit_events')->get());

        foreach ([...$bodies, $logged, $audit] as $text) {
            $this->assertStringNotContainsString($keyBody, $text);
            $this->assertStringNotContainsString('PRIVATE KEY', $text);
            $this->assertStringNotContainsString(self::PASSWORD, $text);
        }
    }
}
