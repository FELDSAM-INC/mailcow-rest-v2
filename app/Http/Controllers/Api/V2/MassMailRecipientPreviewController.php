<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\Settings\PreviewMassMailRecipientsRequest;
use App\Services\MassMail\SelectionResolver;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;

/**
 * previewMassMailRecipients (feature 013 FR-072, FR-073, FR-081): the recipients a selection yields
 * now. Changes nothing and is not audited; read-only administrator keys may use it.
 */
class MassMailRecipientPreviewController extends Controller
{
    public function store(PreviewMassMailRecipientsRequest $request, SelectionResolver $resolver): JsonResponse
    {
        $preview = $resolver->preview($request->selection(), ListQuery::fromRequest($request, ['address' => 'username'], 'address'));

        return response()->json($preview, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
