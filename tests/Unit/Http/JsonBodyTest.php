<?php

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\Http\JsonBodyProbeRequest;

class JsonBodyTest extends TestCase
{
    public function test_merge_patch_members_keep_presence_types_and_raw_values(): void
    {
        $body = [
            'description' => '  Original text  ',
            'active' => false,
            'quota' => 0,
            'password' => '',
            'rateLimit' => null,
            'tags' => [],
            'permissions' => ['syncJobs' => false],
            'unknownMember' => 'left for the endpoint to accept or reject',
        ];
        $request = JsonBodyProbeRequest::create(
            '/?description=query-value&queryOnly=ignored',
            'PATCH',
            server: ['CONTENT_TYPE' => 'application/merge-patch+json'],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
        $request->prepareForValidation();

        $this->assertSame($body, $request->seenDuringValidation);
        $this->assertSame($body, $request->members());
        $this->assertArrayNotHasKey('omittedMember', $request->members());
        $this->assertArrayNotHasKey('queryOnly', $request->members());

        // Endpoint-specific input normalisation must not change the raw merge-patch members.
        $request->merge(['description' => 'Normalised text', 'addedDefault' => true]);
        $this->assertSame('Normalised text', $request->input('description'));
        $this->assertSame($body, $request->members());
    }

    public function test_an_empty_body_does_not_turn_query_parameters_into_body_members(): void
    {
        $request = JsonBodyProbeRequest::create('/?active=true', 'POST');
        $request->prepareForValidation();

        $this->assertSame([], $request->members());
    }
}
