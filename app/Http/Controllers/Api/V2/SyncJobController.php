<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListSyncJobsRequest;
use App\Http\Requests\Api\V2\StoreSyncJobRequest;
use App\Http\Resources\Api\V2\SyncJobResource;
use App\Models\Mailcow\SyncJob;
use App\Policies\SyncJobPolicy;
use App\Services\SyncJobs\SyncJobQuery;
use App\Services\SyncJobs\SyncJobScope;
use App\Services\SyncJobs\SyncJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listSyncJobs, createSyncJob and getSyncJob (feature 008). The sync-jobs permission is checked
 * first (`403 permission-flag-required`), then the scope (`404`, exactly as for a job that does not
 * exist), then the request.
 */
class SyncJobController extends Controller
{
    public function __construct(
        private readonly SyncJobPolicy $policy,
        private readonly SyncJobScope $scope,
        private readonly SyncJobQuery $query,
    ) {}

    public function index(ListSyncJobsRequest $request): JsonResponse
    {
        $list = $request->listQuery();
        $page = $this->query->page(RequestContext::requirePrincipal($request), $list, $request->filters());

        return SyncJobResource::list(
            array_map(fn (SyncJob $job): array => SyncJobResource::present($job, $page['logs'][(int) $job->id] ?? false), $page['jobs']),
            $list->meta($page['total']),
        );
    }

    public function store(StoreSyncJobRequest $request, SyncJobService $service): JsonResponse
    {
        $job = $service->create(RequestContext::requirePrincipal($request), $request->members(), $request->target());

        return SyncJobResource::response(SyncJobResource::present($job, false), 201)->header('Location', '/api/v2/sync-jobs/'.$job->id);
    }

    public function show(Request $request, string $syncJobId): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->policy->authorize($principal);

        return SyncJobResource::response($this->present($this->scope->findInScope((int) $syncJobId, $principal)));
    }

    /**
     * The representation of a job in the caller's scope, or `404`.
     *
     * @return array<string, mixed>
     */
    private function present(?SyncJob $job): array
    {
        if ($job === null) {
            throw new NotFoundHttpException('The sync job does not exist.');
        }

        return SyncJobResource::present($job, $this->query->logAvailability([(int) $job->id])[(int) $job->id] ?? false);
    }
}
