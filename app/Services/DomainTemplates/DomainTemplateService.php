<?php

namespace App\Services\DomainTemplates;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\Domains\DomainPresenter;
use App\Services\Domains\DomainRules;
use App\Services\Domains\DomainTagSet;
use App\Services\Domains\DomainVersion;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Http\ListQuery;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Domain templates (`templates` rows of `type = 'domain'`, FR-045–FR-050, research D22): list,
 * read, create, edit and delete. Names are unique without regard to letter case (checked under a
 * lock of every domain template row); the template named "Default" cannot be renamed or deleted.
 * Values follow the domain rules and are stored with the legacy keys, so that legacy's add form keeps
 * working. Every change is audited; templates never change existing domains.
 *
 * The default mailbox quota of the legacy format is not one of the values (owner decision
 * 2026-10-18). It is kept valid for legacy's add-domain form (0 < default ≤ maximum mailbox quota):
 * a creation stores the maximum mailbox quota, an edit lowers a larger value to it and replaces a
 * missing or zero one with it. It is never audited.
 */
class DomainTemplateService
{
    public const SORTS = ['name' => 'template', 'id' => 'id', 'createdAt' => 'created', 'modifiedAt' => 'modified'];

    public const VALUE_FIELDS = [
        'tags', 'maxMailboxes', 'maxAliases', 'maxMailboxQuota', 'domainQuota', 'rateLimit',
        'active', 'globalAddressList', 'backupMx', 'relayAllRecipients', 'relayUnknownOnly', 'dkimSelector', 'dkimKeySize',
    ];

    public const READ_ONLY = ['id', 'isDefault', 'createdAt', 'modifiedAt', 'etag'];

