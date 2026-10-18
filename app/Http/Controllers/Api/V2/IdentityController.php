<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\IdentityResource;
use Illuminate\Http\Request;

/**
 * getIdentity (FR-028, FR-029): the authenticated caller, available in restricted sessions.
 */
class IdentityController extends Controller
{
    public function show(Request $request): IdentityResource
    {
        return new IdentityResource(RequestContext::requirePrincipal($request));
    }
}
