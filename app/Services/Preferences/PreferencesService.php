<?php

namespace App\Services\Preferences;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Models\Preference;
use App\Services\Audit\AuditLogger;
use App\Services\Config\MailcowConfig;
use App\Support\Http\ETag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The signed-in account's language and theme (FR-030, R21). Accounts are keyed by kind and
 * username, so that an administrator and a mailbox with the same name stay separate.
 *
 * The effective language is the stored one while it is enabled, otherwise mailcow's default
 * language; the theme defaults to `system`. The version tag covers the stored values only, so a
 * change of the server's default language does not change it.
 */
class PreferencesService
{
    /** The preferences a merge patch may change. */
    public const FIELDS = ['language', 'theme'];

    public function __construct(
        private readonly MailcowConfig $config,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{language: string, theme: string, etag: string}
     */
    public function effective(Principal $principal): array
    {
        return $this->present($this->stored($principal));
    }

    /**
     * Applies a validated merge patch, checking `If-Match`. Only a change of a stored value is
     * written and audited.
     *
     * @param  array<string, string>  $changes
     * @return array{language: string, theme: string, etag: string}
     */
    public function update(Request $request, Principal $principal, array $changes): array
    {
        return DB::transaction(function () use ($request, $principal, $changes): array {
            $row = $this->stored($principal, lock: true);

            if ($row === null) {
                // An empty row reads like no row (same values and tag); creating it first lets
                // concurrent first updates serialise on its lock instead of on the unique key.
                $now = now('UTC');
                Preference::query()->insertOrIgnore([
                    'account_kind' => $principal->accountKind->value,
                    'username' => $principal->username,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $row = $this->stored($principal, lock: true);
            }

            if (ETag::ifMatchFails($request, $this->etag($row))) {
                throw new PreconditionFailedHttpException('The preferences were changed since they were read.');
            }

            $changed = array_values(array_filter(
                self::FIELDS,
                fn (string $field): bool => array_key_exists($field, $changes) && $changes[$field] !== $row?->getAttribute($field),
            ));

            if ($changed === []) {
                return $this->present($row);
            }

            assert($row instanceof Preference);
            $row->fill(array_intersect_key($changes, array_flip($changed)))->save();

            $this->audit->record(
                AuditEventType::PreferencesUpdate,
                true,
                $principal,
                ['type' => 'preferences', 'id' => null, 'name' => null],
                $principal->authMethod->value,
                null,
                ['changedFields' => $changed],
            );

            return $this->present($row);
        });
    }

    private function stored(Principal $principal, bool $lock = false): ?Preference
    {
        $query = Preference::query()
            ->where('account_kind', $principal->accountKind->value)
            ->where('username', $principal->username);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * @return array{language: string, theme: string, etag: string}
     */
    private function present(?Preference $row): array
    {
        $language = $row?->language;

        return [
            'language' => $language !== null && array_key_exists($language, $this->config->availableLanguages())
                ? $language
                : $this->config->defaultLanguage(),
            'theme' => $row->theme ?? 'system',
            'etag' => $this->etag($row),
        ];
    }

    private function etag(?Preference $row): string
    {
        return ETag::of([$row?->language, $row?->theme]);
    }
}
