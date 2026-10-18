<?php

namespace Tests\Feature\Dkim;

use App\Audit\AuditEventType;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\Audit\FlakyAuditLogger;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T092 (US6, FR-026, FR-027, research R9): administrators export a private key exactly as
 * stored, only while legacy's SHOW_DKIM_PRIV_KEYS is enabled (off by default); an export is a read,
 * so an administrator's read-only key may export; every export and every refused attempt is
 * audited, without the key.
 */
class ExportDkimPrivateKeyTest extends TestCase
{
    use DkimTestHelpers;

    private ?string $workDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->dkimKey('example.org', 'dkim', 1024);
    }

    protected function tearDown(): void
    {
        if ($this->workDir !== null) {
            File::deleteDirectory($this->workDir);
        }

        parent::tearDown();
    }

    private function enableExport(): void
    {
        config(['restv2.vars_overrides.SHOW_DKIM_PRIV_KEYS' => 'true']);
    }

    public function test_an_administrator_exports_the_key_exactly_as_stored(): void
    {
        $this->enableExport();
        $this->asAdmin();

        $this->getJson('/api/v2/dkim-keys/example.org/private-key')->assertOk()
            ->assertHeader('Cache-Control', 'no-store')
            ->assertExactJson(['data' => ['name' => 'example.org', 'selector' => 'dkim', 'privateKey' => DkimFixtures::RSA_1024_PRIVATE]]);

        $record = $this->onlyAudit('dkimPrivateKeyExport');
        $this->assertSame(['success', 'example.org'], [$record->outcome, $record->target_id]);
        $this->assertSame(['selector' => 'dkim', 'publicKeySha256' => hash('sha256', (string) base64_decode(DkimFixtures::RSA_1024_PUBLIC, true))], $record->details);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($record) ?: '');
    }

    public function test_the_export_is_disabled_by_default(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/dkim-keys/example.org/private-key')->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/dkim-private-key-export-disabled');

        $record = $this->onlyAudit('dkimPrivateKeyExport');
        $this->assertSame(['failure', 'featureDisabled', '/api/v2/problems/dkim-private-key-export-disabled'], [$record->outcome, $record->failure_reason, $record->details['problemType']]);
    }

    public function test_domain_administrators_can_never_export(): void
    {
        $this->enableExport();
        $this->asDomainAdmin(['example.org']);

        $this->getJson('/api/v2/dkim-keys/example.org/private-key')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->assertSame('permissionDenied', $this->onlyAudit('dkimPrivateKeyExport')->failure_reason);
    }

    public function test_keys_of_administrators_may_export_and_keys_of_domain_administrators_may_not(): void
    {
        $this->enableExport();
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->mailcow()->domainAdmin('reseller', ['example.org']);

        $this->getJson('/api/v2/dkim-keys/example.org/private-key', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk();
        $this->getJson('/api/v2/dkim-keys/example.org/private-key', $this->keyHeader($this->apiKey('reseller')))->assertForbidden();

        $this->assertSame([null, 'permissionDenied'], array_column($this->auditOfType('dkimPrivateKeyExport'), 'failure_reason'));
    }

    public function test_a_name_without_a_key_is_not_found_and_audited(): void
    {
        $this->enableExport();
        $this->mailcow()->domain('nokey.example');
        $this->mailcow()->partialDkimKey('half.example', ['publicKey' => DkimFixtures::RSA_1024_PUBLIC, 'selector' => 'dkim']);
        $this->asAdmin();

        $this->getJson('/api/v2/dkim-keys/nokey.example/private-key')->assertNotFound();
        $this->getJson('/api/v2/dkim-keys/half.example/private-key')->assertNotFound();
        $this->getJson('/api/v2/dkim-keys/nothing.example/private-key')->assertNotFound();

        $this->assertSame(['notFound', 'notFound', 'notFound'], array_column($this->auditOfType('dkimPrivateKeyExport'), 'failure_reason'));
    }

    public function test_an_unavailable_store_is_a_temporary_failure(): void
    {
        $this->enableExport();
        $this->asAdmin();
        $this->storeUnavailable();

        $this->getJson('/api/v2/dkim-keys/example.org/private-key')->assertStatus(503);

        $this->assertSame('temporaryFailure', $this->onlyAudit('dkimPrivateKeyExport')->failure_reason);
    }

    public function test_the_setting_comes_from_the_vars_files_and_the_server_setting_wins(): void
    {
        $this->workDir = storage_path('framework/testing/mailcow-inc-export-'.Str::random(8));
        File::ensureDirectoryExists($this->workDir);
        File::copy(__DIR__.'/../../fixtures/mailcow-inc/vars.inc.php', $this->workDir.'/vars.inc.php');
        file_put_contents($this->workDir.'/vars.local.inc.php', "<?php\n\$SHOW_DKIM_PRIV_KEYS = true;\n");
        config(['restv2.mailcow_inc_dir' => $this->workDir]);
        $this->asAdmin();

        $this->getJson('/api/v2/dkim-keys/example.org/private-key')->assertOk();

        config(['restv2.vars_overrides.SHOW_DKIM_PRIV_KEYS' => 'false']);
        $this->getJson('/api/v2/dkim-keys/example.org/private-key')->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/dkim-private-key-export-disabled');
    }

    public function test_a_failed_audit_record_withholds_the_key(): void
    {
        $this->enableExport();
        $this->asAdmin();
        $this->app->instance(AuditLogger::class, new FlakyAuditLogger(failingTypes: [AuditEventType::DkimPrivateKeyExport]));

        $response = $this->getJson('/api/v2/dkim-keys/example.org/private-key')->assertStatus(500);

        $this->assertNoPemInResponse($response);
    }
}
