<?php

namespace App\Services\MailboxGroups;

use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\IdentityProvider\TemplateMarker;
use App\Services\Mailboxes\MailboxTemplateApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GroupTemplates
{
    /** @param list<string> $members
     * @return array<string, int|null>
     */
    public function snapshot(array $members): array
    {
        $result = array_fill_keys($members, null);
        $rows = DB::table('mailbox_group_member as m')->join('mailbox_group as g', 'g.id', '=', 'm.group_id')
            ->join('templates as t', 't.id', '=', 'g.template_id')->whereIn('m.username', $members)
            ->where('g.active', 1)->where('t.type', 'mailbox')->orderByDesc('g.priority')->orderBy('g.id')
            ->get(['m.username', 't.id']);
        foreach ($rows as $row) {
            $result[$row->username] ??= (int) $row->id;
        }

        return $result;
    }

    /** @return array<string, int|null> */
    public function snapshotForTemplate(int $id): array
    {
        return $this->snapshot(DB::table('mailbox_group_member as m')->join('mailbox_group as g', 'g.id', '=', 'm.group_id')
            ->where('g.template_id', $id)->distinct()->pluck('m.username')->all());
    }

    /** @return list<string> */
    public function templateChanged(int $id, Principal $actor): array
    {
        return $this->applyChanged(array_filter($this->snapshotForTemplate($id), fn ($effective): bool => $effective === $id), $actor, true);
    }

    /** @param array<string, int|null> $before
     * @return list<string>
     */
    public function applyChanged(array $before, Principal $actor, bool $force = false): array
    {
        $warnings = [];
        foreach ($this->snapshot(array_keys($before)) as $mailbox => $templateId) {
            if ($templateId === null || (! $force && $templateId === $before[$mailbox])) {
                continue;
            }
            $template = DB::table('templates')->where('id', $templateId)->first();
            $row = DB::table('mailbox')->where('username', $mailbox)->first();
            if ($template === null || $row === null) {
                continue;
            }
            try {
                app(MailboxTemplateApplication::class)->apply(
                    new Principal('mailbox-group-template', Role::Admin, AccountKind::Admin, AuthMethod::Password),
                    $mailbox, (string) $template->attributes, (string) $row->name,
                    ['templateName' => $template->template, 'templateMarker' => TemplateMarker::for($template->attributes),
                        'auditActor' => ['username' => $actor->actingAccount()->username, 'role' => null],
                        'auditDetails' => ['trigger' => 'mailboxGroup', 'template' => $template->template]],
                    in_array($row->authsource, ['mailcow', 'ldap', 'keycloak'], true));
            } catch (Throwable $e) {
                Log::warning('The group template could not be applied.', ['mailbox' => $mailbox, 'exception' => $e]);
                $warnings[] = 'templateApplicationFailed:'.$mailbox;
            }
        }

        return $warnings;
    }
}
