<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ReportSingleSignOnProviderErrorRequest;
use App\Services\IdentityProvider\SingleSignOnService;
use Illuminate\Http\Response;

/**
 * reportSingleSignOnProviderError (FR-063): `204`; the failed sign-in is audited.
 */
class SingleSignOnProviderErrorController extends Controller
{
    public function store(ReportSingleSignOnProviderErrorRequest $request, SingleSignOnService $sso): Response
    {
        $sso->reportProviderError($request, (string) $request->validated('error'), (string) $request->validated('state'));

        return response()->noContent();
    }
}
