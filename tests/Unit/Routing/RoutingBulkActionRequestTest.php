<?php

namespace Tests\Unit\Routing;

use App\Auth\Role;
use App\Http\Requests\Api\V2\RoutingBulkActionRequest;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T017 (FR-090, research R20): the body of every routing bulk action, with the actions
 * of its collection, 1 to 100 targets shaped like `BulkTarget`, and duplicates kept for the runner.
 */
class RoutingBulkActionRequestTest extends TestCase
{
    use RoutingTestHelpers;

    private const COLLECTIONS = [
        'relayhost' => ['activate', 'deactivate', 'delete'],
        'transport' => ['activate', 'deactivate', 'delete'],
        'recipientMap' => ['activate', 'deactivate', 'delete'],
        'tlsPolicyMap' => ['activate', 'deactivate', 'delete'],
        'bccMap' => ['activate', 'deactivate', 'switchToSender', 'switchToRecipient', 'delete'],
        'forwardingHost' => ['enableSpamFilter', 'disableSpamFilter', 'delete'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_keys(self::COLLECTIONS) as $collection) {
            Route::middleware(['api.v2', 'restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])
                ->post('/api/v2/__testing/routing-bulk/'.$collection, fn (RoutingBulkActionRequest $request) => response()->json(['action' => $request->action(), 'targets' => $request->targets()]))
                ->defaults('restv2.audit', ['event' => 'fromBulkAction', 'target' => $collection]);
        }

        $this->asAdmin();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function collections(): array
    {
        return array_combine(array_keys(self::COLLECTIONS), array_map(fn (string $c): array => [$c], array_keys(self::COLLECTIONS)));
    }

    #[DataProvider('collections')]
    public function test_each_collection_accepts_its_own_actions_only(string $collection): void
    {
        foreach (self::COLLECTIONS[$collection] as $action) {
            $this->postJson('/api/v2/__testing/routing-bulk/'.$collection, ['action' => $action, 'targets' => [['id' => '1']]], $this->csrf())
                ->assertOk()->assertJsonPath('action', $action);
        }

        $foreign = $collection === 'forwardingHost' ? 'activate' : 'enableSpamFilter';
        $response = $this->postJson('/api/v2/__testing/routing-bulk/'.$collection, ['action' => $foreign, 'targets' => [['id' => '1']]], $this->csrf())->assertStatus(422);
        $this->assertSame('notInList', $response->json('errors.action.0.code'));
        $this->assertSame(self::COLLECTIONS[$collection], $response->json('errors.action.0.params.allowed'));
    }

    public function test_one_to_one_hundred_targets_are_accepted(): void
    {
        $targets = array_map(fn (int $i): array => ['id' => (string) $i], range(1, 100));
        $this->postJson('/api/v2/__testing/routing-bulk/relayhost', ['action' => 'delete', 'targets' => $targets], $this->csrf())->assertOk();

        $response = $this->postJson('/api/v2/__testing/routing-bulk/relayhost', ['action' => 'delete', 'targets' => [...$targets, ['id' => '101']]], $this->csrf())->assertStatus(422);
        $this->assertSame('tooMany', $response->json('errors.targets.0.code'));
        $this->assertSame(['max' => 100], $response->json('errors.targets.0.params'));

        $response = $this->postJson('/api/v2/__testing/routing-bulk/relayhost', ['action' => 'delete', 'targets' => []], $this->csrf())->assertStatus(422);
        $this->assertSame('required', $response->json('errors.targets.0.code'));
    }

    public function test_targets_are_shaped_like_bulk_targets(): void
    {
        $response = $this->postJson('/api/v2/__testing/routing-bulk/transport', ['action' => 'activate', 'targets' => [['version' => '"x"'], ['id' => 5]]], $this->csrf())->assertStatus(422);
        $this->assertSame('required', $response->json('errors')['targets.0.id'][0]['code']);
        $this->assertSame('invalidType', $response->json('errors')['targets.1.id'][0]['code']);

        $this->postJson('/api/v2/__testing/routing-bulk/transport', ['action' => 'activate', 'targets' => [['id' => '3', 'version' => '"abc"'], ['id' => '3']]], $this->csrf())
            ->assertOk()
            ->assertJsonPath('targets', [['id' => '3', 'version' => '"abc"'], ['id' => '3', 'version' => null]]);
    }

    public function test_a_missing_action_is_required(): void
    {
        $this->postJson('/api/v2/__testing/routing-bulk/bccMap', ['targets' => [['id' => '1']]], $this->csrf())
            ->assertStatus(422)->assertJsonPath('errors.action.0.code', 'required');
    }

    public function test_the_whole_request_is_refused_for_a_caller_without_the_permission(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asRoutingDomainAdmin(['example.org']);

        $this->postJson('/api/v2/__testing/routing-bulk/relayhost', ['action' => 'delete', 'targets' => [['id' => '1']]], $this->csrf())
            ->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->postJson('/api/v2/__testing/routing-bulk/bccMap', ['action' => 'delete', 'targets' => [['id' => '1']]], $this->csrf())
            ->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['bccMaps']);

        $this->mailbox('anna@example.org');
        $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->postJson('/api/v2/__testing/routing-bulk/bccMap', ['action' => 'delete', 'targets' => [['id' => '1']]], $this->csrf())
            ->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
