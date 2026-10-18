<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V2\SessionResource;
use Illuminate\Http\Request;

/**
 * The browser session (contract `Session`). getSession never fails for a missing or ended
 * session: it reports `anonymous`.
 */
class SessionController extends Controller
{
    public function show(Request $request): SessionResource
    {
        return SessionResource::fromSession($request->session());
    }
}
