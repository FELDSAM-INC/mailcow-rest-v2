<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\EnrolOwnSecondFactorRequest;
use App\Services\Auth\SessionManager;
use App\Services\SecondFactors\SecondFactorEnrolmentService;
use App\Services\SecondFactors\SecondFactorQueryService;
use App\Services\SecondFactors\SecondFactorRemovalService;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listOwnSecondFactors, getOwnSecondFactor, enrolOwnSecondFactor and deleteOwnSecondFactor
 * (feature 005 FR-027–FR-036): the signed-in account's own entries, sessions only.
 */
class OwnSecondFactorController extends Controller
{
    public function __construct(
        private readonly SecondFactorQueryService $query,
        private readonly SecondFactorEnrolmentService $enrolment,
        private readonly SessionManager $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->enrolment->assertEligible($principal);
        $list = ListQuery::fromRequest($request, ['id' => 'id', 'type' => 'authmech', 'name' => 'key_id'], 'id');

        return response()->json($this->query->list($principal, $list, $this->sessions->secondFactorId($request->session())), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, string $secondFactorId): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->enrolment->assertEligible($principal);
        $entry = $this->query->find($principal, (int) $secondFactorId, $this->sessions->secondFactorId($request->session()))
            ?? throw new NotFoundHttpException('The second factor does not exist.');

        return response()->json(['data' => $entry], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(EnrolOwnSecondFactorRequest $request): JsonResponse
    {
        $entry = $this->enrolment->enrol($request, RequestContext::requirePrincipal($request), $request->enrolment());

        return response()->json(['data' => $entry], 201, ['Location' => '/api/v2/me/second-factors/'.$entry['id']], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Request $request, string $secondFactorId, SecondFactorRemovalService $removal): Response
    {
        $removal->removeOne(RequestContext::requirePrincipal($request), (int) $secondFactorId);

        return response()->noContent();
    }
}
