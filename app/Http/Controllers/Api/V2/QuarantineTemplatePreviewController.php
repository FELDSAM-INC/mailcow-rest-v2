<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\ValidationProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\PreviewQuarantineTemplateRequest;
use App\Rules\NotificationTemplate;
use App\Services\Quarantine\QuarantineSettingsService;
use App\Services\Quarantine\QuarantineTemplateRenderer;
use Illuminate\Http\JsonResponse;

/**
 * previewQuarantineTemplate (feature 006 FR-071): the given, stored or default template rendered
 * with sample dataset B and the chosen `quarantine_acl`, as text; a template the notifier cannot
 * use is `422 invalidTemplate` on `template`. Changes nothing and is not audited.
 */
class QuarantineTemplatePreviewController extends Controller
{
    public function store(PreviewQuarantineTemplateRequest $request, QuarantineSettingsService $settings, QuarantineTemplateRenderer $renderer): JsonResponse
    {
        $template = $request->template() ?? $settings->effectiveTemplate();
        $acl = $request->quarantineAcl();
        $result = $renderer->preview($template, $acl);
        $violation = NotificationTemplate::violationOf($result);

        if ($violation !== null) {
            throw new ValidationProblemException(['template' => [$violation]]);
        }

        $dataset = $renderer->datasets()[1];

        return response()->json(['data' => [
            'subject' => $settings->effectiveSubject(),
            'html' => $result->renders[0]['html'] ?? '',
            'text' => $result->renders[0]['text'] ?? '',
            'sample' => [
                'username' => $dataset['username'],
                'counter' => $dataset['counter'],
                'hostname' => $dataset['hostname'],
                'quarantineAcl' => $acl ? 1 : 0,
                'items' => $dataset['meta'],
            ],
        ]], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
