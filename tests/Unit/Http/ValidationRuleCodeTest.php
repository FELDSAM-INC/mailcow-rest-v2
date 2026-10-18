<?php

namespace Tests\Unit\Http;

use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Exceptions\ProblemException;
use App\Support\Http\FieldViolations;
use App\Support\Http\ListQuery;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Http\DomainRulesProbeRequest;
use Tests\Support\Http\NonEmptyBodyProbeRequest;
use Tests\Support\Http\ValidationProbeRequest;
use Tests\TestCase;

/**
 * T025 (FR-055, FR-058, R29): every validation failure carries the contract's stable rule code and
 * its parameters, under the documented error key, and lists follow the shared list conventions.
 */
class ValidationRuleCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api.v2')->post('/api/v2/__testing/validation', fn (ValidationProbeRequest $request) => response()->noContent());
        Route::middleware('api.v2')->post('/api/v2/__testing/validation-body', fn (NonEmptyBodyProbeRequest $request) => response()->noContent());
        Route::middleware('api.v2')->post('/api/v2/__testing/domain-rules', fn (DomainRulesProbeRequest $request) => response()->noContent());
        Route::middleware('api.v2')->post('/api/v2/__testing/field-violations', function (): never {
            $violations = new FieldViolations;
            $violations->add('defaultMailboxQuota', 'mustNotExceed', ['field' => 'maxMailboxQuota', 'value' => 1048576]);
            $violations->add('maxMailboxes', 'belowInUse', ['inUse' => 8]);
            $violations->add('name', 'reserved');
            $violations->add('createdAt', 'immutable');
            $violations->add('templateId', 'notFound');
            $violations->add('html', 'tooLong', ['max' => 1048576, 'unit' => 'bytes']);
            $violations->throwIfAny();

            throw new \LogicException('unreachable');
        });
        Route::middleware('api.v2')->get('/api/v2/__testing/problem/{type}', function (string $type): never {
            throw new ProblemException(ProblemType::from($type));
        });
        Route::middleware('api.v2')->get('/api/v2/__testing/shared-store-down', function (): never {
            throw new SharedStoreUnavailable('connection refused');
        });
        Route::middleware('api.v2')->get('/api/v2/__testing/list', function (Request $request) {
            $list = ListQuery::fromRequest($request, ['domain' => 'domain', 'createdAt' => 'created'], 'domain', 'asc');

            return response()->json($list->paginate(
                $list->applySearch(DB::table('domain'), ['domain', 'description']),
                fn (object $row): array => ['domain' => $row->domain],
            ));
        });
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, list<array{code: string, params: array<string, mixed>, message: string}>>
     */
    private function errors(array $body, string $path = '/api/v2/__testing/validation'): array
    {
        $response = $this->postJson($path, $body);
        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', '/api/v2/problems/validation-failed')
            ->assertJsonPath('status', 422);

        return $response->json('errors');
    }

    /**
     * @param  array<string, list<array{code: string, params: array<string, mixed>, message: string}>>  $errors
     * @param  array<string, mixed>  $params
     */
    private function assertViolation(array $errors, string $field, string $code, array $params = []): void
    {
        $this->assertArrayHasKey($field, $errors, "no error for {$field}: ".json_encode($errors));
        $this->assertSame($code, $errors[$field][0]['code'], json_encode($errors[$field]));
        $this->assertEquals($params, $errors[$field][0]['params'], json_encode($errors[$field]));
        $this->assertNotSame('', $errors[$field][0]['message']);
    }

    public function test_required(): void
    {
        $this->assertViolation($this->errors([]), 'name', 'required');
        $this->assertViolation($this->errors(['name' => '']), 'name', 'required');
    }

    public function test_wrong_type(): void
    {
        $errors = $this->errors(['name' => 123, 'count' => 'many', 'tags' => 'x']);

        $this->assertViolation($errors, 'name', 'invalidType', ['expected' => 'string']);
        $this->assertViolation($errors, 'count', 'invalidType', ['expected' => 'integer']);
        $this->assertViolation($errors, 'tags', 'invalidType', ['expected' => 'array']);
    }

    public function test_string_length(): void
    {
        $this->assertViolation($this->errors(['name' => 'ab']), 'name', 'tooShort', ['min' => 3]);
        $this->assertViolation($this->errors(['name' => str_repeat('a', 11)]), 'name', 'tooLong', ['max' => 10]);
    }

    public function test_list_size_and_dot_path_keys(): void
    {
        $this->assertViolation($this->errors(['name' => 'abc', 'tags' => ['a', 'b', 'c']]), 'tags', 'tooMany', ['max' => 2]);
        $this->assertViolation($this->errors(['name' => 'abc', 'tags' => ['a', 5]]), 'tags.1', 'invalidType', ['expected' => 'string']);
    }

    public function test_numeric_range(): void
    {
        $this->assertViolation($this->errors(['name' => 'abc', 'count' => 0]), 'count', 'outOfRange', ['min' => 1, 'max' => 1000]);
        $this->assertViolation($this->errors(['name' => 'abc', 'count' => 1001]), 'count', 'outOfRange', ['min' => 1, 'max' => 1000]);
    }

    public function test_not_in_list(): void
    {
        $this->assertViolation($this->errors(['name' => 'abc', 'level' => 'admin']), 'level', 'notInList', ['allowed' => ['readOnly', 'readWrite']]);
    }

    public function test_date_time_format(): void
    {
        $this->assertViolation($this->errors(['name' => 'abc', 'until' => 'tomorrow']), 'until', 'invalidFormat', ['format' => 'dateTime']);
        $this->assertViolation($this->errors(['name' => 'abc', 'until' => '2999-02-30T00:00:00Z']), 'until', 'invalidFormat', ['format' => 'dateTime']);
        $this->assertViolation($this->errors(['name' => 'abc', 'until' => '2999-01-01 00:00:00']), 'until', 'invalidFormat', ['format' => 'dateTime']);
        // Laravel's own date rules map to the same code.
        $this->assertViolation($this->errors(['name' => 'abc', 'since' => '18.10.2026']), 'since', 'invalidFormat', ['format' => 'dateTime']);
        $this->postJson('/api/v2/__testing/validation', ['name' => 'abc', 'until' => '2999-01-01T00:00:00.123+02:00'])->assertNoContent();
    }

    public function test_ip_network(): void
    {
        $errors = $this->errors(['name' => 'abc', 'networks' => ['192.0.2.0/24', '2001:db8::/129', 'not-an-ip']]);

        $this->assertArrayNotHasKey('networks.0', $errors);
        $this->assertViolation($errors, 'networks.1', 'invalidFormat', ['format' => 'ipNetwork']);
        $this->assertViolation($errors, 'networks.2', 'invalidFormat', ['format' => 'ipNetwork']);
    }

    public function test_future_date_time(): void
    {
        $this->assertViolation($this->errors(['name' => 'abc', 'until' => '2020-01-01T00:00:00Z']), 'until', 'mustBeInFuture');
    }

    public function test_eligible_key_owner(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->mailcow()->mailboxWithDomain('alice@example.org');

        $this->assertViolation($this->errors(['name' => 'abc', 'owner' => 'nobody']), 'owner', 'notFound');
        $this->assertViolation($this->errors(['name' => 'abc', 'owner' => 'alice@example.org']), 'owner', 'notEligible', ['allowedRoles' => ['admin', 'domainAdmin']]);
        $this->postJson('/api/v2/__testing/validation', ['name' => 'abc', 'owner' => 'admin'])->assertNoContent();
    }

    public function test_enabled_language(): void
    {
        $this->assertViolation($this->errors(['name' => 'abc', 'language' => 'de-de']), 'language', 'notInList', ['allowed' => ['cs-cz', 'en-gb', 'sk-sk']]);
        $this->postJson('/api/v2/__testing/validation', ['name' => 'abc', 'language' => 'cs-cz'])->assertNoContent();
    }

    public function test_body_key_for_the_request_as_a_whole(): void
    {
        $this->assertViolation($this->errors([], '/api/v2/__testing/validation-body'), 'body', 'required');
    }

    public function test_a_valid_request_passes(): void
    {
        $this->postJson('/api/v2/__testing/validation', [
            'name' => 'abc',
            'count' => 5,
            'tags' => ['a'],
            'level' => 'readOnly',
            'until' => '2999-01-01T00:00:00Z',
            'networks' => ['192.0.2.1', '2001:db8::/32'],
        ])->assertNoContent();
    }

    public function test_list_defaults_and_meta(): void
    {
        foreach (['b.example', 'a.example', 'c.example'] as $domain) {
            $this->mailcow()->domain($domain);
        }

        $this->getJson('/api/v2/__testing/list')
            ->assertOk()
            ->assertExactJson([
                'data' => [['domain' => 'a.example'], ['domain' => 'b.example'], ['domain' => 'c.example']],
                'meta' => ['total' => 3, 'limit' => 25, 'offset' => 0],
            ]);

        $this->getJson('/api/v2/__testing/list?limit=1&offset=1&sort=domain&order=desc')
            ->assertOk()
            ->assertExactJson(['data' => [['domain' => 'b.example']], 'meta' => ['total' => 3, 'limit' => 1, 'offset' => 1]]);

        $this->getJson('/api/v2/__testing/list?limit=1000')->assertOk()->assertJsonPath('meta.limit', 1000);
    }

    public function test_list_parameters_are_validated(): void
    {
        $cases = [
            'limit=0' => ['limit', 'outOfRange', ['min' => 1, 'max' => 1000]],
            'limit=1001' => ['limit', 'outOfRange', ['min' => 1, 'max' => 1000]],
            'limit=abc' => ['limit', 'invalidType', ['expected' => 'integer']],
            'offset=-1' => ['offset', 'outOfRange', ['min' => 0]],
            'sort=password' => ['sort', 'notInList', ['allowed' => ['domain', 'createdAt']]],
            'order=up' => ['order', 'notInList', ['allowed' => ['asc', 'desc']]],
            'q='.str_repeat('x', 256) => ['q', 'tooLong', ['max' => 255]],
        ];

        foreach ($cases as $query => [$field, $code, $params]) {
            $response = $this->getJson('/api/v2/__testing/list?'.$query)->assertStatus(422);
            $this->assertViolation($response->json('errors'), $field, $code, $params);
        }
    }

    public function test_list_search_is_trimmed_case_insensitive_and_escaped(): void
    {
        $this->mailcow()->domain('alpha.example');
        $this->mailcow()->domain('beta.example');
        $this->mailcow()->domain('50percent.example');

        $this->getJson('/api/v2/__testing/list?q='.urlencode('  ALPHA '))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.domain', 'alpha.example');

        $this->getJson('/api/v2/__testing/list?q=')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v2/__testing/list?q='.urlencode('%'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v2/__testing/list?q='.urlencode('_'))->assertOk()->assertJsonPath('meta.total', 0);

        $this->assertSame('%50\\%\\_a\\\\b%', ListQuery::likePattern('50%_a\\b'));
    }

    public function test_quotas_must_be_whole_mebibytes(): void
    {
        // Feature 002 T020 (FR-059, research D3).
        $errors = $this->errors(['quota' => 1572864], '/api/v2/__testing/domain-rules');

        $this->assertViolation($errors, 'quota', 'notMultipleOf', ['factor' => 1048576]);
        $this->postJson('/api/v2/__testing/domain-rules', ['quota' => 3 * 1048576])->assertNoContent();
    }

    public function test_dkim_selectors_are_letters_digits_dots_hyphens_and_underscores(): void
    {
        $errors = $this->errors(['selector' => 'a b'], '/api/v2/__testing/domain-rules');

        $this->assertViolation($errors, 'selector', 'invalidFormat', ['format' => 'dkimSelector']);
        $this->postJson('/api/v2/__testing/domain-rules', ['selector' => 'Feld-host_1.x'])->assertNoContent();
        $this->postJson('/api/v2/__testing/domain-rules', ['selector' => ''])->assertNoContent();
    }

    public function test_service_violations_are_reported_together(): void
    {
        $errors = $this->errors([], '/api/v2/__testing/field-violations');

        $this->assertViolation($errors, 'defaultMailboxQuota', 'mustNotExceed', ['field' => 'maxMailboxQuota', 'value' => 1048576]);
        $this->assertViolation($errors, 'maxMailboxes', 'belowInUse', ['inUse' => 8]);
        $this->assertViolation($errors, 'name', 'reserved');
        $this->assertViolation($errors, 'createdAt', 'immutable');
        $this->assertViolation($errors, 'templateId', 'notFound');
        $this->assertViolation($errors, 'html', 'tooLong', ['max' => 1048576, 'unit' => 'bytes']);
    }

    public function test_the_problem_types_of_feature_002(): void
    {
        $expected = [
            'domain-exists' => 409,
            'domain-not-empty' => 409,
            'domain-template-name-exists' => 409,
            'domain-template-protected' => 409,
            'attribute-not-permitted' => 403,
            'temporary-failure' => 503,
            'permission-flag-required' => 403,
        ];

        foreach ($expected as $name => $status) {
            $this->assertSame($status, ProblemType::from($name)->status(), $name);
            $this->getJson('/api/v2/__testing/problem/'.$name)
                ->assertStatus($status)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', '/api/v2/problems/'.$name);
        }
    }

    public function test_an_unavailable_shared_store_is_a_temporary_failure_with_retry_after(): void
    {
        $this->getJson('/api/v2/__testing/shared-store-down')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '10')
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure');
    }
}
