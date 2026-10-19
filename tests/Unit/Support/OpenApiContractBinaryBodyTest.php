<?php

namespace Tests\Unit\Support;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Tests\Support\Contract\OpenApiContract;

/**
 * Feature 013 T021: the test harness matches request bodies of a non-JSON media type (a logo upload
 * of `replaceLogo`) by their media type only, without validating them as JSON, and still refuses an
 * undocumented media type. The harness of feature 001 already behaves so; this test keeps it.
 */
class OpenApiContractBinaryBodyTest extends TestCase
{
    private const CONTRACT = <<<'JSON'
        {
          "openapi": "3.1.0",
          "info": {"title": "t", "version": "1"},
          "servers": [{"url": "/api/v2"}],
          "paths": {
            "/settings/logos/{variant}": {
              "parameters": [{"name": "variant", "in": "path", "required": true, "schema": {"type": "string", "enum": ["light", "dark"]}}],
              "put": {
                "operationId": "replaceLogo",
                "requestBody": {"required": true, "content": {
                  "image/png": {"schema": {"type": "string", "contentMediaType": "image/png"}},
                  "application/octet-stream": {"schema": {"type": "string", "contentMediaType": "application/octet-stream"}}
                }},
                "responses": {"204": {"description": "ok"}}
              }
            }
          }
        }
        JSON;

    private function request(string $contentType, string $body): Request
    {
        return Request::create('/api/v2/settings/logos/light', 'PUT', [], [], [], ['CONTENT_TYPE' => $contentType], $body);
    }

    public function test_a_binary_body_of_a_documented_media_type_passes_without_json_validation(): void
    {
        $contract = OpenApiContract::fromJson(self::CONTRACT);
        $png = "\x89PNG\r\n\x1a\n".random_bytes(64);

        $this->assertSame([], $contract->validateRequest($this->request('image/png', $png)));
        $this->assertSame([], $contract->validateRequest($this->request('application/octet-stream', $png)));
    }

    public function test_an_undocumented_media_type_is_a_violation(): void
    {
        $contract = OpenApiContract::fromJson(self::CONTRACT);

        $violations = $contract->validateRequest($this->request('text/plain', 'logo'));

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('text/plain is not documented', $violations[0]);
    }

    public function test_a_missing_required_body_is_a_violation(): void
    {
        $violations = OpenApiContract::fromJson(self::CONTRACT)->validateRequest($this->request('image/png', ''));

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('required request body is missing', $violations[0]);
    }
}