    public function __construct(
        private readonly DomainTemplateAttributes $codec,
        private readonly DomainRules $rules,
        private readonly DomainVersion $version,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(ListQuery $list): array
    {
        $query = DB::table('templates')->where('type', 'domain');

        if ($list->search !== null) {
            $query->where('template', 'like', ListQuery::likePattern($list->search));
        }

        return $list->paginate($query, fn (object $row): array => $this->present($row), 'id');
    }

    public function find(int $id): ?stdClass
    {
        $row = DB::table('templates')->where('type', 'domain')->where('id', $id)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * The contract's `DomainTemplate`.
     *
     * @return array<string, mixed>
     */
    public function present(object $row): array
    {
        $values = $this->codec->decode($row->attributes);

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->template,
            'isDefault' => self::isDefault((string) $row->template),
            'tags' => DomainTagSet::sort(array_values($values['tags'])),
            'maxMailboxes' => $values['maxMailboxes'],
            'maxAliases' => $values['maxAliases'],
            'maxMailboxQuota' => $values['maxMailboxQuota'],
            'domainQuota' => $values['domainQuota'],
            'rateLimit' => $values['rateLimit'],
            'active' => $values['active'],
            'globalAddressList' => $values['globalAddressList'],
            'backupMx' => $values['backupMx'],
            'relayAllRecipients' => $values['relayAllRecipients'],
            'relayUnknownOnly' => $values['relayUnknownOnly'],
            'dkimSelector' => $values['dkimSelector'],
            'dkimKeySize' => $values['dkimKeySize'],
            'createdAt' => DomainPresenter::time($row->created),
            'modifiedAt' => DomainPresenter::time($row->modified),
            'etag' => $this->version->forTemplate($row),
        ];
    }

    /**
     * @param  array<string, mixed>  $input  the sent members of `DomainTemplateCreateRequest`
     */
    public function create(Principal $principal, array $input): stdClass
    {
        $name = (string) $input['name'];
        $values = $this->checked(array_merge($this->codec->defaults(), array_intersect_key($input, array_flip(self::VALUE_FIELDS))), []);

        return DB::transaction(function () use ($principal, $name, $values): stdClass {
            $this->assertUnique($name, null);
            $id = (int) DB::table('templates')->insertGetId(['type' => 'domain', 'template' => $name, 'attributes' => $this->codec->encode($values, DomainRules::legacyDefaultQuota(null, (int) $values['maxMailboxQuota']))]);
            $this->audit->record(AuditEventType::DomainTemplateCreation, true, $principal, self::target($id, $name), null, null, ['values' => $values]);

            return $this->find($id) ?? throw new NotFoundHttpException('The domain template does not exist.');
        });
    }

    /**
     * @param  array<string, mixed>  $patch  the sent members of `DomainTemplatePatch`
     */
    public function update(Principal $principal, int $id, array $patch, ?string $ifMatch): stdClass
    {
        return DB::transaction(function () use ($principal, $id, $patch, $ifMatch): stdClass {
            DB::table('templates')->where('type', 'domain')->lockForUpdate()->get(['id']);
            $row = $this->find($id) ?? throw new NotFoundHttpException('The domain template does not exist.');

            if (ETag::preconditionFails($ifMatch, $this->version->forTemplate($row))) {
                throw new PreconditionFailedHttpException('The domain template was changed since it was read.');
            }

            $current = $this->codec->decode($row->attributes);
            $name = array_key_exists('name', $patch) ? (string) $patch['name'] : (string) $row->template;

            if ($name !== (string) $row->template) {
                if (self::isDefault((string) $row->template)) {
                    throw new ProblemException(ProblemType::DomainTemplateProtected, 'The Default domain template cannot be renamed.');
                }

                $this->assertUnique($name, $id);
            }

            $violations = new FieldViolations;
            $presented = $this->present($row);

            foreach (array_intersect_key($patch, array_flip(self::READ_ONLY)) as $member => $value) {
                if ($value !== $presented[$member]) {
                    $violations->add($member, 'immutable', [], 'This member is read-only and differs from the current value.');
                }
            }

            $values = $this->checked(array_merge($current, array_intersect_key($patch, array_flip(self::VALUE_FIELDS))), $current['tags'], $violations);
            $changes = [];

            if ($name !== (string) $row->template) {
                $changes['name'] = ['old' => (string) $row->template, 'new' => $name];
            }

            foreach (self::VALUE_FIELDS as $field) {
                $old = $field === 'tags' ? DomainTagSet::sort($current['tags']) : $current[$field];
                $new = $field === 'tags' ? DomainTagSet::sort($values['tags']) : $values[$field];

                if ($old !== $new) {
                    $changes[$field] = ['old' => $old, 'new' => $new];
                }
            }

            $encoded = $this->codec->encode($values, DomainRules::legacyDefaultQuota($this->codec->storedDefaultQuota($row->attributes), (int) $values['maxMailboxQuota']));

            if ($changes !== [] || $encoded !== (string) $row->attributes) {
                DB::table('templates')->where('id', $id)->where('type', 'domain')->update(['template' => $name, 'attributes' => $encoded]);
            }

            $this->audit->record(AuditEventType::DomainTemplateUpdate, true, $principal, self::target($id, $name), null, null, ['changes' => $changes === [] ? new stdClass : $changes]);

            return $this->find($id) ?? throw new NotFoundHttpException('The domain template does not exist.');
        });
    }

    /**
     * Deletes a template; a bulk deletion passes its request ID for the audit record (G40).
     */
    public function delete(Principal $principal, int $id, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        DB::transaction(function () use ($principal, $id, $ifMatch, $bulkRequestId): void {
            $row = DB::table('templates')->where('type', 'domain')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundHttpException('The domain template does not exist.');

            if (ETag::preconditionFails($ifMatch, $this->version->forTemplate($row))) {
                throw new PreconditionFailedHttpException('The domain template was changed since it was read.');
            }

            if (self::isDefault((string) $row->template)) {
                throw new ProblemException(ProblemType::DomainTemplateProtected, 'The Default domain template cannot be deleted.');
            }

            DB::table('templates')->where('id', $id)->where('type', 'domain')->delete();
            $this->audit->record(AuditEventType::DomainTemplateDeletion, true, $principal, self::target($id, (string) $row->template), null, null, ['values' => $this->codec->decode($row->attributes)] + ($bulkRequestId === null ? [] : ['bulkRequestId' => $bulkRequestId]));
        });
    }

    public static function isDefault(string $name): bool
    {
        return mb_strtolower($name) === 'default';
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $currentTags
     * @return array<string, mixed>
     */
    private function checked(array $values, array $currentTags, ?FieldViolations $violations = null): array
    {
        $violations ??= new FieldViolations;
        $checked = $this->rules->check($values, $violations, $currentTags);
        $violations->throwIfAny();

        return array_intersect_key($checked, array_flip(self::VALUE_FIELDS));
    }

    private function assertUnique(string $name, ?int $exceptId): void
    {
        $names = DB::table('templates')->where('type', 'domain')->lockForUpdate()->get(['id', 'template']);

        foreach ($names as $row) {
            if ((int) $row->id !== $exceptId && mb_strtolower((string) $row->template) === mb_strtolower($name)) {
                throw new ProblemException(ProblemType::DomainTemplateNameExists, "Another domain template is named \"{$row->template}\".");
            }
        }
    }

    /**
     * @return array{type: string, id: string, name: string}
     */
    public static function target(int $id, string $name): array
    {
        return ['type' => 'domainTemplate', 'id' => (string) $id, 'name' => $name];
    }
}
