<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Policies\QuarantinePolicy;
use App\Services\Quarantine\DownloadFileName;
use App\Services\Quarantine\QuarantineContent;
use App\Services\Quarantine\QuarantineQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * downloadQuarantineMessage and downloadQuarantineAttachment (feature 006 research R6, FR-016 to
 * FR-018): always files to save that no browser displays, sniffs or runs in the API's origin
 * (`attachment`, `nosniff`, a sandboxing CSP, never cached); attachments always as
 * `application/octet-stream`. Reads: read-only keys may download, and nothing is audited.
 */
class QuarantineItemDownloadController extends Controller
{
    public function message(Request $request, string $itemId, QuarantinePolicy $policy, QuarantineQuery $query, QuarantineContent $content): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->authorize($principal, QuarantinePolicy::DOWNLOAD_MESSAGE);
        $item = $query->find($principal, (int) $itemId) ?? throw new NotFoundHttpException('The quarantine item does not exist.');
        $content->requireDownloadable($item);

        return self::file($content->stored($item), 'message/rfc822', DownloadFileName::forMessage($item->getRawOriginal('subject'), (string) $item->getRawOriginal('qid')));
    }

    public function attachment(Request $request, string $itemId, string $position, QuarantinePolicy $policy, QuarantineQuery $query, QuarantineContent $content): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->authorize($principal, QuarantinePolicy::VIEW_ATTACHMENTS);
        $item = $query->find($principal, (int) $itemId) ?? throw new NotFoundHttpException('The quarantine item does not exist.');
        $content->requireDownloadable($item);
        $attachment = $content->parse($item)->attachment((int) $position) ?? throw new NotFoundHttpException('The attachment does not exist.');

        return self::file($attachment->content, 'application/octet-stream', $attachment->fileName);
    }

    private static function file(string $bytes, string $type, string $name): Response
    {
        $response = new Response($bytes, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => DownloadFileName::disposition($name),
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
