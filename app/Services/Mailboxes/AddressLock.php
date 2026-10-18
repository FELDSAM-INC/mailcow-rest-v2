<?php

namespace App\Services\Mailboxes;

use App\Support\Database\NamedLock;
use Closure;

/**
 * The address lock shared by every feature that hands out addresses (docs/conventions.md "Shared
 * locks and helpers"): `GET_LOCK('restv2_address_<sha1 of the lower-cased address>')`, taken before
 * checking that an address is free and held until the insert is committed. Features 003 (mailboxes),
 * 004 (aliases, alias domains) and 005 (temporary aliases) use exactly this name.
 */
class AddressLock
{
    public function __construct(private readonly NamedLock $locks) {}

    public static function name(string $address): string
    {
        return 'restv2_address_'.sha1(mb_strtolower(trim($address)));
    }

    /**
     * @template T
     *
     * @param  list<string>  $addresses
     * @param  Closure(): T  $code
     * @return T
     */
    public function run(array $addresses, Closure $code, int $timeout = NamedLock::DEFAULT_TIMEOUT): mixed
    {
        return $this->locks->run(array_map(self::name(...), $addresses), $code, $timeout);
    }
}
