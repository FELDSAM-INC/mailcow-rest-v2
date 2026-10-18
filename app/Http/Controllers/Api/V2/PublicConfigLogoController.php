<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Branding\PublicConfigService;
use App\Support\Http\ETag;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * getPublicConfigLogo (FR-042, R15): the logo uploaded in the legacy UI with its media type.
 * With the current version (`v`) it is cacheable for a year; otherwise caches revalidate. The
 * Content-Security-Policy sandboxes SVG logos, so that they cannot run scripts.
 */
class PublicConfigLogoController extends Controller
{
    private const CSP = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

    public function show(Request $request, PublicConfigService $config, string $variant): Response
    {
        $logo = $config->logo($variant) ?? throw new NotFoundHttpException('No logo is uploaded for this variant.');
        $etag = ETag::of(['logo', $variant, $logo['version']]);
        $cacheControl = $request->query('v') === $logo['version'] ? 'public, max-age=31536000, immutable' : 'no-cache, public';

        $response = ETag::ifNoneMatchMatches($request, $etag)
            ? response('', 304)
            : response($logo['bytes'], 200, ['Content-Type' => $logo['type']]);

        return $response->withHeaders([
            'ETag' => $etag,
            'Cache-Control' => $cacheControl,
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
