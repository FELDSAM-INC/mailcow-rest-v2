<?php

namespace Tests\Feature\System;

use App\Support\Http\ProblemType;
use Tests\Support\Redis\ChannelSpy;
use Tests\TestCase;

/**
 * T103 (constitution I and II, R27): the running release serves its bundled contract, an
 * interactive documentation page with only same-origin assets, and an HTML page for every
 * documented problem type. All three ignore `X-API-Key`.
 */
class DocsTest extends TestCase
{
    public function test_the_bundled_contract_is_served(): void
    {
        $response = $this->get('/api/v2/openapi.yaml')->assertOk();

        $this->assertStringStartsWith('application/yaml', (string) $response->headers->get('Content-Type'));
        $this->assertSame(file_get_contents(base_path('build/contract/openapi.yaml')), $response->getContent());
    }

    public function test_the_documentation_page_uses_only_same_origin_assets(): void
    {
        $response = $this->get('/api/v2/docs')->assertOk();

        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $html = (string) $response->getContent();
        $this->assertStringContainsString('openapi.yaml', $html);
        // The Swagger UI assets, when installed, are inlined: only the markup around them may
        // reference assets, and none from another origin.
        $markup = (string) preg_replace('#<(script|style)>.*?</\1>#is', '<$1></$1>', $html);
        $this->assertDoesNotMatchRegularExpression('#<(script|link|img|iframe)\b[^>]*\b(src|href)\s*=#i', $markup, 'no asset is loaded from a URL');
        $this->assertDoesNotMatchRegularExpression('#(src|href)\s*=\s*["\']?(https?:)?//#i', $markup, 'no asset is loaded from another origin');
    }

    public function test_every_problem_type_has_its_page(): void
    {
        foreach (ProblemType::cases() as $type) {
            $response = $this->get('/api/v2/problems/'.$type->value)->assertOk();

            $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
            $this->assertStringContainsString('<h2>'.$type->value.'</h2>', (string) $response->getContent(), $type->value);
            $this->assertStringContainsString((string) $type->status(), (string) $response->getContent());
        }
    }

    public function test_the_section_is_rendered_with_html_escaped(): void
    {
        $html = (string) $this->get('/api/v2/problems/pending-action-required')->assertOk()->getContent();

        $this->assertStringContainsString('<code>/api/v2/problems/pending-action-required</code>', $html);
        // Only the requested section.
        $this->assertStringNotContainsString('<h2>too-many-requests</h2>', $html);
    }

    public function test_an_unknown_problem_type_is_not_found(): void
    {
        $this->getJson('/api/v2/problems/unknown-type')->assertNotFound()->assertJsonPath('type', 'about:blank');
    }

    public function test_an_api_key_is_ignored(): void
    {
        $channel = ChannelSpy::fail2ban();

        $this->get('/api/v2/openapi.yaml', ['X-API-Key' => 'bogus'])->assertOk();
        $this->get('/api/v2/docs', ['X-API-Key' => 'bogus'])->assertOk();
        $this->get('/api/v2/problems/sign-in-failed', ['X-API-Key' => 'bogus'])->assertOk();

        $channel->assertNothingPublished();
    }
}
