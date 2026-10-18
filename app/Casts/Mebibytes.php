<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * mailcow stores domain quotas in MiB; the contract uses bytes (constitution II, feature 002 D2).
 *
 * @implements CastsAttributes<int, int>
 */
class Mebibytes implements CastsAttributes
{
    public const BYTES = 1048576;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): int
    {
        return (int) $value * self::BYTES;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return intdiv((int) $value, self::BYTES);
    }
}
