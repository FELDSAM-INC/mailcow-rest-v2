<?php

namespace Tests\Unit\Support;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Contract\MatchedOperation;
use Tests\Support\Contract\OpenApiContract;

/**
 * T016 (R13, FR-051): the contract matcher and validator used by every feature test.
 */
class OpenApiContractTest extends TestCase
{
    private OpenApiContract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contract = OpenApiContract::fromJson((string) json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'test', 'version' => '1'],
            'servers' => [['url' => '/api/v2']],
            'paths' => [
                '/api-keys/owner-candidates' => [
                    'get' => [
                        'operationId' => 'listApiKeyOwnerCandidates',
                        'parameters' => [
                            ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                            ['name' => 'active', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                            ['name' => 'eventType', 'in' => 'query', 'explode' => true, 'style' => 'form', 'schema' => [
                                'type' => 'array', 'items' => ['type' => 'string', 'enum' => ['signIn', 'signOut']],
                            ]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'ok',
                                'content' => ['application/json' => ['schema' => [
                                    'type' => 'object',
                                    'required' => ['data'],
                                    'properties' => ['data' => ['type' => 'array']],
                                ]]],
                            ],
                        ],
                    ],
                ],
                '/api-keys/{apiKeyId}' => [
                    'parameters' => [
                        ['name' => 'apiKeyId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[0-9A-Z]{26}$']],
                    ],
                    'get' => [
                        'operationId' => 'getApiKey',
                        'responses' => [
                            '200' => [
                                'description' => 'ok',
                                'headers' => ['ETag' => ['required' => true, 'schema' => ['type' => 'string']]],
                                'content' => ['application/json' => ['schema' => [
                                    'type' => 'object',
                                    'required' => ['data'],
                                    'properties' => ['data' => [
                                        'type' => 'object',
                                        'required' => ['id', 'name'],
                                        'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string']],
                                    ]],
                                ]]],
                            ],
                            '404' => ['$ref' => '#/components/responses/NotFound'],
                        ],
                    ],
                ],
                '/items' => [
                    'post' => [
                        'operationId' => 'createItem',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'required' => ['name'],
                                'properties' => ['name' => ['type' => 'string', 'maxLength' => 5]],
                                'additionalProperties' => false,
                            ]]],
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'created',
                                'headers' => ['Location' => ['required' => true, 'schema' => ['type' => 'string']]],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Problem' => [
                        'type' => 'object',
                        'required' => ['type', 'title', 'status', 'requestId'],
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'status' => ['type' => 'integer'],
                            'requestId' => ['type' => 'string', 'pattern' => '^[0-9a-f]{32}$'],
                        ],
                    ],
                ],
                'responses' => [
                    'NotFound' => [
                        'description' => 'not found',
                        'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]],
                    ],
                ],
            ],
        ]));
    }

    public function test_literal_segments_win_over_templates(): void
    {
        $this->assertSame('listApiKeyOwnerCandidates', $this->contract->match('GET', '/api/v2/api-keys/owner-candidates')?->operationId);
        $this->assertSame('getApiKey', $this->contract->match('GET', '/api/v2/api-keys/01J0000000000000000000000A')?->operationId);
        $match = $this->contract->match('GET', '/api/v2/api-keys/01J0000000000000000000000A');
        $this->assertInstanceOf(MatchedOperation::class, $match);
        $this->assertSame(['apiKeyId' => '01J0000000000000000000000A'], $match->pathParameters);
        $this->assertNull($this->contract->match('DELETE', '/api/v2/api-keys/01J0000000000000000000000A'));
        $this->assertNull($this->contract->match('GET', '/api/v2/unknown'));
    }

    public function test_a_valid_exchange_has_no_violations(): void
    {
        $request = Request::create('/api/v2/api-keys/01J0000000000000000000000A', 'GET');
        $response = $this->json(200, ['data' => ['id' => 'x', 'name' => 'y']], ['ETag' => '"abc"']);

        $this->assertSame([], $this->contract->validateRequest($request));
        $this->assertSame([], $this->contract->validateResponse($request, $response));
    }

    public function test_an_undocumented_status_is_a_violation(): void
    {
        $request = Request::create('/api/v2/api-keys/owner-candidates', 'GET');
        $response = $this->json(418, ['type' => 'about:blank', 'title' => 't', 'status' => 418, 'requestId' => str_repeat('a', 32)], [], 'application/problem+json');

        $violations = $this->contract->validateResponse($request, $response);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('418', $violations[0]);
    }

    public function test_a_missing_required_response_header_is_a_violation(): void
    {
        $request = Request::create('/api/v2/items', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"ab"}');

        $this->assertSame([], $this->contract->validateRequest($request));
        $violations = $this->contract->validateResponse($request, new Response('', 201));

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Location', $violations[0]);
    }

    public function test_schema_violations_in_request_and_response_are_reported(): void
    {
        $request = Request::create('/api/v2/items', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"too long","extra":1}');
        $this->assertNotSame([], $this->contract->validateRequest($request));

        $request = Request::create('/api/v2/api-keys/01J0000000000000000000000A', 'GET');
        $response = $this->json(200, ['data' => ['id' => 'x']], ['ETag' => '"abc"']);
        $violations = $this->contract->validateResponse($request, $response);
        $this->assertNotSame([], $violations);
        $this->assertStringContainsString('getApiKey', implode("\n", $violations));
    }

    public function test_path_and_query_parameters_are_validated(): void
    {
        $this->assertNotSame([], $this->contract->validateRequest(Request::create('/api/v2/api-keys/lowercase', 'GET')));
        $this->assertNotSame([], $this->contract->validateRequest(Request::create('/api/v2/api-keys/owner-candidates?limit=abc', 'GET')));
        $this->assertNotSame([], $this->contract->validateRequest(Request::create('/api/v2/api-keys/owner-candidates?unknown=1', 'GET')));
        $this->assertSame([], $this->contract->validateRequest(Request::create('/api/v2/api-keys/owner-candidates?limit=5&eventType=signIn&eventType=signOut', 'GET')));
        $this->assertNotSame([], $this->contract->validateRequest(Request::create('/api/v2/api-keys/owner-candidates?eventType=signIn&eventType=bogus', 'GET')));
    }

    public function test_boolean_query_parameters_accept_exactly_true_and_false(): void
    {
        // Feature 002 research D26: `?active=true|false` filters lists; any other spelling is invalid.
        $this->assertSame([], $this->contract->validateRequest(Request::create('/api/v2/api-keys/owner-candidates?active=true', 'GET')));
        $this->assertSame([], $this->contract->validateRequest(Request::create('/api/v2/api-keys/owner-candidates?active=false', 'GET')));
        $this->assertNotSame([], $this->contract->validateRequest(Request::create('/api/v2/api-keys/owner-candidates?active=1', 'GET')));
        $this->assertNotSame([], $this->contract->validateRequest(Request::create('/api/v2/api-keys/owner-candidates?active=yes', 'GET')));
    }

    public function test_an_unknown_operation_is_valid_only_as_a_404_or_405_problem(): void
    {
        $problem = ['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404, 'requestId' => str_repeat('b', 32)];

        $unknown = Request::create('/api/v2/unknown', 'GET');
        $this->assertSame([], $this->contract->validateRequest($unknown));
        $this->assertSame([], $this->contract->validateResponse($unknown, $this->json(404, $problem, [], 'application/problem+json')));
        $this->assertNotSame([], $this->contract->validateResponse($unknown, $this->json(200, ['data' => []])));
        $this->assertNotSame([], $this->contract->validateResponse($unknown, $this->json(404, ['message' => 'x'], [], 'application/problem+json')));

        $wrongMethod = Request::create('/api/v2/items', 'PUT');
        $problem['status'] = 405;
        $this->assertSame([], $this->contract->validateResponse($wrongMethod, $this->json(405, $problem, ['Allow' => 'POST'], 'application/problem+json')));
    }

    /**
     * Feature 007 T005 (docs/conventions.md "Bulk paths"): a literal path that does not define the
     * request's method gives way to the templated path, so that an object named `bulk-actions`
     * stays addressable with `GET`.
     */
    public function test_a_literal_path_without_the_method_falls_back_to_the_templated_path(): void
    {
        $contract = OpenApiContract::fromJson((string) json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'test', 'version' => '1'],
            'servers' => [['url' => '/api/v2']],
            'paths' => [
                '/admins/bulk-actions' => ['post' => ['operationId' => 'applyAdminBulkAction', 'responses' => ['200' => ['description' => 'ok']]]],
                '/admins/{username}' => [
                    'parameters' => [['name' => 'username', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                    'get' => ['operationId' => 'getAdmin', 'responses' => ['200' => ['description' => 'ok']]],
                ],
            ],
        ]));

        $this->assertSame('applyAdminBulkAction', $contract->match('POST', '/api/v2/admins/bulk-actions')?->operationId);
        $get = $contract->match('GET', '/api/v2/admins/bulk-actions');
        $this->assertNotNull($get);
        $this->assertSame('getAdmin', $get->operationId);
        $this->assertSame(['username' => 'bulk-actions'], $get->pathParameters);
        $this->assertNull($contract->match('DELETE', '/api/v2/admins/bulk-actions'));
    }

    public function test_testing_routes_are_exempt(): void
    {
        $request = Request::create('/api/v2/__testing/anything', 'POST', content: 'free-form');

        $this->assertSame([], $this->contract->validateRequest($request));
        $this->assertSame([], $this->contract->validateResponse($request, new Response('ok', 200)));
    }

    public function test_documented_success_responses_are_recorded_for_coverage(): void
    {
        $request = Request::create('/api/v2/api-keys/01J0000000000000000000000A', 'GET');
        $this->contract->validateResponse($request, $this->json(200, ['data' => ['id' => 'x', 'name' => 'y']], ['ETag' => '"a"']));

        $this->assertContains('getApiKey', $this->contract->coveredOperations());
        $this->assertNotContains('createItem', $this->contract->coveredOperations());
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function json(int $status, array $body, array $headers = [], string $type = 'application/json'): Response
    {
        return new Response((string) json_encode($body), $status, ['Content-Type' => $type] + $headers);
    }
}
