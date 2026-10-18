<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Passwords\PasswordPolicyReader;
use Illuminate\Http\JsonResponse;

/**
 * getOwnPasswordPolicy (feature 005 FR-014): the rules of the signed-in account's new password,
 * with the values and the version tag of feature 003's getPasswordPolicy, for every role and in a
 * session restricted by a forced password change.
 */
class OwnPasswordPolicyController extends Controller
{
    public function show(PasswordPolicyReader $reader): JsonResponse
    {
        $policy = $reader->effective();

        return response()->json(['data' => $policy->toArray()], 200, ['ETag' => $policy->etag()], JSON_UNESCAPED_SLASHES);
    }
}
