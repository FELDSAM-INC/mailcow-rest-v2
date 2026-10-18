<?php

namespace App\Services\Mailboxes;

use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use Closure;
use Redis;

/**
 * Tagged-mail handling in mailcow's shared store (legacy mailbox('edit', 'delimiter_action'),
 * feature 003 research R9): `subject` sets `RCPT_WANTS_SUBJECT_TAG[<address>] = 1` and removes the
 * subfolder field, `subfolder` the reverse, `none` removes both, in one transaction. Shared with
 * feature 005, which changes it afterwards. Failures raise SharedStoreUnavailable.
 */
class TaggedMailHandlingStore
{
    public const SUBJECT = 'RCPT_WANTS_SUBJECT_TAG';

    public const SUBFOLDER = 'RCPT_WANTS_SUBFOLDER_TAG';

    public function __construct(private readonly SharedStore $store) {}

    /**
     * @throws SharedStoreUnavailable
     */
    public function set(string $address, string $mode): void
    {
        $this->store->run(function (Redis $redis) use ($address, $mode): void {
            $transaction = $redis->multi();

            match ($mode) {
                'subject' => $transaction->hSet(self::SUBJECT, $address, '1')->hDel(self::SUBFOLDER, $address),
                'subfolder' => $transaction->hSet(self::SUBFOLDER, $address, '1')->hDel(self::SUBJECT, $address),
                default => $transaction->hDel(self::SUBJECT, $address)->hDel(self::SUBFOLDER, $address),
            };

            if (! is_array($transaction->exec())) {
                throw new SharedStoreUnavailable('mailcow\'s shared store refused the transaction.');
            }
        });
    }

    /**
     * Removes both fields of the address.
     *
     * @return Closure(): void the undo action
     *
     * @throws SharedStoreUnavailable
     */
    public function forget(string $address): Closure
    {
        return $this->store->deleteFields([self::SUBJECT => [$address], self::SUBFOLDER => [$address]]);
    }
}
