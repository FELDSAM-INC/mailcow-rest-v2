<?php

namespace Tests\Unit\Audit;

use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Dkim\DkimFixtures;
use Tests\TestCase;

/**
 * Feature 010 T032 (research R22, docs/conventions.md "Refusal auditing"): the target resolvers of
 * the shared RefusalAuditor for `dkimKey` and `mtaStsPolicy`, and the failure reasons of this
 * feature's problem types. Refusals name the key (the normalised path name, an unused key's exact
 * stored spelling, the body `name` of a generation, the body `target` of a copy) or the policy (the
 * path domain, the body `domain` of a creation); the resolvers never read a private key, and the
 * errors of a refused import hold rule codes and parameters only.
 */
class MailDnsRefusalResolverTest extends TestCase
{
    use ApiKeys;

    protected function setUp(): void
    {
        parent::setUp();

        $refuse = function (Request $request): never {
            match ($request->input('problem')) {
                'notFound' => throw new NotFoundHttpException('The DKIM key does not exist.'),
                'exists' => throw new ProblemException(ProblemType::DkimKeyExists),
                'policyExists' => throw new ProblemException(ProblemType::MtaStsPolicyExists),
                'exportDisabled' => throw new ProblemException(ProblemType::DkimPrivateKeyExportDisabled),
                'publicationDisabled' => throw new ProblemException(ProblemType::DkimDnsPublicationDisabled),
                'incomplete' => throw new HttpException(409, 'The source key is incomplete: privateKey is missing.'),
                'outdated' => throw new HttpException(412, 'The key was changed since it was read.'),
                'invalidKey' => (function (): never {
                    $violations = new FieldViolations;
                    $violations->add('privateKey', 'keyLengthOutOfRange', ['min' => 1024, 'max' => 8192]);
                    $violations->throwIfAny();

                    throw new \LogicException('unreachable');
                })(),
                default => throw new \RuntimeException('unexpected'),
            };
        };

        $middleware = ['api.v2', 'restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'];
        Route::middleware($middleware)->post('/api/v2/__testing/dkim-keys/bulk-actions', $refuse)
            ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => 'dkimKey']);
        Route::middleware($middleware)->post('/api/v2/__testing/dkim-keys/{dkimName}/import', $refuse)
            ->where('dkimName', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'dkimKeyImport', 'target' => 'dkimKey']);
        Route::middleware($middleware)->post('/api/v2/__testing/dkim-keys/{dkimName}/copy', $refuse)
            ->where('dkimName', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'dkimKeyCopy', 'target' => 'dkimKey']);
        Route::middleware(['api.v2', 'restv2.auth:authenticated', 'restv2.pending'])->get('/api/v2/__testing/dkim-keys/{dkimName}/private-key', $refuse)
            ->where('dkimName', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'dkimPrivateKeyExport', 'target' => 'dkimKey']);
        Route::middleware($middleware)->post('/api/v2/__testing/dkim-keys/{dkimName}/dns-publication', $refuse)
            ->where('dkimName', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'dkimDnsPublication', 'target' => 'dkimKey']);
        Route::middleware($middleware)->delete('/api/v2/__testing/dkim-keys/{dkimName}', $refuse)
            ->where('dkimName', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'dkimKeyDeletion', 'target' => 'dkimKey']);
        Route::middleware($middleware)->post('/api/v2/__testing/dkim-keys', $refuse)
            ->defaults('restv2.audit', ['event' => 'dkimKeyGeneration', 'target' => 'dkimKey']);
        Route::middleware($middleware)->post('/api/v2/__testing/mta-sts-policies', $refuse)
            ->defaults('restv2.audit', ['event' => 'mtaStsPolicyCreation', 'target' => 'mtaStsPolicy']);
        Route::middleware($middleware)->patch('/api/v2/__testing/mta-sts-policies/{policyDomain}', $refuse)
            ->where('policyDomain', '[^/]+')
            ->defaults('restv2.audit', ['event' => 'mtaStsPolicyUpdate', 'target' => 'mtaStsPolicy']);

        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->mailcow()->domain('example.org');
        $this->mailcow()->redisHash('DKIM_PUB_KEYS', 'Example.ORG', 'MIIBlegacy');
        $this->mailcow()->redisHash('DKIM_SELECTORS', 'Example.ORG', 'dkim');
        $this->mailcow()->redisHash('DKIM_PRIV_KEYS', 'dkim.Example.ORG', DkimFixtures::RSA_1024_PRIVATE);
    }

    /**
     * @return list<stdClass>
     */
    private function records(): array
    {
        return DB::table('restv2_audit_events')->orderBy('occurred_at')->orderBy('id')->get()
            ->map(function (stdClass $row): stdClass {
                $row->details = json_decode((string) $row->details, true);

                return $row;
            })->all();
    }

    private function only(): stdClass
    {
        $records = $this->records();
        $this->assertCount(1, $records);

        return $records[0];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(string $method, string $uri, array $body): void
    {
        $this->signInAs('admin', Role::Admin);
        $this->json($method, $uri, $body, ['X-CSRF-Token' => $this->csrfToken()]);
    }

    public function test_a_generation_names_the_normalised_body_name(): void
    {
        $this->send('POST', '/api/v2/__testing/dkim-keys', ['problem' => 'exists', 'name' => ' BÜCHER.example ']);

        $record = $this->only();
        $this->assertSame('dkimKeyGeneration', $record->event_type);
        $this->assertSame('failure', $record->outcome);
        $this->assertSame('conflict', $record->failure_reason);
        $this->assertSame(['dkimKey', 'xn--bcher-kva.example', 'bücher.example'], [$record->target_type, $record->target_id, $record->target_name]);
        $this->assertSame('/api/v2/problems/dkim-key-exists', $record->details['problemType']);
    }

    public function test_a_path_name_is_normalised_and_an_unused_key_keeps_its_exact_spelling(): void
    {
        $this->send('DELETE', '/api/v2/__testing/dkim-keys/EXAMPLE.org', ['problem' => 'outdated']);
        $this->send('DELETE', '/api/v2/__testing/dkim-keys/Example.ORG', ['problem' => 'outdated']);

        [$normalised, $legacy] = $this->records();
        $this->assertSame(['example.org', 'versionOutdated'], [$normalised->target_id, $normalised->failure_reason]);
        $this->assertSame(['Example.ORG', 'Example.ORG'], [$legacy->target_id, $legacy->target_name]);
    }

    public function test_a_copy_is_recorded_on_its_target(): void
    {
        $this->send('POST', '/api/v2/__testing/dkim-keys/example.org/copy', ['problem' => 'incomplete', 'target' => 'Example.NET']);

        $record = $this->only();
        $this->assertSame(['dkimKeyCopy', 'example.net', 'conflict'], [$record->event_type, $record->target_id, $record->failure_reason]);
    }

    public function test_a_name_that_cannot_be_normalised_is_kept_cut_to_255_characters(): void
    {
        $this->send('POST', '/api/v2/__testing/dkim-keys', ['problem' => 'notFound', 'name' => str_repeat('a..', 100)]);

        $record = $this->only();
        $this->assertSame(255, mb_strlen((string) $record->target_id));
        $this->assertSame('notFound', $record->failure_reason);
    }

    public function test_the_disabled_operations_are_feature_disabled(): void
    {
        $this->signInAs('admin', Role::Admin);
        $this->getJson('/api/v2/__testing/dkim-keys/example.org/private-key?problem=exportDisabled');
        $this->postJson('/api/v2/__testing/dkim-keys/example.org/dns-publication', ['problem' => 'publicationDisabled'], ['X-CSRF-Token' => $this->csrfToken()]);

        [$export, $publication] = $this->records();
        $this->assertSame(['dkimPrivateKeyExport', 'featureDisabled', 'example.org'], [$export->event_type, $export->failure_reason, $export->target_id]);
        $this->assertSame(['dkimDnsPublication', 'featureDisabled'], [$publication->event_type, $publication->failure_reason]);
    }

    public function test_a_refused_import_records_codes_and_parameters_only(): void
    {
        $this->send('POST', '/api/v2/__testing/dkim-keys/import.example/import', ['problem' => 'invalidKey', 'privateKey' => DkimFixtures::RSA_1024_PRIVATE]);

        $record = $this->only();
        $this->assertSame(['dkimKeyImport', 'import.example', 'validationFailed'], [$record->event_type, $record->target_id, $record->failure_reason]);
        $this->assertSame(['privateKey' => [['code' => 'keyLengthOutOfRange', 'params' => ['min' => 1024, 'max' => 8192]]]], $record->details['errors']);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) json_encode($record));
        $this->assertStringNotContainsString(substr(explode("\n", DkimFixtures::RSA_1024_PRIVATE)[1], 0, 40), (string) json_encode($record));
    }

    public function test_a_bulk_request_refused_as_a_whole_names_the_requested_ids(): void
    {
        $this->send('POST', '/api/v2/__testing/dkim-keys/bulk-actions', ['problem' => 'notFound', 'action' => 'delete', 'targets' => [['id' => 'a.example'], ['id' => 'Example.ORG']]]);

        $record = $this->only();
        $this->assertSame('dkimKeyDeletion', $record->event_type);
        $this->assertNull($record->target_type);
        $this->assertSame(['a.example', 'Example.ORG'], $record->details['targetIds']);
        $this->assertSame('delete', $record->details['bulkAction']);
    }

    public function test_policies_name_the_path_or_body_domain(): void
    {
        $this->send('POST', '/api/v2/__testing/mta-sts-policies', ['problem' => 'policyExists', 'domain' => 'Example.ORG']);
        $this->send('PATCH', '/api/v2/__testing/mta-sts-policies/B%C3%BCCHER.example', ['problem' => 'outdated']);

        [$creation, $update] = $this->records();
        $this->assertSame(['mtaStsPolicyCreation', 'mtaStsPolicy', 'example.org', 'conflict'], [$creation->event_type, $creation->target_type, $creation->target_id, $creation->failure_reason]);
        $this->assertSame(['mtaStsPolicyUpdate', 'xn--bcher-kva.example', 'bücher.example', 'versionOutdated'], [$update->event_type, $update->target_id, $update->target_name, $update->failure_reason]);
    }

    public function test_an_unavailable_store_still_records_the_normalised_name(): void
    {
        config(['database.redis.mailcow.port' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();

        $this->send('DELETE', '/api/v2/__testing/dkim-keys/Example.ORG', ['problem' => 'notFound']);

        $this->assertSame('example.org', $this->only()->target_id);
    }
}
