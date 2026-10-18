<?php

namespace Tests\Unit\Routing;

use App\Rules\RoutingEmail;
use App\Support\Http\FieldViolations;
use Illuminate\Support\Facades\Route;
use Tests\Support\Http\RoutingRulesProbeRequest;
use Tests\TestCase;

/**
 * Feature 009 T013 (FR-013, FR-041, FR-087, SC-011): the SMTP username and e-mail rules, and the
 * rule codes this feature adds (`invalidCharacter`, `notResolvable`, `required` with `because`).
 * Messages never echo the submitted value, and every invalid field of a request is named.
 */
class RoutingRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api.v2')->post('/api/v2/__testing/routing-rules', fn (RoutingRulesProbeRequest $request) => response()->noContent());
        Route::middleware('api.v2')->post('/api/v2/__testing/routing-violations', function (): never {
            $violations = new FieldViolations;
            $violations->add('username', 'required', ['because' => 'password']);
            $violations->add('host', 'notResolvable');
            $violations->add('username2', 'invalidCharacter', ['characters' => [':']]);
            $violations->throwIfAny();

            throw new \LogicException('unreachable');
        });
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, list<array{code: string, params: array<string, mixed>, message: string}>>
     */
    private function errors(array $body, string $path = '/api/v2/__testing/routing-rules'): array
    {
        $response = $this->postJson($path, $body)->assertStatus(422)->assertJsonPath('type', '/api/v2/problems/validation-failed');

        return (array) $response->json('errors');
    }

    public function test_valid_values_pass(): void
    {
        $this->postJson('/api/v2/__testing/routing-rules', ['username' => ' acct ', 'sender' => 'relay@example.org', 'recipient' => 'null@bücher.example'])->assertNoContent();
    }

    public function test_a_username_with_a_colon_is_an_invalid_character(): void
    {
        $errors = $this->errors(['username' => 'user:name']);

        $this->assertSame('invalidCharacter', $errors['username'][0]['code']);
        $this->assertSame(['characters' => [':']], $errors['username'][0]['params']);
        $this->assertStringNotContainsString('user:name', $errors['username'][0]['message']);
    }

    public function test_a_username_longer_than_255_characters_after_trimming_is_too_long(): void
    {
        $this->assertSame([['code' => 'tooLong', 'params' => ['max' => 255]]], self::codes($this->errors(['username' => str_repeat('u', 256)])['username']));
        $this->postJson('/api/v2/__testing/routing-rules', ['username' => '  '.str_repeat('u', 255).'  '])->assertNoContent();
    }

    public function test_invalid_addresses_are_refused_without_echoing_them(): void
    {
        $errors = $this->errors(['sender' => 'not-an-address', 'recipient' => 'a@b@c.example']);

        $this->assertSame([['code' => 'invalidFormat', 'params' => ['format' => 'email']]], self::codes($errors['sender']));
        $this->assertSame([['code' => 'invalidFormat', 'params' => ['format' => 'email']]], self::codes($errors['recipient']));
        $this->assertStringNotContainsString('not-an-address', $errors['sender'][0]['message']);
    }

    public function test_an_address_longer_than_255_characters_is_refused(): void
    {
        $long = str_repeat('a', 64).'@'.str_repeat(str_repeat('b', 60).'.', 3).str_repeat('c', 20).'.example';

        $this->assertGreaterThan(255, strlen($long));
        $this->assertSame('invalidFormat', $this->errors(['sender' => $long])['sender'][0]['code']);
    }

    public function test_several_invalid_fields_are_all_named(): void
    {
        $errors = $this->errors(['username' => 'a:b', 'sender' => 'x', 'recipient' => 'y']);

        $this->assertEqualsCanonicalizing(['username', 'sender', 'recipient'], array_keys($errors));
    }

    public function test_the_new_rule_codes_carry_their_parameters_and_messages(): void
    {
        $errors = $this->errors([], '/api/v2/__testing/routing-violations');

        $this->assertSame(['code' => 'required', 'params' => ['because' => 'password']], self::codes($errors['username'])[0]);
        $this->assertSame('A password needs a username.', $errors['username'][0]['message']);
        $this->assertSame(['code' => 'notResolvable', 'params' => []], self::codes($errors['host'])[0]);
        $this->assertSame('The host name resolves to no address.', $errors['host'][0]['message']);
        $this->assertSame('Must not contain: ":".', $errors['username2'][0]['message']);
    }

    public function test_the_normalised_form_of_an_address(): void
    {
        $this->assertSame('null@xn--bcher-kva.example', RoutingEmail::normalize(' null@Bücher.Example '));
        $this->assertSame('Relay@example.org', RoutingEmail::normalize('Relay@EXAMPLE.org'));
        $this->assertNull(RoutingEmail::normalize('not-an-address'));
    }

    /**
     * @param  list<array{code: string, params: array<string, mixed>, message: string}>  $violations
     * @return list<array{code: string, params: array<string, mixed>}>
     */
    private static function codes(array $violations): array
    {
        return array_map(fn (array $v): array => ['code' => $v['code'], 'params' => $v['params']], $violations);
    }
}
