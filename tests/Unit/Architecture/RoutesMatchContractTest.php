<?php

namespace Tests\Unit\Architecture;

use Illuminate\Routing\Route;
use Tests\Support\Contract\OpenApiContract;
use Tests\Support\FeatureRegistry;
use Tests\TestCase;

/**
 * Constitution I: every /api/v2 route is a contract operation of an implemented feature
 * (tests/feature-registry.json). Contract paths of planned features are ignored; test-only routes
 * under /api/v2/__testing/ are exempt.
 */
class RoutesMatchContractTest extends TestCase
{
    public function test_every_route_is_an_operation_of_an_implemented_feature(): void
    {
        $contract = OpenApiContract::shared();
        $registry = FeatureRegistry::load();
        $violations = [];
        $checked = 0;

        /** @var Route $route */
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');

            if (! str_starts_with($uri, '/api/v2/') || str_starts_with($uri, '/api/v2/__testing/')) {
                continue;
            }

            // Laravel parameter names need not equal the contract's: match on a sample value.
            $path = (string) preg_replace('/\{[^}]+\}/', 'sample', $uri);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $checked++;
                $operation = $contract->match($method, $path);

                if ($operation === null) {
                    $violations[] = "{$method} {$uri} is not in the contract";
                } elseif ($registry->featureOf($operation->operationId) === null) {
                    $violations[] = "{$method} {$uri} ({$operation->operationId}) belongs to no implemented feature";
                }
            }
        }

        $this->assertSame([], $violations);
        $this->assertGreaterThan(0, $checked, 'no /api/v2 route is registered');
    }

    public function test_every_registered_operation_is_in_the_contract(): void
    {
        $missing = array_diff(FeatureRegistry::load()->operations(), OpenApiContract::shared()->operationIds());

        $this->assertSame([], array_values($missing));
    }
}
