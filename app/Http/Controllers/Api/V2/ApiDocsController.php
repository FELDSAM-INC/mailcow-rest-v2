<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * getApiDocs: Swagger UI (`swagger-ui-dist`, Apache-2.0) rendering `GET /openapi.yaml`. Its CSS
 * and JavaScript are inlined from `resources/docs/` (`composer docs:assets`, run by the image
 * build), so that the page loads nothing from another origin. Without the assets the page links
 * to the contract instead.
 */
class ApiDocsController extends Controller
{
    public function show(): Response
    {
        $css = self::asset('swagger-ui.css');
        $js = self::asset('swagger-ui-bundle.js');

        $body = $css === null || $js === null
            ? '<main><h1>mailcow REST API v2</h1><p>The documentation assets are not installed. The contract is available at <a href="openapi.yaml">openapi.yaml</a>.</p></main>'
            : '<div id="swagger-ui"></div><script>'.$js.'</script><script>'
                ."window.addEventListener('load', function () { window.ui = SwaggerUIBundle({ url: 'openapi.yaml', dom_id: '#swagger-ui', deepLinking: true, validatorUrl: null, withCredentials: true }); });"
                .'</script>';

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>mailcow REST API v2</title>'
            .($css === null ? '' : '<style>'.$css.'</style>')
            .'</head><body>'.$body.'</body></html>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * getApiRoot: the base path redirects here. The `Location` is relative, so it keeps the
     * scheme and host the client used behind mailcow's nginx.
     */
    public function root(): Response
    {
        return response('', 302, ['Location' => '/api/v2/docs']);
    }

    /**
     * An inlined asset, made safe to embed in its element; null when it is not installed.
     */
    private static function asset(string $name): ?string
    {
        $file = resource_path('docs/'.$name);

        if (! is_file($file)) {
            return null;
        }

        $content = (string) file_get_contents($file);

        return str_ireplace(['</script', '</style'], ['<\/script', '<\/style'], $content);
    }
}
