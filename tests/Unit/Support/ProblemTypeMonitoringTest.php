<?php

namespace Tests\Unit\Support;

use App\Clients\DockerApi\DockerApiTimeout;
use App\Clients\DockerApi\DockerApiUnreachable;
use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature 011 T014 (research R25, FR-065): the problem types of the monitoring feature, their
 * extension members, and the mapping of an unavailable container service to `temporary-failure`.
 */
class ProblemTypeMonitoringTest extends TestCase
{
    /**
     * @return array<string, array{ProblemType, string, int}>
     */
    public static function types(): array
    {
        return [
            'container-protected' => [ProblemType::ContainerProtected, '/api/v2/problems/container-protected', 409],
            'container-not-running' => [ProblemType::ContainerNotRunning, '/api/v2/problems/container-not-running', 409],
            'container-action-failed' => [ProblemType::ContainerActionFailed, '/api/v2/problems/container-action-failed', 502],
            'mail-service-not-running' => [ProblemType::MailServiceNotRunning, '/api/v2/problems/mail-service-not-running', 409],
            'queue-action-failed' => [ProblemType::QueueActionFailed, '/api/v2/problems/queue-action-failed', 502],
            'ban-rules-not-restored' => [ProblemType::BanRulesNotRestored, '/api/v2/problems/ban-rules-not-restored', 504],
            'ip-check-disabled' => [ProblemType::IpCheckDisabled, '/api/v2/problems/ip-check-disabled', 409],
        ];
    }

    #[DataProvider('types')]
    public function test_each_type_has_its_uri_status_and_title(ProblemType $type, string $uri, int $status): void
    {
        $this->assertSame($uri, $type->uri());
        $this->assertSame($status, $type->status());
        $this->assertNotSame('', $type->title());
        $this->assertNotSame('', $type->detail());
    }

    public function test_container_and_service_message_are_rendered_as_extension_members(): void
    {
        Route::middleware('api.v2')->get('/api/v2/__testing/container-failure', function (): never {
            throw new ProblemException(ProblemType::ContainerActionFailed, null, ['container' => 'sogo-mailcow', 'serviceMessage' => 'container did not start']);
        });

        $this->getJson('/api/v2/__testing/container-failure')
            ->assertStatus(502)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', '/api/v2/problems/container-action-failed')
            ->assertJsonPath('container', 'sogo-mailcow')
            ->assertJsonPath('serviceMessage', 'container did not start');
    }

    public function test_an_unreachable_container_service_is_a_temporary_failure(): void
    {
        Route::middleware('api.v2')->get('/api/v2/__testing/dockerapi-down', function (): never {
            throw new DockerApiUnreachable('connection refused');
        });
        Route::middleware('api.v2')->get('/api/v2/__testing/dockerapi-slow', function (): never {
            throw new DockerApiTimeout('timed out');
        });

        foreach (['/api/v2/__testing/dockerapi-down', '/api/v2/__testing/dockerapi-slow'] as $path) {
            $response = $this->getJson($path)
                ->assertStatus(503)
                ->assertJsonPath('type', '/api/v2/problems/temporary-failure')
                ->assertHeader('Retry-After', '5');
            $this->assertStringNotContainsString('connection refused', (string) $response->getContent());
        }
    }
}
