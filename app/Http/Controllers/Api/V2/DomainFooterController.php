<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ReplaceDomainFooterRequest;
use App\Models\Mailcow\Domain;
use App\Services\Domains\DomainFooterService;
use App\Services\Domains\DomainScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * getDomainFooter and replaceDomainFooter (FR-051–FR-055).
 */
class DomainFooterController extends Controller
{
    public function __construct(private readonly DomainFooterService $footers) {}

    public function show(Request $request, string $domain, DomainScope $scope): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('updateFooter', Domain::class);
        $name = $scope->resolve($principal, $domain) ?? throw new NotFoundHttpException('The domain does not exist.');

        return $this->single($this->footers->read($name));
    }

    public function update(ReplaceDomainFooterRequest $request): JsonResponse
    {
        return $this->single($this->footers->replace(RequestContext::requirePrincipal($request), $request->domain(), $request->members(), $request->header('If-Match')));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function single(array $data): JsonResponse
    {
        return response()->json(['data' => $data], 200, ['ETag' => (string) $data['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
