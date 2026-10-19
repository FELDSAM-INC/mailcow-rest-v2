<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Settings\UpdatePasswordPolicyRequest;
use App\Models\Mailcow\Mailbox;
use App\Services\Passwords\PasswordPolicyReader;
use App\Services\Passwords\PasswordPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * getPasswordPolicy (feature 003 FR-063): the policy in mailcow's shared store as structured rules,
 * with the version tag feature 013 edits against. Administrators and domain administrators,
 * read-only keys included; `503` when the shared store is down.
 *
 * updatePasswordPolicy (feature 013 FR-036 to FR-038): administrators change the rules; the answer
 * carries the same version tag the read returns.
 */
class PasswordPolicyController extends Controller
{
    public function show(Request $request, PasswordPolicyReader $reader): JsonResponse
    {
        Gate::forUser(RequestContext::requirePrincipal($request))->authorize('viewPasswordPolicy', Mailbox::class);
        $policy = $reader->effective();

        return response()->json(['data' => $policy->toArray()], 200, ['ETag' => $policy->etag()], JSON_UNESCAPED_SLASHES);
    }

    public function update(UpdatePasswordPolicyRequest $request, PasswordPolicyService $policies): JsonResponse
    {
        /** @var array<string, int|bool> $patch */
        $patch = $request->patch();
        $policy = $policies->update($patch, $request->header('If-Match'), RequestContext::requirePrincipal($request));

        return response()->json(['data' => $policy->toArray()], 200, ['ETag' => $policy->etag()], JSON_UNESCAPED_SLASHES);
    }
}
