<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListMtaStsPoliciesRequest;
use App\Http\Requests\Api\V2\StoreMtaStsPolicyRequest;
use App\Http\Requests\Api\V2\UpdateMtaStsPolicyRequest;
use App\Http\Resources\Api\V2\MtaStsPolicyResource;
use App\Services\Domains\DomainScope;
use App\Services\MtaSts\MtaStsPolicyService;
use App\Support\Mailcow\UnicodeSearch;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * listMtaStsPolicies, createMtaStsPolicy, getMtaStsPolicy and updateMtaStsPolicy (feature 010 US4).
 * Policies are addressed through `domain` rows only, so alias domain names (and legacy rows stored
 * under them) answer `404`. The role is checked first, then the domain in the caller's scope.
 */
class MtaStsPolicyController extends Controller
{
    public function __construct(private readonly MtaStsPolicyService $policies) {}

    public function index(ListMtaStsPoliciesRequest $request, DomainScope $scope): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $list = $request->listQuery();
        $filters = $request->filters();
        $query = DB::table('mta_sts as m')->join('domain as d', 'd.domain', '=', 'm.domain')
            ->select('m.*', DB::raw('CAST(m.max_age AS UNSIGNED) AS max_age_number'));
        $scope->restrict($query, $principal, 'm.domain');

        if ($filters['active'] !== null) {
            $query->where('m.active', $filters['active'] ? '!=' : '=', 0);
        }

        if ($filters['mode'] !== null) {
            $query->where('m.mode', $filters['mode']);
        }

        if ($list->search !== null) {
            $unicode = UnicodeSearch::matchingKeys($scope->restrict(DB::table('mta_sts as m')->join('domain as d', 'd.domain', '=', 'm.domain'), $principal, 'm.domain'), 'm.domain', ['m.domain'], $list->search);
            $query->where(function (Builder $inner) use ($list, $unicode): void {
                $list->applySearch($inner, ['m.domain']);

                if ($unicode !== []) {
                    $inner->orWhereIn('m.domain', $unicode);
                }
            });
        }

        $page = $list->paginate($query, fn (object $row): array => MtaStsPolicyResource::present($row), 'm.domain');

        return response()->json($page, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function store(StoreMtaStsPolicyRequest $request): JsonResponse
    {
        return MtaStsPolicyResource::response($this->policies->create(RequestContext::requirePrincipal($request), $request->members()), 201);
    }

    public function show(Request $request, string $policyDomain): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('mtaSts.use');

        return MtaStsPolicyResource::response($this->policies->find($principal, $policyDomain));
    }

    public function update(UpdateMtaStsPolicyRequest $request, string $policyDomain): JsonResponse
    {
        return MtaStsPolicyResource::response($this->policies->update(RequestContext::requirePrincipal($request), $policyDomain, $request->members(), $request->header('If-Match')));
    }
}
