<?php

namespace App\Services\Accounts;

/**
 * The second-factor status of an administrator or domain administrator (feature 007 FR-008,
 * contract `AccountSecondFactorStatus`), over the account's TOTP, WebAuthn and Yubico OTP entries
 * in `tfa`; deprecated U2F and HOTP entries never count, because feature 001 never accepts them.
 */
enum SecondFactorStatus: string
{
    case None = 'none';
    case Active = 'active';
    case Suspended = 'suspended';

    /** The `tfa.authmech` values that count. */
    public const COUNTED = ['totp', 'webauthn', 'yubi_otp'];

    /**
     * @param  iterable<array{0: string, 1: int|string|bool}>  $rows  `[authmech, active]` per entry
     */
    public static function fromTfaRows(iterable $rows): self
    {
        $counted = false;

        foreach ($rows as [$mechanism, $active]) {
            if (! in_array($mechanism, self::COUNTED, true)) {
                continue;
            }

            if ((int) $active === 1) {
                return self::Active;
            }

            $counted = true;
        }

        return $counted ? self::Suspended : self::None;
    }

    /**
     * The status from the aggregate of AccountQuery: whether counted entries exist, and whether any
     * of them is active.
     */
    public static function fromAggregate(?int $anyActive): self
    {
        return match ($anyActive) {
            null => self::None,
            1 => self::Active,
            default => self::Suspended,
        };
    }
}
