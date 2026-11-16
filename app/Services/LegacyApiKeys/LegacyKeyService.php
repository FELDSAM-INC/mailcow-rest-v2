<?php

namespace App\Services\LegacyApiKeys;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Auth\Role;
use App\Models\Mailcow\SchemaVersion;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/** Legacy /api/v1 credentials are global administrator keys, not owner-bound v2 credentials. */
final class LegacyKeyService
{
    public function authorize(Principal $actor, bool $write = false): void
    {
        if ($actor->role !== Role::Admin || ($write && $actor->apiKey !== null)) {
            throw new AccessDeniedHttpException('Managing legacy credentials requires an administrator browser session.');
        }
        if (! SchemaVersion::hasNovember2026Features()) {
            throw new ConflictHttpException('Multiple legacy API keys require mailcow 2026-11.');
        }
    }

    public function row(int $id, bool $lock = false): object
    {
        $query = DB::table('api')->where('id', $id);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new NotFoundHttpException;
    }

    /** List and detail responses never disclose stored credentials, including service keys.
     * @return array<string, mixed>
     */
    public function present(object $key): array
    {
        return ['id' => (int) $key->id, 'description' => $key->description, 'prefix' => substr($key->api_key, 0, 6),
            'accessLevel' => $key->access === 'ro' ? 'readOnly' : 'readWrite',
            'allowedNetworks' => preg_split('/[\s,;]+/', $key->allow_from, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            'skipIpCheck' => (bool) $key->skip_ip_check, 'active' => (bool) $key->active, 'service' => (bool) $key->service,
            'etag' => ETag::of(['legacyApiKey', (array) $key])];
    }

    /** @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function save(?int $id, array $patch, Principal $actor, ?string $ifMatch, bool $regenerate = false): array
    {
        $this->authorize($actor, true);

        return DB::transaction(function () use ($id, $patch, $actor, $ifMatch, $regenerate): array {
            $current = $id === null ? null : $this->editable($id, $ifMatch);
            $values = $current ? (array) $current : ['description' => '', 'allow_from' => '', 'skip_ip_check' => 0, 'active' => 1, 'access' => 'ro', 'service' => 0];
            foreach (['description' => 'description', 'active' => 'active', 'skipIpCheck' => 'skip_ip_check', 'accessLevel' => 'access', 'allowedNetworks' => 'allow_from'] as $field => $column) {
                if (! array_key_exists($field, $patch)) {
                    continue;
                }
                $values[$column] = match ($field) {
                    'accessLevel' => $patch[$field] === 'readOnly' ? 'ro' : 'rw',
                    'allowedNetworks' => implode(', ', array_values(array_unique($patch[$field]))),
                    'active', 'skipIpCheck' => (int) $patch[$field],
                    default => $patch[$field],
                };
            }
            $errors = new FieldViolations;
            if (! $values['skip_ip_check'] && trim($values['allow_from']) === '') {
                $errors->add('allowedNetworks', 'tooFew', ['min' => 1]);
            }
            if (strlen($values['allow_from']) > 512) {
                $errors->add('allowedNetworks', 'tooLong', ['max' => 512]);
            }
            $errors->throwIfAny();
            $secret = null;
            if ($id === null || $regenerate) {
                $secret = implode('-', array_map(fn () => strtoupper(bin2hex(random_bytes(3))), range(1, 5)));
                $values['api_key'] = $secret;
            }
            if ($id === null) {
                $id = (int) DB::table('api')->insertGetId($values);
                $event = AuditEventType::LegacyApiKeyCreation;
            } else {
                unset($values['id'], $values['created'], $values['modified']);
                DB::table('api')->where('id', $id)->update($values);
                $event = $regenerate ? AuditEventType::LegacyApiKeyRegeneration : AuditEventType::LegacyApiKeyUpdate;
            }
            app(AuditLogger::class)->record($event, true, $actor, ['type' => 'legacyApiKey', 'id' => (string) $id], null, null, ['fields' => array_keys($patch)]);

            return $this->present($this->row($id)) + ($secret === null ? [] : ['secret' => $secret]);
        });
    }

    public function delete(int $id, Principal $actor, ?string $ifMatch): void
    {
        $this->authorize($actor, true);
        DB::transaction(function () use ($id, $actor, $ifMatch): void {
            $this->editable($id, $ifMatch);
            DB::table('api')->where('id', $id)->delete();
            app(AuditLogger::class)->record(AuditEventType::LegacyApiKeyDeletion, true, $actor, ['type' => 'legacyApiKey', 'id' => (string) $id], null, null, []);
        });
    }

    private function editable(int $id, ?string $ifMatch): object
    {
        $key = $this->row($id, true);
        if ($key->service) {
            throw new ConflictHttpException('This service key is managed in mailcow.conf.');
        }
        if (ETag::preconditionFails($ifMatch, $this->present($key)['etag'])) {
            throw new PreconditionFailedHttpException;
        }

        return $key;
    }
}
