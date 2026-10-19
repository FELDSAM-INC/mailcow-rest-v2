<?php

namespace Tests\Unit\Clients;

use App\Clients\Rspamd\LearnOutcome;
use App\Clients\Rspamd\LearnResult;
use App\Clients\Rspamd\RspamdUnavailable;
use App\Clients\Rspamd\UnixSocketRspamdController;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature 006 T014 (research R2, FR-062): the learning calls and the action thresholds of the
 * Rspamd controller over its Unix socket, exactly as legacy sends them, and the classification of
 * the answers.
 */
class UnixSocketRspamdControllerTest extends TestCase
{
    private const MESSAGE = "From: a@example.net\r\nSubject: Spam\r\n\r\nBuy now.\r\n";

    /** @var list<array<string, mixed>> */
    private array $options = [];

    private function client(): UnixSocketRspamdController
    {
        return $this->app->make(UnixSocketRspamdController::class);
    }

    /**
     * @param  array<int|string, mixed>|string  $body
     */
    private function answer(array|string $body, int $status = 200): void
    {
        Http::fake(function (Request $request, array $options) use ($body, $status) {
            $this->options[] = $options;

            return Http::response($body, $status);
        });
    }

    public function test_learn_ham_posts_the_message_over_the_socket(): void
    {
        config(['restv2.rspamd.socket' => '/var/lib/rspamd/rspamd.sock', 'restv2.rspamd.timeout' => 30]);
        $this->answer(['success' => true]);

        $outcome = $this->client()->learnHam(self::MESSAGE);

        $this->assertSame(LearnResult::Done, $outcome->result);
        $this->assertNull($outcome->error);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && $r->url() === 'http://rspamd/learnham'
            && $r->header('Content-Type') === ['text/plain']
            && $r->body() === self::MESSAGE
            && ! $r->hasHeader('Flag')
            && ! $r->hasHeader('Password'));
        $this->assertSame('/var/lib/rspamd/rspamd.sock', $this->options[0]['curl'][CURLOPT_UNIX_SOCKET_PATH] ?? null);
        $this->assertEquals(30, $this->options[0]['timeout']);
    }

    public function test_learn_spam_posts_to_learnspam(): void
    {
        $this->answer(['success' => true]);

        $this->assertTrue($this->client()->learnSpam(self::MESSAGE)->succeeded());
        Http::assertSent(fn (Request $r): bool => $r->url() === 'http://rspamd/learnspam' && $r->body() === self::MESSAGE);
    }

    public function test_fuzzy_add_sends_the_flag_header(): void
    {
        $this->answer(['success' => true]);

        $this->client()->fuzzyAdd(self::MESSAGE, 13);
        $this->client()->fuzzyAdd(self::MESSAGE, 11);

        Http::assertSent(fn (Request $r): bool => $r->url() === 'http://rspamd/fuzzyadd' && $r->header('Flag') === ['13'] && $r->header('Content-Type') === ['text/plain']);
        Http::assertSent(fn (Request $r): bool => $r->url() === 'http://rspamd/fuzzyadd' && $r->header('Flag') === ['11']);
    }

    public function test_the_configured_timeout_is_used(): void
    {
        config(['restv2.rspamd.timeout' => 7]);
        $this->answer(['success' => true]);

        $this->client()->learnSpam(self::MESSAGE);

        $this->assertEquals(7, $this->options[0]['timeout']);
    }

    public function test_stat_takes_a_timeout_and_keeps_the_short_default_without(): void
    {
        $this->answer(['actions' => []]);

        $this->client()->stat();
        $this->client()->stat(30);

        $this->assertEquals(10, $this->options[0]['timeout']);
        $this->assertEquals(30, $this->options[1]['timeout']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && $r->url() === 'http://rspamd/stat');
    }

    public function test_status_208_is_already_learned(): void
    {
        $this->answer(['error' => '<abc@example.net> has been already learned as ham, ignore it'], 208);

        $outcome = $this->client()->learnHam(self::MESSAGE);

        $this->assertSame(LearnResult::AlreadyLearned, $outcome->result);
        $this->assertTrue($outcome->succeeded());
    }

    public function test_an_already_learned_error_text_counts_as_success_whatever_the_status(): void
    {
        $this->answer(['error' => '<abc@example.net> has been ALREADY LEARNED as spam, ignore it'], 404);

        $this->assertSame(LearnResult::AlreadyLearned, $this->client()->learnSpam(self::MESSAGE)->result);
    }

    public function test_no_content_for_fuzzy_hashes_is_reported_as_such(): void
    {
        $this->answer(['error' => 'No content to generate fuzzy'], 404);

        $outcome = $this->client()->fuzzyAdd(self::MESSAGE, 13);

        $this->assertSame(LearnResult::NoFuzzyContent, $outcome->result);
        $this->assertFalse($outcome->succeeded());
    }

    public function test_another_error_fails_with_its_text(): void
    {
        $this->answer(['error' => 'x'], 500);

        $outcome = $this->client()->learnHam(self::MESSAGE);

        $this->assertSame(LearnResult::Failed, $outcome->result);
        $this->assertSame('x', $outcome->error);
    }

    public function test_an_error_status_without_error_text_fails(): void
    {
        $this->answer('<html>Bad gateway</html>', 502);

        $outcome = $this->client()->learnHam(self::MESSAGE);

        $this->assertSame(LearnResult::Failed, $outcome->result);
        $this->assertStringContainsString('502', (string) $outcome->error);
    }

    public function test_a_connection_failure_fails_with_the_curl_error(): void
    {
        Http::fake(fn () => Http::failedConnection('cURL error 7: Failed to connect to /var/lib/rspamd/rspamd.sock'));

        $outcome = $this->client()->learnSpam(self::MESSAGE);

        $this->assertSame(LearnResult::Failed, $outcome->result);
        $this->assertStringContainsString('cURL error 7', (string) $outcome->error);
    }

    public function test_a_timeout_fails(): void
    {
        Http::fake(fn () => Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds'));

        $outcome = $this->client()->fuzzyAdd(self::MESSAGE, 11);

        $this->assertSame(LearnResult::Failed, $outcome->result);
        $this->assertStringContainsString('timed out', (string) $outcome->error);
    }

    public function test_outcome_constructors(): void
    {
        $this->assertTrue(LearnOutcome::done()->succeeded());
        $this->assertTrue(LearnOutcome::alreadyLearned()->succeeded());
        $this->assertFalse(LearnOutcome::noFuzzyContent()->succeeded());
        $this->assertSame('boom', LearnOutcome::failed('boom')->error);
    }

    public function test_actions_are_read_and_decoded(): void
    {
        $this->answer([['action' => 'reject', 'value' => 15], ['action' => 'add header', 'value' => 8]]);

        $this->assertSame([['action' => 'reject', 'value' => 15], ['action' => 'add header', 'value' => 8]], $this->client()->actions());
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && $r->url() === 'http://rspamd/actions');
    }

    public function test_actions_that_are_not_json_are_unavailable(): void
    {
        $this->answer('<html>no</html>');

        $this->expectException(RspamdUnavailable::class);
        $this->client()->actions();
    }

    public function test_stat_that_is_not_json_is_unavailable(): void
    {
        $this->answer('not json');

        $this->expectException(RspamdUnavailable::class);
        $this->client()->stat();
    }
}
