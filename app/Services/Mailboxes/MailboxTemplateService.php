<?php

namespace App\Services\Mailboxes;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\Config\MailcowConfig;
use App\Services\Domains\DomainPresenter;
use App\Services\Identity\IdentityService;
use App\Support\Database\NamedLock;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Http\ListQuery;
use App\Support\Http\ProblemType;
use App\Support\Tags\TagSet;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Mailbox templates (`templates` rows of `type = 'mailbox'`, feature 003 FR-049–FR-053, research
 * R5): list, read, create, change and delete. Values a stored template lacks are reported with the
 * built-in defaults; writes store every legacy key with legacy's types (MailboxDefaults' codec), so
 * that legacy's forms keep working. Names are unique without regard to letter case, checked under
 * the named lock `restv2_mailbox_template_<sha1 of the lower-cased name>`; the template named
 * Default cannot be renamed or deleted. A change that changes nothing writes and records nothing.
 * Templates only pre-fill new mailboxes: no mailbox ever changes with them.
 */
class MailboxTemplateService
{
    /** Contract sort value => SQL expression; ties by ID. */
    public const SORTS = ['name' => 'LOWER(`template`)', 'id' => '`id`', 'createdAt' => '`created`', 'modifiedAt' => '`modified`'];

    public const VALUE_MEMBERS = [
        'quota', 'status', 'forcePasswordChange', 'forceSecondFactorSetup', 'webmailRedirect', 'protocolAccess',
        'userPermissions', 'tags', 'taggedMailHandling', 'quarantineNotification', 'quarantineCategory',
        'tlsEnforceInbound', 'tlsEnforceOutbound', 'rateLimit',
    ];

