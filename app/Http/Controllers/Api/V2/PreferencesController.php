<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\UpdatePreferencesRequest;
use App\Http\Resources\Api\V2\PreferencesResource;
use App\Services\Preferences\PreferencesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user's preferences (FR-030, FR-031). Not available to API keys.
 */
class PreferencesController extends Controller
{
    public function __construct(private readonly PreferencesService $preferences) {}

    public function show(Request $request): JsonResponse
    {
        return (new PreferencesResource($this->preferences->effective(RequestContext::requirePrincipal($request))))->withETag();
    }

    public function update(UpdatePreferencesRequest $request): JsonResponse
    {
        $preferences = $this->preferences->update($request, RequestContext::requirePrincipal($request), $request->changes());

        return (new PreferencesResource($preferences))->withETag();
    }
}
