<?php

namespace App\Services\Accounts;

use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * The generation of an account (feature 007 research R11): `restv2:account-generation:<kind>:<username>`
 * on the `restv2` Redis connection, absent = 0, without expiry. This API increments it when it
 * deletes or renames an administrator, a domain administrator (`admin`) or a mailbox (`mailbox`).
 * It is read only to validate impersonation targets, so that an impersonation ends even when an
 * account with the same name is created again within the same second. A lost counter differs
 * from the stored one, which ends the impersonation: it fails safe.
 */
class AccountGeneration
{
    public function __construct(private readonly RedisFactory $redis) {}

    public function current(string $kind, string $username): int
    {
        return (int) $this->redis->connection('restv2')->command('get', [self::key($kind, $username)]);
    }

    public function bump(string $kind, string $username): int
    {
        return (int) $this->redis->connection('restv2')->command('incr', [self::key($kind, $username)]);
    }

    private static function key(string $kind, string $username): string
    {
        return 'account-generation:'.$kind.':'.mb_strtolower($username);
    }
}
