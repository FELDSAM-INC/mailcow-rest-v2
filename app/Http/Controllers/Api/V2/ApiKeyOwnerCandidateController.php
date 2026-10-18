<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ListApiKeyOwnerCandidatesRequest;
use App\Http\Resources\Api\V2\ApiKeyOwnerCandidateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * listApiKeyOwnerCandidates (FR-057): the active administrators and domain administrators of
 * mailcow's `admin` table, sorted by username. Mailbox users are never listed. Not audited.
 */
class ApiKeyOwnerCandidateController extends Controller
{
    public function index(ListApiKeyOwnerCandidatesRequest $request): JsonResponse
    {
        $query = $request->listQuery();
        $builder = DB::table('admin')->select(['username', 'superadmin'])->where('active', 1);

        if ($request->role() !== null) {
            $builder->where('superadmin', $request->role() === 'admin' ? 1 : 0);
        }

        $query->applySearch($builder, ['username']);
        $page = $query->paginate($builder, fn (mixed $row): array => (new ApiKeyOwnerCandidateResource($row))->toArray($request));

        return response()->json($page, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
