<?php

namespace App\Services\Mailboxes;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Http\RequestContext;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The legacy mass deletion of the mailbox template list (gap G39, `delete_selected` on
 * `tab-templates-mbox.twig`): up to 100 templates, each through MailboxTemplateService::delete()
 * with its version check, Default protection and audit record, on 002's BulkActionRunner. A target
 * is a template ID; anything else, and the ID of a domain template, is `notFound`.
 */
class MailboxTemplateBulkActions
{
    public const ACTIONS = ['delete'];

    public const LIMIT = 100;

    public function __construct(
        private readonly BulkActionRunner $runner,
        private readonly MailboxTemplateService $templates,
    ) {}

    /**
     * @param  list<array{id: string, version?: string|null}>  $targets
     * @return array{bulkRequestId: string, results: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function apply(Request $request, Principal $principal, array $targets): array
    {
        $bulkRequestId = RequestContext::requestId($request);
        /** @var array<string, string> $names */
        $names = [];

        return $this->runner->run(
            $request,
            $principal,
            AuditEventType::MailboxTemplateDeletion,
            $targets,
            function (string $id) use (&$names): ?string {
                $id = trim($id);

                if (! ctype_digit($id) || strlen(ltrim($id, '0')) > 10) {
                    return null;
                }

                $name = DB::table('templates')->where('type', 'mailbox')->where('id', (int) $id)->value('template');

                if (! is_string($name)) {
                    return null;
                }

                $key = (string) (int) $id;
                $names[$key] = $name;

                return $key;
            },
            function (string $key, ?string $ifMatch) use ($principal, $bulkRequestId): ?string {
                $this->templates->delete($principal, (int) $key, $ifMatch, $bulkRequestId);

                return null;
            },
            function (string $id, ?string $key) use (&$names): array {
                return $key !== null && isset($names[$key])
                    ? MailboxTemplateService::target((int) $key, $names[$key])
                    : ['type' => 'mailboxTemplate', 'id' => mb_substr($key ?? $id, 0, 255), 'name' => null];
            },
        );
    }
}
