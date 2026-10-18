<?php

namespace App\Services\Passwords;

/**
 * A password as stored in `mailbox.password` (`{SCHEME}hash`), and whether the caller gave it
 * pre-hashed. Never logged, returned or audited.
 */
final readonly class HashedPassword
{
    public function __construct(
        public string $value,
        public bool $preHashed,
    ) {}
}
