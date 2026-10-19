<?php

namespace App\Http\Controllers\Api\V2;

use App\Clients\Rspamd\RspamdUnavailable;
use App\Clients\Rspamd\SettingsMapClient;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getEffectiveRspamdSettingsMap (feature 013 FR-060): the settings map exactly as mailcow generates
 * it; an unreachable endpoint is `503 temporary-failure`. Administrators only.
 */
class RspamdSettingsMapController extends Controller
{
    public function show(Request $request, SettingsPolicy $policy, SettingsMapClient $client): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));

        try {
            $content = $client->fetch();
        } catch (RspamdUnavailable) {
            throw ProblemException::temporaryFailure('settingsMap', null, 'mailcow\'s Rspamd settings map endpoint could not be reached. Retry later.');
        }

        return SettingsResource::response(['content' => $content]);
    }
}
