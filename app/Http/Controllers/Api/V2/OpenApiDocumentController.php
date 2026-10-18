<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * getOpenApiDocument (constitution I): the bundled contract of the running release. The image
 * builds it into `resources/contract/openapi.yaml`; a development checkout serves the bundle of
 * `composer contract:bundle` (`build/contract/openapi.yaml`).
 */
class OpenApiDocumentController extends Controller
{
    public function show(): Response
    {
        $file = self::contractFile() ?? throw new RuntimeException('The bundled contract is missing: run `composer contract:bundle`.');

        return response((string) file_get_contents($file), 200, [
            'Content-Type' => 'application/yaml; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function contractFile(): ?string
    {
        foreach ([resource_path('contract/openapi.yaml'), base_path('build/contract/openapi.yaml')] as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }
}
