<?php

namespace App\Services\SecondFactors;

use App\Auth\Principal;
use App\Support\Http\ListQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in account's second-factor entries (feature 005 FR-027, research R10): every `tfa` row
 * of the username, all types and both states, as the contract's `OwnSecondFactor`. No column with
 * secret material (`secret`, `keyHandle`, `publicKey`, `certificate`, `counter`) leaves the query;
 * a Yubico entry's public identifier is `RIGHT(secret, 12)` (legacy get_tfa()).
 */
class SecondFactorQueryService
{
    /** Contract `OwnSecondFactorType` by `tfa.authmech`. */
    public const TYPES = ['totp' => 'totp', 'webauthn' => 'webauthn', 'yubi_otp' => 'yubiOtp', 'u2f' => 'u2f', 'hotp' => 'hotp'];

    /** Types this API accepts at sign-in (001: U2F and HOTP are deprecated). */
    private const USABLE = ['totp', 'webauthn', 'yubi_otp'];

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(Principal $principal, ListQuery $query, ?int $currentId): array
    {
        $builder = $this->query($principal->username);
        $query->applySearch($builder, ['key_id']);
        $total = (clone $builder)->count();

        $sort = match ($query->sort) {
            'type' => DB::raw('CAST(`authmech` AS CHAR)'),
            'name' => 'key_id',
            default => 'id',
        };
        $builder->orderBy($sort, $query->order);

        if ($query->sort !== 'id') {
            $builder->orderBy('id', $query->order);
        }

        $rows = $builder->offset($query->offset)->limit($query->limit)->get();

        return [
            'data' => array_values($rows->map(fn (object $row): array => $this->present($row, $currentId))->all()),
            'meta' => $query->meta($total),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(Principal $principal, int $id, ?int $currentId): ?array
    {
        $row = $this->query($principal->username)->where('id', $id)->first();

        return $row === null ? null : $this->present($row, $currentId);
    }

    private function query(string $username): Builder
    {
        return DB::table('tfa')
            ->where('username', $username)
            ->select(['id', 'key_id', 'authmech', 'active'])
            ->selectRaw("CASE WHEN `authmech` = 'yubi_otp' THEN RIGHT(`secret`, 12) END AS `yubico_public_id`");
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $row, ?int $currentId): array
    {
        $authmech = (string) $row->authmech;
        $publicId = is_string($row->yubico_public_id) ? strtolower($row->yubico_public_id) : null;

        return [
            'id' => (int) $row->id,
            'type' => self::TYPES[$authmech] ?? $authmech,
            'name' => (string) $row->key_id,
            'active' => (int) $row->active === 1,
            'usable' => in_array($authmech, self::USABLE, true),
            'yubicoPublicId' => $publicId !== null && preg_match('/^[a-z]{12}$/', $publicId) === 1 ? $publicId : null,
            'usedForCurrentSignIn' => $currentId !== null && (int) $row->id === $currentId,
        ];
    }
}
