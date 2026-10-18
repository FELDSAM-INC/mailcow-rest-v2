<?php

namespace App\Clients\Ispconfig;

use RuntimeException;

/**
 * A failed ISPConfig remote API exchange with its reason code (contract
 * `DkimDnsPublicationReasonCode`): `unreachable`, `timeout`, `authenticationFailed`,
 * `remoteFault` or `invalidResponse`. The detail is at most 500 characters and never contains the
 * remote password.
 */
class IspconfigException extends RuntimeException
{
    public readonly ?string $detail;

    public function __construct(public readonly string $reason, ?string $detail = null)
    {
        $this->detail = $detail === null ? null : mb_substr($detail, 0, 500);

        parent::__construct("ISPConfig {$reason}".($this->detail === null ? '' : ': '.$this->detail));
    }
}
