<?php

namespace Tests\Feature\Dkim;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T100 (SC-005, FR-027): a scenario through every DKIM operation, successful and
 * refused, with the log captured. Afterwards the log, every response body and every audit record
 * are scanned for PEM private key armour and for the base64 lines of the keys used; only the export
 * responses may contain them.
 */
class DkimSecretLeakTest extends TestCase
{
    use DkimTestHelpers;

    /** @var list<string> */
    private array $logged = [];

    /** @var list<array{string, string}> operation => body */
    private array $responses = [];

    /**
     * @param  TestResponse<Response>  $response
     */
    private function keep(string $operation, TestResponse $response): void
    {
        $this->responses[] = [$operation, (string) $response->baseResponse->getContent()];
    }

    public function test_private_key_material_appears_only_in_export_responses(): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
        $f = $this->mailcow();
        foreach (['a.example', 'b.example', 'c.example'] as $domain) {
            $f->domain($domain);
        }
        $this->enablePublication();
        $this->ispconfig()->zone('a.example.', 10, 2);
        config(['restv2.vars_overrides.SHOW_DKIM_PRIV_KEYS' => 'true']);
        $this->asAdmin();

        $this->keep('generate', $this->send('POST', '/api/v2/dkim-keys', ['name' => 'a.example', 'keySize' => 1024])->assertCreated());
        $this->keep('import', $this->send('POST', '/api/v2/dkim-keys/b.example/import', ['privateKey' => DkimFixtures::RSA_2048_PRIVATE])->assertCreated());
        $this->keep('import refused', $this->send('POST', '/api/v2/dkim-keys/b.example/import', ['privateKey' => DkimFixtures::RSA_2048_PRIVATE])->assertStatus(409));
        $this->keep('import invalid', $this->send('POST', '/api/v2/dkim-keys/c.example/import', ['privateKey' => DkimFixtures::RSA_512_PRIVATE])->assertStatus(422));
        $this->keep('import encrypted', $this->send('POST', '/api/v2/dkim-keys/c.example/import', ['privateKey' => DkimFixtures::ENCRYPTED_PKCS1_PRIVATE])->assertStatus(422));
        $this->keep('copy', $this->send('POST', '/api/v2/dkim-keys/b.example/copy', ['target' => 'c.example'])->assertCreated());
        $this->keep('list', $this->getJson('/api/v2/dkim-keys')->assertOk());
        $this->keep('get', $this->getJson('/api/v2/dkim-keys/b.example')->assertOk());
        $this->keep('publish', $this->send('POST', '/api/v2/dkim-keys/a.example/dns-publication')->assertOk());
        $this->keep('export', $this->getJson('/api/v2/dkim-keys/b.example/private-key')->assertOk());
        $this->keep('export', $this->getJson('/api/v2/dkim-keys/a.example/private-key')->assertOk());
        $this->keep('delete', $this->send('DELETE', '/api/v2/dkim-keys/a.example')->assertNoContent());
        $this->keep('bulk', $this->send('POST', '/api/v2/dkim-keys/bulk-actions', ['action' => 'delete', 'targets' => [['id' => 'b.example'], ['id' => 'c.example']]])->assertOk());

        $exported = $this->responses[9][1].$this->responses[10][1];
        $generated = (string) json_decode($this->responses[10][1], true)['data']['privateKey'];
        $needles = ['PRIVATE KEY'];

        foreach ([DkimFixtures::RSA_2048_PRIVATE, DkimFixtures::RSA_512_PRIVATE, DkimFixtures::ENCRYPTED_PKCS1_PRIVATE, $generated] as $pem) {
            foreach (explode("\n", $pem) as $line) {
                if (strlen($line) >= 40 && ! str_starts_with($line, '-----')) {
                    $needles[] = $line;
                }
            }
        }

        $this->assertStringContainsString(json_encode(DkimFixtures::RSA_2048_PRIVATE, JSON_UNESCAPED_SLASHES) ?: 'x', $exported);
        $audit = (string) DB::table('restv2_audit_events')->get()->toJson(JSON_UNESCAPED_SLASHES);
        $logs = implode("\n", $this->logged);

        foreach ($needles as $needle) {
            $escaped = str_replace('/', '\/', $needle);

            foreach ($this->responses as [$operation, $body]) {
                if ($operation !== 'export') {
                    $this->assertStringNotContainsString($needle, $body, "{$operation} response");
                    $this->assertStringNotContainsString($escaped, $body, "{$operation} response");
                }
            }

            $this->assertStringNotContainsString($needle, $audit, 'audit records');
            $this->assertStringNotContainsString($needle, $logs, 'log');
            $this->assertStringNotContainsString($escaped, $logs, 'log');
        }

        $this->assertGreaterThan(10, count(DB::table('restv2_audit_events')->get()));
    }
}
