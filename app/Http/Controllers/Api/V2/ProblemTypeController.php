<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * getProblemTypeDocumentation (constitution II, R27): the `## <name>` section of
 * `docs/problems.md` as HTML, so that every documented problem type URI dereferences on each
 * installation. HTML in the Markdown is escaped.
 */
class ProblemTypeController extends Controller
{
    public function show(string $problemType): Response
    {
        $section = self::section($problemType) ?? throw new NotFoundHttpException('The problem type is not documented.');
        $content = Str::markdown($section, ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.e($problemType).' – mailcow REST API v2</title></head>'
            .'<body><main>'.$content.'</main></body></html>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The Markdown of the section, from its heading to the next `## ` heading.
     */
    private static function section(string $name): ?string
    {
        $markdown = (string) @file_get_contents(base_path('docs/problems.md'));
        $quoted = preg_quote($name, '/');

        if (preg_match('/^## '.$quoted.'[ \t]*\R(.*?)(?=^## |\z)/ms', $markdown, $m) !== 1) {
            return null;
        }

        return '## '.$name."\n".$m[1];
    }
}
