<?php

namespace Tests\Unit\Http;

use App\Auth\PermissionFlag;
use App\Exceptions\PermissionFlagRequiredException;
use App\Exceptions\ProblemRenderer;
use Illuminate\Http\Request;
use Tests\Support\Contract\OpenApiContract;
use Tests\TestCase;

/**
 * G34: one shared problem for missing permission flags, `requiredFlags` and `requiredFlagsMatch`
 * (docs/conventions.md "Permission refusals"), which every feature's variant extends.
 */
class PermissionFlagRequiredProblemTest extends TestCase
{
    public function test_the_problem_names_the_flags_and_how_they_combine(): void
    {
        $response = ProblemRenderer::render(new PermissionFlagRequiredException([PermissionFlag::DomainRelayhost, PermissionFlag::MailboxRelayhost], 'any'), Request::create('/api/v2/x'));
        $body = json_decode((string) $response->getContent(), true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('/api/v2/problems/permission-flag-required', $body['type']);
        $this->assertSame(['domainRelayhost', 'mailboxRelayhost'], $body['requiredFlags']);
        $this->assertSame('any', $body['requiredFlagsMatch']);
        $this->assertSame([], OpenApiContract::shared()->validateComponent($body, 'PermissionFlagRequiredProblem'));
    }

    public function test_all_is_the_default(): void
    {
        $e = new PermissionFlagRequiredException(['syncjobs']);

        $this->assertSame(['requiredFlags' => ['syncjobs'], 'requiredFlagsMatch' => 'all'], $e->extensions);
    }

    public function test_feature_variants_extend_the_shared_schema(): void
    {
        $contract = OpenApiContract::shared();
        $problem = ['type' => '/api/v2/problems/permission-flag-required', 'title' => 'Permission flag required', 'status' => 403, 'requestId' => str_repeat('a', 32), 'requiredFlags' => ['syncjobs'], 'requiredFlagsMatch' => 'all'];

        $this->assertSame([], $contract->validateComponent($problem, 'PermissionFlagRequiredProblem'));
        $this->assertNotSame([], $contract->validateComponent(['requiredFlagsMatch' => 'some'] + $problem, 'PermissionFlagRequiredProblem'));
        $this->assertNotSame([], $contract->validateComponent(['requiredFlags' => []] + $problem, 'PermissionFlagRequiredProblem'));

        foreach (glob(__DIR__.'/../../../api/components/schemas/*Permission*Problem.yaml') ?: [] as $file) {
            if (basename($file) !== 'PermissionFlagRequiredProblem.yaml') {
                $this->assertStringContainsString("\$ref: './PermissionFlagRequiredProblem.yaml'", (string) file_get_contents($file), basename($file));
            }
        }
    }
}