    public function __construct(
        private readonly MailboxDefaults $defaults,
        private readonly MailboxValidator $validator,
        private readonly MailboxVersion $version,
        private readonly NamedLock $locks,
        private readonly MailcowConfig $config,
        private readonly IdentityService $identity,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(Principal $principal, ListQuery $list): array
    {
        $query = DB::table('templates')->where('type', 'mailbox');

        if ($list->search !== null) {
            $query->whereRaw('LOWER(`template`) LIKE ?', [ListQuery::likePattern(mb_strtolower($list->search))]);
        }

        $total = (clone $query)->count();
        $direction = $list->order === 'desc' ? 'DESC' : 'ASC';
        $rows = $query->orderByRaw(self::SORTS[$list->sort].' '.$direction)->orderBy('id', $list->order === 'desc' ? 'desc' : 'asc')
            ->offset($list->offset)->limit($list->limit)->get();
        $flags = $this->identity->permissions($principal);

        return [
            'data' => array_values($rows->map(fn (object $row): array => $this->present($row, $principal, $flags))->all()),
            'meta' => $list->meta($total),
        ];
    }

    public function find(int $id): ?stdClass
    {
        $row = DB::table('templates')->where('type', 'mailbox')->where('id', $id)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * The contract's `MailboxTemplate`.
     *
     * @param  array<string, bool>|null  $flags  the caller's effective flags (read when null)
     * @return array<string, mixed>
     */
    public function present(object $row, Principal $principal, ?array $flags = null): array
    {
        $values = $this->defaults->templateValues($row->attributes);
        $values['tags'] = TagSet::sort($values['tags']);
        $values['userPermissions'] = UserPermissions::visibleTo($values['userPermissions'], $principal, $flags ?? $this->identity->permissions($principal));

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->template,
            'isDefault' => self::isDefault((string) $row->template),
            ...$values,
            'createdAt' => DomainPresenter::time($row->created),
            'modifiedAt' => DomainPresenter::time($row->modified),
            'etag' => $this->version->forTemplate($row),
        ];
    }

    /**
     * @param  array<string, mixed>  $input  the sent members of `MailboxTemplateCreateRequest`
     */
    public function create(Principal $principal, array $input): stdClass
    {
        $violations = new FieldViolations;
        $name = $this->name($input['name'] ?? null, $violations);
        $values = $this->checked(MailboxDefaults::merge($this->defaults->builtIn(), array_intersect_key($input, array_flip(self::VALUE_MEMBERS))), [], $violations);
        $violations->throwIfAny();

        return $this->locks->run([self::lockName($name)], fn (): stdClass => DB::transaction(function () use ($principal, $name, $values): stdClass {
            $this->assertUnique($name, null);
            $id = (int) DB::table('templates')->insertGetId(['type' => 'mailbox', 'template' => $name, 'attributes' => json_encode($this->defaults->encodeTemplate($values))]);
            $this->audit->record(AuditEventType::MailboxTemplateCreation, true, $principal, self::target($id, $name), null, null, ['values' => $values]);

            return $this->find($id) ?? throw new NotFoundHttpException('The mailbox template does not exist.');
        }));
    }

    /**
     * @param  array<string, mixed>  $patch  the sent members of `MailboxTemplatePatch`
     */
    public function update(Principal $principal, int $id, array $patch, ?string $ifMatch): stdClass
    {
        $row = $this->find($id) ?? throw new NotFoundHttpException('The mailbox template does not exist.');
        $violations = new FieldViolations;
        $name = array_key_exists('name', $patch) ? $this->name($patch['name'], $violations) : (string) $row->template;
        $violations->throwIfAny();

        return $this->locks->run([self::lockName($name)], fn (): stdClass => DB::transaction(function () use ($principal, $id, $patch, $ifMatch, $name): stdClass {
            $row = DB::table('templates')->where('type', 'mailbox')->where('id', $id)->lockForUpdate()->first();

            if (! $row instanceof stdClass) {
                throw new NotFoundHttpException('The mailbox template does not exist.');
            }

            if (ETag::preconditionFails($ifMatch, $this->version->forTemplate($row))) {
                throw new PreconditionFailedHttpException('The mailbox template was changed since it was read.');
            }

            if ($name !== (string) $row->template) {
                if (self::isDefault((string) $row->template)) {
                    throw new ProblemException(ProblemType::MailboxTemplateProtected, 'The Default mailbox template cannot be renamed.');
                }

                $this->assertUnique($name, $id);
            }

            $current = $this->defaults->templateValues($row->attributes);
            $current['tags'] = TagSet::sort($current['tags']);
            $violations = new FieldViolations;
            $values = $this->checked(MailboxDefaults::merge($current, array_intersect_key($patch, array_flip(self::VALUE_MEMBERS))), $current['tags'], $violations);
            $violations->throwIfAny();

            $changes = self::changes(['name' => (string) $row->template] + $current, ['name' => $name] + $values);

            if ($changes === []) {
                return $row;
            }

            DB::table('templates')->where('id', $id)->update(['template' => $name, 'attributes' => json_encode($this->defaults->encodeTemplate($values))]);
            $this->audit->record(AuditEventType::MailboxTemplateUpdate, true, $principal, self::target($id, $name), null, null, ['changes' => $changes]);

            return $this->find($id) ?? throw new NotFoundHttpException('The mailbox template does not exist.');
        }));
    }

    public function delete(Principal $principal, int $id, ?string $ifMatch): void
    {
        DB::transaction(function () use ($principal, $id, $ifMatch): void {
            $row = DB::table('templates')->where('type', 'mailbox')->where('id', $id)->lockForUpdate()->first();

            if (! $row instanceof stdClass) {
                throw new NotFoundHttpException('The mailbox template does not exist.');
            }

            if (ETag::preconditionFails($ifMatch, $this->version->forTemplate($row))) {
                throw new PreconditionFailedHttpException('The mailbox template was changed since it was read.');
            }

            if (self::isDefault((string) $row->template)) {
                throw new ProblemException(ProblemType::MailboxTemplateProtected, 'The Default mailbox template cannot be deleted.');
            }

            $values = $this->defaults->templateValues($row->attributes);
            DB::table('templates')->where('id', $id)->delete();
            $this->audit->record(AuditEventType::MailboxTemplateDeletion, true, $principal, self::target($id, (string) $row->template), null, null, ['values' => $values]);
        });
    }

    public static function isDefault(string $name): bool
    {
        return mb_strtolower($name) === 'default';
    }

    public static function lockName(string $name): string
    {
        return 'restv2_mailbox_template_'.sha1(mb_strtolower($name));
    }

    private function name(mixed $raw, FieldViolations $violations): string
    {
        $name = trim(is_string($raw) ? $raw : '');

        if ($name === '') {
            $violations->add('name', 'required');
        }

        return $name;
    }

    /**
     * The business rules of template values: whole MiB and the tag set.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $currentTags
     * @return array<string, mixed>
     */
    private function checked(array $values, array $currentTags, FieldViolations $violations): array
    {
        $this->validator->wholeMebibytes($values['quota'], $violations);
        $values['tags'] = TagSet::sort(TagSet::normalise((array) $values['tags'], $currentTags, $this->config->taggingLimit(), $violations));

        return $values;
    }

    private function assertUnique(string $name, ?int $except): void
    {
        $query = DB::table('templates')->where('type', 'mailbox')->whereRaw('LOWER(`template`) = ?', [mb_strtolower($name)]);

        if ($except !== null) {
            $query->where('id', '!=', $except);
        }

        if ($query->exists()) {
            throw new ProblemException(ProblemType::MailboxTemplateNameExists, "Another mailbox template is named {$name}.");
        }
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $values
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private static function changes(array $current, array $values): array
    {
        $changes = [];

        foreach ($current as $member => $old) {
            $new = $values[$member];

            if (in_array($member, ['protocolAccess', 'userPermissions'], true)) {
                foreach ($old as $key => $value) {
                    if ($value !== ($new[$key] ?? null)) {
                        $changes[$member.'.'.$key] = ['old' => $value, 'new' => $new[$key] ?? null];
                    }
                }
            } elseif ($old !== $new) {
                $changes[$member] = ['old' => $old, 'new' => $new];
            }
        }

        ksort($changes);

        return $changes;
    }

    /**
     * @return array{type: string, id: string, name: string}
     */
    private static function target(int $id, string $name): array
    {
        return ['type' => 'mailboxTemplate', 'id' => (string) $id, 'name' => $name];
    }
}
