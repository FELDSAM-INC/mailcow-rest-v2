<?php

namespace App\Models\Mailcow;

/**
 * mailcow `tfa`: second factors (TOTP, WebAuthn, Yubico OTP; legacy U2F and HOTP rows are never
 * offered). Read by the second-factor service; the only write is the re-activation of FR-026.
 *
 * @property int $id
 * @property string $key_id
 * @property string $username
 * @property string|null $authmech
 * @property string|null $secret
 * @property string|null $keyHandle
 * @property string|null $publicKey
 * @property bool $active
 */
class TfaEntry extends MailcowModel
{
    protected $table = 'tfa';

    protected $primaryKey = 'id';

    protected $hidden = ['secret', 'publicKey', 'keyHandle', 'certificate'];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'active' => 'boolean',
            'counter' => 'integer',
        ];
    }
}
