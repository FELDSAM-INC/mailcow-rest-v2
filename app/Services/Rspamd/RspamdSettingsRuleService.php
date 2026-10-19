<?php

namespace App\Services\Rspamd;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Models\Mailcow\RspamdSettingsRule;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ETag;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The rules of mailcow's Rspamd settings map (feature 013 FR-050 to FR-055, research R11), in the
 * `settingsmap` table that legacy's rsettings() writes and php-fpm's `settings.php` serves to
 * Rspamd at its next refresh, without a restart. Content is stored trimmed (legacy trims on edit
 * only; trimming at creation changes nothing Rspamd sees). A rule created without an active state
 * is inactive, as in legacy. The version tag covers the ID, description, content and active state.
 * Each change and its audit record (content as sizes only) share one database transaction.
 */
class RspamdSettingsRuleService
{
    /** Contract sort => column. */
    public const SORTS = ['id' => 'id', 'description' => 'desc', 'active' => 'active', 'createdAt' => 'created', 'modifiedAt' => 'modified'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{data: list<mixed>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(ListQuery $query, ?bool $active): array
    {
        $builder = RspamdSettingsRule::query();

        if ($active !== null) {
            $builder->where('active', $active ? 1 : 0);
        }

        $query->applySearch($builder, ['desc', 'content']);

        return $query->paginate($builder, fn (RspamdSettingsRule $rule): array => self::present($rule), 'id');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $rule = RspamdSettingsRule::query()->find($id);

        return $rule instanceof RspamdSettingsRule ? self::present($rule) : null;
    }

    /**
     * @param  array{description: string, content: string, active?: bool}  $values  validated
     * @return array<string, mixed>
     */
    public function create(array $values, Principal $actor): array
    {
        $id = DB::transaction(function () use ($values, $actor): int {
            $content = trim($values['content']);
            $active = (bool) ($values['active'] ?? false);
            $id = (int) DB::table('settingsmap')->insertGetId(['desc' => $values['description'], 'content' => $content, 'active' => $active ? 1 : 0]);

            $this->audit->record(AuditEventType::RspamdSettingsRuleCreation, true, $actor, ['type' => 'rspamdSettingsRule', 'id' => (string) $id, 'name' => $values['description']], null, null, ['changes' => [
                'description' => ['old' => null, 'new' => $values['description']],
                'content' => ['changed' => true, 'oldBytes' => 0, 'newBytes' => strlen($content)],
                'active' => ['old' => null, 'new' => $active],
            ]]);

            return $id;
        });

        return (array) $this->find($id);
    }

    /**
     * Applies a validated merge patch; null with `validateOnly` (after the existence and version
     * checks).
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $patch, ?string $ifMatch, Principal $actor, bool $validateOnly = false): ?array
    {
        $updated = DB::transaction(function () use ($id, $patch, $ifMatch, $actor, $validateOnly): bool {
            $rule = $this->locked($id, $ifMatch);

            if ($validateOnly) {
                return false;
            }

            $changes = [];
            $values = [];

            if (array_key_exists('description', $patch) && $patch['description'] !== $rule->desc) {
                $values['desc'] = (string) $patch['description'];
                $changes['description'] = ['old' => $rule->desc, 'new' => $values['desc']];
            }

            if (array_key_exists('content', $patch) && trim((string) $patch['content']) !== $rule->content) {
                $values['content'] = trim((string) $patch['content']);
                $changes['content'] = ['changed' => true, 'oldBytes' => strlen($rule->content), 'newBytes' => strlen($values['content'])];
            }

            if (array_key_exists('active', $patch) && $patch['active'] !== $rule->active) {
                $values['active'] = $patch['active'] ? 1 : 0;
                $changes['active'] = ['old' => $rule->active, 'new' => (bool) $patch['active']];
            }

            if ($values === []) {
                return true;
            }

            DB::table('settingsmap')->where('id', $id)->update($values);
            $this->audit->record(AuditEventType::RspamdSettingsRuleUpdate, true, $actor, ['type' => 'rspamdSettingsRule', 'id' => (string) $id, 'name' => $values['desc'] ?? $rule->desc], null, null, ['changes' => $changes]);

            return true;
        });

        return $updated ? $this->find($id) : null;
    }

    public function delete(int $id, ?string $ifMatch, Principal $actor): void
    {
        DB::transaction(function () use ($id, $ifMatch, $actor): void {
            $rule = $this->locked($id, $ifMatch);
            DB::table('settingsmap')->where('id', $id)->delete();

            $this->audit->record(AuditEventType::RspamdSettingsRuleDeletion, true, $actor, ['type' => 'rspamdSettingsRule', 'id' => (string) $id, 'name' => $rule->desc], null, null, ['changes' => [
                'description' => ['old' => $rule->desc, 'new' => null],
                'content' => ['changed' => true, 'oldBytes' => strlen($rule->content), 'newBytes' => 0],
                'active' => ['old' => $rule->active, 'new' => null],
            ]]);
        });
    }

    /**
     * The rule, locked for the transaction, after the existence and version checks.
     */
    private function locked(int $id, ?string $ifMatch): RspamdSettingsRule
    {
        $rule = RspamdSettingsRule::query()->lockForUpdate()->find($id);

        if (! $rule instanceof RspamdSettingsRule) {
            throw new NotFoundHttpException('The settings rule does not exist.');
        }

        if (ETag::preconditionFails($ifMatch, self::etag($rule))) {
            throw new PreconditionFailedHttpException('The settings rule has changed since it was read.');
        }

        return $rule;
    }

    private static function etag(RspamdSettingsRule $rule): string
    {
        return ETag::of(['rspamdSettingsRule', $rule->id, $rule->desc, hash('sha256', $rule->content), $rule->active]);
    }

    /**
     * Contract `RspamdSettingsRule`.
     *
     * @return array<string, mixed>
     */
    public static function present(RspamdSettingsRule $rule): array
    {
        return [
            'id' => $rule->id,
            'description' => $rule->desc,
            'content' => $rule->content,
            'active' => $rule->active,
            'createdAt' => ($rule->created ?? now('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'modifiedAt' => $rule->modified?->format('Y-m-d\TH:i:s\Z'),
            'etag' => self::etag($rule),
        ];
    }
}
